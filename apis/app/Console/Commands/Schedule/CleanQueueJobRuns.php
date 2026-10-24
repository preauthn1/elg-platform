<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Models\QueueJobRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 清理 queue_job_runs 過期執行紀錄（保留一個月）
 *
 * 表由多個 job 共用（QueueJobKeyEnum），最大量來源是 fetch_bet_record：
 * 每 5 分鐘逐商派發、續棒各自成列，放著不清數月即撞 SchemaMonitor 的
 * 50 萬列 ALTER 門檻。執行紀錄價值隨時間衰減，舊於保留期的排查走 Log。
 *
 * 保留一個月已涵蓋所有讀取端的回看視窗：SBR 補洞回看 6 小時、
 * summary_bet_record:audit 預設 48 小時（--hours 拉超過保留期會把已清理的
 * 完成標記誤報成缺格，勿超過）。
 *
 * php artisan clean:queue-job-runs
 * php artisan clean:queue-job-runs --dry-run
 */
class CleanQueueJobRuns extends Command
{
    /** 分批刪除，避免單一大 DELETE 長時間持鎖拖累線上寫入 */
    private const int CHUNK_SIZE = 10000;

    protected $signature = 'clean:queue-job-runs {--dry-run : 只顯示將刪除的列數，不實際刪除}';

    protected $description = '清理一個月前的 queue_job_runs 執行紀錄';

    public function handle(): int
    {
        $cutoff = now()->subMonth();

        if ($this->option('dry-run')) {
            $count = QueueJobRun::query()->where('created_at', '<', $cutoff)->count();
            $this->warn("=== 乾跑模式：{$cutoff} 前的執行紀錄共 {$count} 列，未刪除 ===");

            return self::SUCCESS;
        }

        $total = 0;

        do {
            $deleted = QueueJobRun::query()
                ->where('created_at', '<', $cutoff)
                ->limit(self::CHUNK_SIZE)
                ->delete();
            $total += $deleted;
        } while ($deleted > 0);

        Log::info('clean:queue-job-runs 完成', ['cutoff' => (string) $cutoff, 'deleted' => $total]);
        $this->info("已刪除 {$cutoff} 前的執行紀錄 {$total} 列");

        return self::SUCCESS;
    }
}
