<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Common;
use App\Jobs\UpdateMemberActivity;
use App\Models\Game;
use App\Models\SimpleNamedLock;
use App\Models\TotalBetRecord;
use App\Models\TotalBetRecordTransactionLog;
use App\Models\TotalBetRecordWalletTransactionLog;
use App\Vendors\Models\AbstractBetRecord;
use Exception;
use Illuminate\Support\Facades\Log;

/**
 * 統一注單彙整層（total_bet_records）的寫入入口——跨所有遊戲商用同一組欄位查注單。
 *
 * 一筆 BR 對應 TBR 至多一列，**冪等鍵固定為 (vendor_id, bet_record_id)**，
 * 禁止改用 reference_no 當唯一鍵、禁止另建第二張跨商注單彙整表。
 *
 * ⚠️ TBR 與帳變流水的關聯**只能**寫在 total_bet_record_transaction_logs 或
 * total_bet_record_wallet_transaction_logs，**不得在 TBR 上直接掛流水欄位**。
 * TBR 是 summary_bet_records 的來源，禁止反向以 SBR 回補 TBR。
 *
 * 🔴 同一張表有兩類寫入者：PHP 服務（本 Service 與 BetRecordFetchService）與尚未搬進 PHP
 * 的舊系統 stored procedure（database/procedures/SP_transfer_bet_record_{tname}.sql）——
 * **改動欄位語意時兩邊都要動**；哪個 vendor 走哪條路徑，看 docs/reconcile/vendors/
 * 對帳卡的「BR→TBR 機制」欄。
 */
class TotalBetRecordService
{
    private int $errorCode = 0;

    private LargeAmountNotifyService $largeAmountNotifyService;

    public function __construct(
        private int $vendorId,
        private int $memberId,
        private bool $isMultipleWallet = false,
    ) {
        $this->largeAmountNotifyService = app(LargeAmountNotifyService::class);
    }

    public function setErrorCode(int $errorCode): static
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    public function create(AbstractBetRecord $betRecord, string|int $transactionLogId, array $appendData = []): TotalBetRecord
    {
        $game = Game::where('vendor_id', $this->vendorId)
            ->where('game_type', $betRecord->game_code)
            ->first();

        $values = [
            'summary_time' => $betRecord->updated_at->copy()->startOfHour(),
            'member_id' => $this->memberId,
            'vendor_id' => $this->vendorId,
            'bet_record_id' => $betRecord->id,
            'game_id' => $game?->id ?? 0,
            'reference_no' => $betRecord->getBetId() ?? '',
            'bet' => $betRecord->getBet(),
            'valid_bet' => $betRecord->getValidBet(),
            'win_lose' => $betRecord->getWinLose(),
            'rolling_chip' => $betRecord->getRollingChip(),
            'jackpot' => $betRecord->getJackpot(),
            'bonus' => $betRecord->getBonus(),
            'valid_bet_rate' => 1,
            'commission' => 0,
            'bet_time' => $betRecord->bet_time,
            'admin' => 0,
        ];

        if ($betRecord->payout_time ?? null) {
            $values['payout_time'] = $betRecord->payout_time;
        }

        $values = array_merge($values, $appendData);

        $keys = [
            'vendor_id' => $this->vendorId,
            'bet_record_id' => $betRecord->id,
        ];

        // 注單已存在（結算／取消／改派彩）時，它的 TBR 是下注那筆交易寫的，可能在本交易等會員鎖期間才 commit；
        // updateOrCreate 內部的存在判斷是一般讀，REPEATABLE READ 舊快照看不到那列就會改走 INSERT，
        // 撞 (vendor_id, bet_record_id) 唯一鍵讓整筆交易回滾（2026-09-05 TPower 事故的第二段，target/op_tickets#3075）。
        // 改以鎖定讀判斷存在與否（讀最新 commit）。新建的注單維持 updateOrCreate：其 TBR 通常不存在，
        // 對不存在的唯一鍵做 FOR UPDATE 會拿 gap lock，並發寫入同一 gap 會互相 deadlock。
        $totalBetRecord = $betRecord->wasRecentlyCreated
            ? null
            : TotalBetRecord::query()->where($keys)->lockForUpdate()->first();

        if ($totalBetRecord) {
            $totalBetRecord->fill($values)->save();
        } else {
            $totalBetRecord = TotalBetRecord::updateOrCreate($keys, $values);
        }

        Log::info('TotalBetRecord ' . ($totalBetRecord->wasRecentlyCreated ? 'created' : 'updated'), [
            'id' => $totalBetRecord->id,
            'bet_id' => $betRecord->getBetId() ?? '',
        ]);

        $this->createLogRelation($totalBetRecord->id, $transactionLogId);

        // 更新會員最後投注時間（member_statistics.last_bet_at），非同步、afterCommit 防交易 rollback
        if ($betRecord->bet_time) {
            UpdateMemberActivity::dispatchSafely(
                $this->memberId,
                UpdateMemberActivity::TYPE_BET,
                (string) $betRecord->bet_time,
            );
        }

        // 大額投注告警
        $this->largeAmountNotifyService->notifyLargeBetRecord(
            memberId: $this->memberId,
            vendorId: $this->vendorId,
            record: $totalBetRecord,
            game: $game,
        );

        // 大額派彩告警
        $this->largeAmountNotifyService->notifyLargePayout(
            memberId: $this->memberId,
            vendorId: $this->vendorId,
            record: $totalBetRecord,
            game: $game,
        );

        return $totalBetRecord;
    }

    /**
     * 會新增或更新 total_bet_record 的資料，以下幾種情況可以使用：
     * 1. 投注與派彩分開, 如 TfgamingSW, SpadegamingSW
     * 2. 會有取消後再派彩, 需更新, 如 Wcasino, TfgamingSW
     * 3. 會有連續派彩, 且會因此更新結算時間, 如 Rcb988
     */
    public function createOrUpdate(
        AbstractBetRecord $betRecord,
        AbstractBetRecord $settleBetRecord,
        string|int $transactionLogId,
        string $lockAction,
    ): TotalBetRecord {
        $lock = new SimpleNamedLock("{$lockAction}_total_bet_record_" . $betRecord->getBetId());
        try {
            $totalBetRecord = TotalBetRecord::where('vendor_id', $this->vendorId)
                ->where('bet_record_id', $betRecord->id)
                ->first();

            if ($totalBetRecord) {
                $totalBetRecord->win_lose = Common::numAdd($totalBetRecord->win_lose, $settleBetRecord->getWinLose());
                $totalBetRecord->bonus = Common::numAdd($totalBetRecord->bonus, $settleBetRecord->getBonus());
                $totalBetRecord->jackpot = Common::numAdd($totalBetRecord->jackpot, $settleBetRecord->getJackpot());
                $totalBetRecord->payout_time = $settleBetRecord->payout_time;
                $totalBetRecord->save();

                $this->largeAmountNotifyService->notifyLargePayout(
                    memberId: $this->memberId,
                    vendorId: $this->vendorId,
                    record: $totalBetRecord,
                );

                return $totalBetRecord;
            }

            // 同一筆記錄不重複加總（部分 callers 會傳入相同的 betRecord 兩次），避免雙倍計算
            $total_win_lose = ($betRecord->id === $settleBetRecord->id)
                ? $betRecord->getWinLose()
                : Common::numAdd($betRecord->getWinLose(), $settleBetRecord->getWinLose());

            $newBetRecord = clone $betRecord;
            $newBetRecord->updated_at = $settleBetRecord->updated_at;
            $newBetRecord->win_lose = $total_win_lose;
            $newBetRecord->payout_time = $settleBetRecord->payout_time;

            return $this->create($newBetRecord, $transactionLogId);
        } catch (Exception $e) {
            throw new Exception('Total bet record creation failed, msg: ' . $e->getMessage(), $this->errorCode, $e);
        } finally {
            $lock->unLock();
        }
    }

    private function createLogRelation(int $totalBetRecordId, string|int $transactionLogId): void
    {
        if (empty($transactionLogId) || empty($totalBetRecordId)) {
            return;
        }

        if ($this->isMultipleWallet) {
            TotalBetRecordWalletTransactionLog::updateOrCreate([
                'transaction_log_id' => $transactionLogId,
                'total_bet_record_id' => $totalBetRecordId,
            ]);

            return;
        }

        TotalBetRecordTransactionLog::updateOrCreate([
            'transaction_log_id' => $transactionLogId,
            'total_bet_record_id' => $totalBetRecordId,
        ]);
    }
}
