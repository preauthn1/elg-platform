<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * 批次同步 payout_time（日期範圍）
 *
 * 透過調用 ppsw:sync-payout-time 命令來處理每一天的數據同步
 * 確保邏輯與 SyncPayoutTimeFromGameRounds 保持一致
 *
 * 使用方式：php artisan ppsw:sync-payout-time-range {startDate} {endDate}
 * 範例：php artisan ppsw:sync-payout-time-range 2025-11-18 2025-11-30
 */
class SyncPayoutTimeRange extends Command
{
    protected $signature = 'ppsw:sync-payout-time-range {startDate} {endDate} {--dry-run : 僅顯示統計，不實際執行}';

    protected $description = '批次將 ppsw_game_rounds 的 endDate 同步到 ppsw_bet_records 的 payout_time（調用 ppsw:sync-payout-time）';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $startDateStr = $this->argument('startDate');
        $endDateStr = $this->argument('endDate');
        $isDryRun = $this->option('dry-run');

        // 驗證日期格式
        try {
            $startDate = Carbon::createFromFormat('Y-m-d', $startDateStr)->startOfDay();
            $endDate = Carbon::createFromFormat('Y-m-d', $endDateStr)->startOfDay();
        } catch (Exception $e) {
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d 格式（例如：2025-11-18）');

            return 1;
        }

        // 驗證日期範圍
        if ($startDate->gt($endDate)) {
            $this->error('❌ 開始日期不能大於結束日期');

            return 1;
        }

        // 計算天數
        $days = $startDate->diffInDays($endDate) + 1;

        $this->info('========================================');
        $this->info('📊 批次同步 payout_time');
        $this->info('========================================');
        $this->info('開始日期: ' . $startDate->format('Y-m-d'));
        $this->info('結束日期: ' . $endDate->format('Y-m-d'));
        $this->info('總天數: ' . $days . ' 天');
        $this->info('模式: ' . ($isDryRun ? '預覽模式（不更新）' : '執行模式'));
        $this->info('========================================');
        $this->newLine();

        // 確認是否繼續
        if (!$isDryRun && !$this->confirm('是否繼續執行？', true)) {
            $this->warn('❌ 使用者取消執行');

            return 0;
        }

        $this->newLine();

        // 統計資訊
        $successCount = 0;
        $failCount = 0;
        $datesWithIssues = [];
        $totalUpdated = 0;
        $currentDate = $startDate->copy();

        // 逐日處理
        while ($currentDate->lte($endDate)) {
            $dateStr = $currentDate->format('Y-m-d');
            $this->info('========================================');
            $this->info("📅 處理日期: {$dateStr}");
            $this->info('========================================');

            try {
                // 調用 SyncPayoutTimeFromGameRounds 命令處理這一天的同步
                $options = $isDryRun ? ['--dry-run' => true] : [];

                $exitCode = Artisan::call('ppsw:sync-payout-time',
                    array_merge(['date' => $dateStr], $options),
                    $this->output
                );

                if ($exitCode === 0) {
                    $successCount++;
                    $this->info("✅ {$dateStr} 完成");
                } else {
                    $failCount++;
                    $datesWithIssues[] = [
                        'date' => $dateStr,
                        'type' => 'error',
                        'code' => $exitCode,
                    ];
                    $this->error("❌ {$dateStr} 失敗（退出碼: {$exitCode}）");
                }
            } catch (Exception $e) {
                $failCount++;
                $datesWithIssues[] = [
                    'date' => $dateStr,
                    'type' => 'exception',
                    'message' => $e->getMessage(),
                ];
                $this->error("❌ {$dateStr} 異常: " . $e->getMessage());
            }

            $this->newLine();
            $currentDate->addDay();
        }

        // 輸出統計
        $this->info('========================================');
        $this->info('📊 執行結果統計');
        $this->info('========================================');
        $this->info("總天數: {$days}");
        $this->info("✅ 成功: {$successCount}");
        $this->info("❌ 失敗: {$failCount}");

        if (!empty($datesWithIssues)) {
            $this->newLine();
            $this->warn('⚠️  有問題的日期：');
            foreach ($datesWithIssues as $issue) {
                if ($issue['type'] === 'error') {
                    $this->warn("  - {$issue['date']} (退出碼: {$issue['code']})");
                } else {
                    $this->warn("  - {$issue['date']} (異常: {$issue['message']})");
                }
            }
            $this->newLine();
            $this->info('💡 提示：使用單日同步命令查看詳細資訊');
            $this->line('   php artisan ppsw:sync-payout-time {date}');
        }

        if ($isDryRun) {
            $this->newLine();
            $this->warn('⚠️  預覽模式：未執行實際更新');
            $this->info('移除 --dry-run 參數以執行實際更新');
        }

        $this->info('========================================');

        return $failCount > 0 ? 1 : 0;
    }
}
