<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * 批次比對 ppsw_game_rounds 與 ppsw_bet_records 的資料差異（日期範圍）
 *
 * 透過調用 ppsw:compare-records 命令來處理每一天的數據比對
 * 確保邏輯與 CompareGameRoundsWithBetRecords 保持一致
 *
 * 使用方式：php artisan ppsw:compare-records-range {startDate} {endDate}
 * 範例：php artisan ppsw:compare-records-range 2025-11-18 2025-11-30
 */
class CompareGameRoundsRange extends Command
{
    protected $signature = 'ppsw:compare-records-range {startDate} {endDate}';

    protected $description = '批次比對指定日期範圍的 ppsw_game_rounds 與 ppsw_bet_records 資料差異（調用 ppsw:compare-records）';

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
        $this->info('📊 批次比對遊戲輪次資料與投注記錄');
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

        // 統計資訊
        $successCount = 0;
        $failCount = 0;
        $datesWithIssues = [];
        $currentDate = $start->copy();

        // 逐日處理
        while ($currentDate->lte($end)) {
            $dateStr = $currentDate->format('Y-m-d');
            $this->info('========================================');
            $this->info("📅 處理日期: {$dateStr}");
            $this->info('========================================');

            try {
                // 調用 CompareGameRoundsWithBetRecords 命令處理這一天的比對
                $exitCode = Artisan::call('ppsw:compare-records', [
                    'date' => $dateStr,
                ], $this->output);

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
            $this->info('💡 提示：使用單日比對命令查看詳細資訊');
            $this->line('   php artisan ppsw:compare-records {date}');
        }

        $this->info('========================================');

        return $failCount > 0 ? 1 : 0;
    }
}
