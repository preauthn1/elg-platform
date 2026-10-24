<?php

declare(strict_types=1);

namespace App\Console\Commands\Schedule;

use App\Services\EventV2\AngpowPeriodResetService;
use Illuminate\Console\Command;

/**
 * 紅包週期到期重置的手動入口（target/tickets#4234）。
 *
 * 排程上不掛這支：紅包走既有的 V2 機制（event_v2.schedule `0 16 * * *` → check:event_v2_schedule → app:init-event-v2，
 * InitEventV2 看到紅包類型就交給 AngpowPeriodResetService）。這支給排查用：--dry-run 先看會重置哪些紀錄，
 * 或排程錯過時手動補跑。規則與判斷全在 AngpowPeriodResetService，這裡不重複。
 *
 * 用法：
 *   php artisan event-v2:reset-period-joins --dry-run   # 只列出會重置哪些紀錄，不寫入
 *   php artisan event-v2:reset-period-joins             # 手動補跑
 */
class ResetExpiredPeriodJoins extends Command
{
    protected $signature = 'event-v2:reset-period-joins {--dry-run : 只列出會重置哪些紀錄，不寫入}';

    protected $description = '依各檔活動的會員週期 member_period 重置上個週期還停在 JOIN 的參加紀錄（紅包，手動／排查用）';

    public function handle(AngpowPeriodResetService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $result = $service->run(dryRun: $dryRun, dryRunLine: fn (string $line) => $this->line($line));

        $this->info(($dryRun ? '[dry-run] ' : '') . "reset {$result['reset']}, skipped {$result['skipped']}, failed {$result['failed']}");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
