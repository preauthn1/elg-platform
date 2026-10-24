<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use App\Models\TotalBetRecord;
use App\Services\TotalBetRecordService;
use App\Services\TransactionLogCreateService;
use App\Vendors\Models\PpswBetRecord;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 批次比對 ppsw_game_rounds 與 ppsw_bet_records 的資料差異（日期範圍）
 *
 * 透過調用 ppsw:compare-records 命令來處理每一天的數據比對
 * 確保邏輯與 CompareGameRoundsWithBetRecords 保持一致
 *
 * 使用方式：php artisan ppsw:compare-records-range {startDate} {endDate}
 * 範例：php artisan ppsw:end-round 2026-01-13 2026-01-17
 */
class EndRound extends Command
{
    protected $signature = 'ppsw:end-round {startDate} {endDate}';

    protected $description = '結算沒有收到 EndRound 的投注紀錄，並建立 TotalBetRecord';

    private int $vendorId = 83; // PragmaticPlay SW

    private bool $isMultipleWallet = true;

    private const int STATUS_ENDED = 4;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startDate = $this->argument('startDate');
        $endDate = $this->argument('endDate');

        // 驗證日期格式
        try {
            $start = Carbon::createFromFormat('Y-m-d', $startDate)->startOfDay();
            $end = Carbon::createFromFormat('Y-m-d', $endDate)->startOfDay();
        } catch (Exception $e) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d 格式（例如：2025-11-18）');

            return 1;
        }

        // 驗證日期範圍
        if ($start->gt($end)) {
            $this->error('❌ 開始日期不能大於結束日期');

            return 1;
        }

        // 計算天數
        $days = $start->diffInDays($end) + 1;

        $this->info('========================================');
        $this->info('結算 PPSW 投注記錄');
        $this->info('========================================');
        $this->info('開始日期: ' . $start->format('Y-m-d'));
        $this->info('結束日期: ' . $end->format('Y-m-d'));
        $this->info('總天數: ' . $days . ' 天');
        $this->info('========================================');
        $this->newLine();

        // 確認是否繼續
        if (!$this->confirm('是否繼續執行？', true)) {
            $this->warn('❌ 使用者取消執行');

            return 0;
        }

        $this->newLine();

        // 逐日處理
        for ($date = $start; $date->lte($end); $date->addDay()) {
            $this->getDailyBetRecords($date->format('Y-m-d'));
            $this->newLine();
        }

        return 0;
    }

    /**
     * 查詢指定日期未結算的投注紀錄（分批處理，避免記憶體暴增）
     */
    private function getDailyBetRecords(string $date): void
    {
        $startDate = Carbon::createFromFormat('Y-m-d', $date)->startOfDay();
        $endDate = Carbon::createFromFormat('Y-m-d', $date)->addDay()->startOfDay();

        $query = PpswBetRecord::query()
            ->with('member')
            ->where('bet_time', '>=', $startDate)
            ->where('bet_time', '<', $endDate)
            ->where('status', 1)
            ->whereNull('payout_time')
            // ->where('status', self::STATUS_ENDED)
            ->orderBy('id');

        $count = (clone $query)->count();
        $this->info(sprintf('處理日期: %s，共 %d 筆投注紀錄', $date, $count));

        // 分批處理：避免一次載入一天的所有資料
        $chunkSize = 500;
        $query->chunkById($chunkSize, function ($records) {
            // 1) 批次查：此 chunk 內哪些 bet_record_id 已經有 TotalBetRecord
            $betRecordIds = $records->pluck('id')->all();
            $existingBetRecordIdSet = TotalBetRecord::query()
                ->where('vendor_id', $this->vendorId)
                ->whereIn('bet_record_id', $betRecordIds)
                ->pluck('bet_record_id')
                ->flip(); // key 為 bet_record_id，方便 O(1) 判斷

            // 2) 批次查：此 chunk 內每個 member 最後一筆 transaction log id（多錢包：wallet_transaction_logs）
            //    先用 group max(id) 方式，避免每個 member 都跑一條 orderByDesc()
            $memberIds = $records->pluck('member_id')->unique()->values()->all();
            $lastTransactionLogIdByMember = [];
            if (!empty($memberIds)) {
                // PPSW 在多錢包情境通常使用 CODE_GAME(2)，此處先沿用既有輸出中的 wallet_type_id=2
                $walletTypeId = 2;
                $lastTransactionLogIdByMember = DB::table('wallet_transaction_logs')
                    ->selectRaw('member_id, MAX(id) as last_id')
                    ->whereIn('member_id', $memberIds)
                    ->where('wallet_type_id', $walletTypeId)
                    ->groupBy('member_id')
                    ->pluck('last_id', 'member_id')
                    ->all();
            }

            /** @var PpswBetRecord $record */
            foreach ($records as $record) {
                $this->endRound($record, $existingBetRecordIdSet, $lastTransactionLogIdByMember);
            }

            unset($records);
            gc_collect_cycles();

            return true;
        });
    }

    private function endRound(
        PpswBetRecord $betRecord,
        $existingBetRecordIdSet = null,
        array $lastTransactionLogIdByMember = []
    ): void {
        // 若 chunk 有傳入已存在集合，優先用集合判斷；否則 fallback 原本查詢
        if ($existingBetRecordIdSet !== null) {
            $alreadyExists = isset($existingBetRecordIdSet[$betRecord->id]);
            if ($alreadyExists) {
                // $totalBetRecord = TotalBetRecord::query()
                //     ->where('vendor_id', $this->vendorId)
                //     ->where('bet_record_id', $betRecord->id)
                //     ->first();
                //
                // if ($totalBetRecord) {
                //     // 不觸發 updated_at 自動更新
                //     $totalBetRecord->timestamps = false;
                //     $totalBetRecord->update([
                //         'payout_time' => $totalBetRecord->created_at,
                //     ]);
                // }

                return;
            }
        } else {
            // 確認是否已經有 TotalBetRecord
            $totalBetRecord = TotalBetRecord::query()
                ->where('vendor_id', $this->vendorId)
                ->where('bet_record_id', $betRecord->id)
                ->first();

            if ($totalBetRecord) {
                $totalBetRecord->timestamps = false;
                $totalBetRecord->update([
                    'payout_time' => $totalBetRecord->created_at,
                ]);

                return;
            }
        }

        $member = $betRecord->member;

        DB::transaction(function () use ($betRecord, $member, $lastTransactionLogIdByMember) {
            $betRecord->update([
                // 'payout_time' => now()->toDateTimeString(),
                'payout_time' => $betRecord->bet_time,
                'status' => self::STATUS_ENDED,
            ]);

            // 優先使用 chunk 預先查到的最後一筆 transaction log id（大幅減少查詢數）
            $transactionLogId = $lastTransactionLogIdByMember[$member->id] ?? null;

            // fallback：若查不到，才用原本的 service 查詢，避免影響既有業務邏輯
            if (empty($transactionLogId)) {
                $transactionLogService = new TransactionLogCreateService(member: $member, isMultipleWallet: $this->isMultipleWallet);
                $transactionLog = $transactionLogService->getLastTransactionLog();
                $transactionLogId = $transactionLog->id;
            }

            $totalBetRecordService = new TotalBetRecordService($this->vendorId, $member->id, $this->isMultipleWallet);
            $totalBetRecordService->create($betRecord, $transactionLogId);
        });
    }
}
