<?php

declare(strict_types=1);

namespace App\Console\Commands\Vendor\PragmaticPlay\RetrieveRecords;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * 批次取得 PragmaticPlay 遊戲輪次資料（日期範圍）
 *
 * 透過調用 ppsw:game-rounds 命令來處理每一天的數據
 * 確保邏輯與 FetchGameRounds 保持一致
 *
 * 使用方式：php artisan ppsw:game-rounds-range {startDate} {endDate}
 * 範例：php artisan ppsw:game-rounds-range 2025-11-18 2025-11-30
 */
class FetchGameRoundsRange extends Command
{
    protected $signature = 'ppsw:game-rounds-range {startDate} {endDate}';

    protected $description = '批次取得指定日期範圍的 PPSW 遊戲輪次資料（調用 ppsw:game-rounds）';

    /**
     * Execute the console command.
     *
     * @throws Exception
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
            $this->error('❌ 日期格式錯誤，請使用 Y-m-d 格式（例如：2025-11-17）');

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
        $this->info('📊 批次取得遊戲輪次資料');
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
        $failedDates = [];
        $currentDate = $start->copy();

        // 逐日處理
        while ($currentDate->lte($end)) {
            $dateStr = $currentDate->format('Y-m-d');
            $this->info('========================================');
            $this->info("📅 處理日期: {$dateStr}");
            $this->info('========================================');

            try {
                // 調用 FetchGameRounds 命令處理這一天的數據
                $exitCode = Artisan::call('ppsw:game-rounds', [
                    'date' => $dateStr,
                ], $this->output);

                if ($exitCode === 0) {
                    $successCount++;
                    $this->info("✅ {$dateStr} 完成");
                } else {
                    $failCount++;
                    $failedDates[] = $dateStr;
                    $this->error("❌ {$dateStr} 失敗（退出碼: {$exitCode}）");
                }
            } catch (Exception $e) {
                $failCount++;
                $failedDates[] = $dateStr;
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

        if (!empty($failedDates)) {
            $this->newLine();
            $this->warn('⚠️  失敗的日期：');
            foreach ($failedDates as $date) {
                $this->warn("  - {$date}");
            }
        }

        $this->info('========================================');

        return $failCount > 0 ? 1 : 0;
    }
}
