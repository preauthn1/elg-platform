<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Common;
use App\Models\EventTotalBetRecord;
use App\Models\SimpleNamedLock;
use App\Vendors\Models\AbstractBetRecord;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class EventTotalBetRecordService
{
    private $vendorId;

    private $memberId;

    private $errorCode = 0;

    public function __construct(int $vendorId, int $memberId)
    {
        $this->vendorId = $vendorId;
        $this->memberId = $memberId;
    }

    public function setErrorCode(int $errorCode): static
    {
        $this->errorCode = $errorCode;

        return $this;
    }

    public function create(AbstractBetRecord $betRecord, array $appendData = [])
    {

        $game = DB::table('games')->where('vendor_id', $this->vendorId)
            ->where('game_type', $betRecord->game_code)
            ->first();

        $values = [
            'summary_time' => date('Y-m-d H:00:00', strtotime($betRecord->updated_at->toDateTimeString())),
            'member_id' => $this->memberId,
            'vendor_id' => $this->vendorId,
            'bet_record_id' => $betRecord->id,
            'game_id' => is_object($game) ? $game->id : 0,
            'reference_no' => $betRecord->getBetId() ?? '',
            'bet' => $betRecord->getBet(),
            'valid_bet' => $betRecord->getValidBet(),
            'win_lose' => $betRecord->getWinLose(),
            'valid_bet_rate' => 1,
            'bet_time' => $betRecord->bet_time,
            'payout_time' => $betRecord->payout_time ?? $betRecord->bet_time,
            'record_time' => $betRecord->bet_time,
            'admin' => 0,
        ];

        $values = array_merge($values, $appendData);

        $totalBetRecord = EventTotalBetRecord::updateOrCreate([
            'vendor_id' => $this->vendorId,
            'bet_record_id' => $betRecord->id,
        ], $values);

        if ($totalBetRecord->wasRecentlyCreated) {
            Log::info(sprintf('TotalBetRecord %s created, BetId %s', $totalBetRecord->id, $betRecord->getBetId() ?? ''));
        } else {
            Log::info(sprintf('TotalBetRecord %s updated, BetId %s', $totalBetRecord->id, $betRecord->getBetId() ?? ''));
        }

        if (!is_object($totalBetRecord) || empty($totalBetRecord->id)) {
            throw new Exception('Add total bet record failed', $this->errorCode);
        }
    }

    /**
     * 會新增或更新 total_bet_record 的資料，以下幾種情況可以使用：
     * 1. 投注與派彩分開, 如 TfgamingSW, SpadegamingSW
     * 2. 會有取消後再派彩, 需更新, 如 Wcasino, TfgamingSW
     * 3. 會有連續派彩, 且會因此更新結算時間, 如 Rcb988
     */
    public function createOrUpdate(AbstractBetRecord $betRecord, AbstractBetRecord $settleBetRecord, string $transactionLogId, string $lockAction)
    {
        $lock = new SimpleNamedLock("{$lockAction}_total_bet_record_" . $betRecord->getBetId());
        try {

            $totalBetRecord = EventTotalBetRecord::where('vendor_id', $this->vendorId)
                ->where('bet_record_id', $betRecord->id)
                ->first();

            if ($totalBetRecord) {
                $totalBetRecord->win_lose = Common::numAdd($totalBetRecord->win_lose, $settleBetRecord->getWinLose());
                $totalBetRecord->payout_time = $settleBetRecord->payout_time;
                $totalBetRecord->save();
            } else {
                // 如果 betRecord 和 settleBetRecord 是同一筆，則 win_lose 為 betRecord 的 win_lose
                // 否則為 betRecord 和 settleBetRecord 的 win_lose 相加
                $total_win_lose = ($betRecord->id === $settleBetRecord->id) ?
                    $betRecord->getWinLose() :
                    Common::numAdd($betRecord->getWinLose(), $settleBetRecord->getWinLose());

                $newBetRecord = clone $betRecord;
                $newBetRecord->updated_at = $settleBetRecord->updated_at;
                $newBetRecord->win_lose = $total_win_lose;
                $newBetRecord->payout_time = $settleBetRecord->payout_time;
                $this->create($newBetRecord);
            }

            return $totalBetRecord;
        } catch (Exception $e) {
            throw new Exception('Total bet record creation failed, msg: ' . $e->getMessage(), $this->errorCode);
        } finally {
            $lock->unLock();
        }
    }
}
