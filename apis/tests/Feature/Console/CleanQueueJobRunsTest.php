<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Models\QueueJobRun;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * queue_job_runs 保留期清理排程測試。
 *
 * php artisan test tests/Feature/Console/CleanQueueJobRunsTest.php
 */
class CleanQueueJobRunsTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 建一列並改寫 created_at（欄位在 model guarded 中，走 query builder 回填）
     */
    private function createRunAt(string $createdAt): int
    {
        $id = QueueJobRun::createPending(
            QueueJobKeyEnum::FETCH_BET_RECORD,
            99999,
            '2026-08-01 00:00:00',
            QueueJobRunSourceEnum::SCHEDULE,
        );
        DB::table('queue_job_runs')->where('id', $id)->update(['created_at' => $createdAt]);

        return $id;
    }

    #[TestDox('刪除一個月前的執行紀錄，保留期內的列不動')]
    public function test_deletes_rows_older_than_one_month_and_keeps_recent_ones(): void
    {
        $expiredId = $this->createRunAt(now()->subMonth()->subDay()->toDateTimeString());
        $recentId = $this->createRunAt(now()->subDays(20)->toDateTimeString());

        $this->artisan('clean:queue-job-runs')->assertSuccessful();

        $this->assertNull(QueueJobRun::find($expiredId), '超過保留期的列必須被刪除');
        $this->assertNotNull(QueueJobRun::find($recentId), '保留期內的列不得被刪除');
    }

    #[TestDox('乾跑模式只回報列數，不刪除任何資料')]
    public function test_dry_run_reports_without_deleting(): void
    {
        $expiredId = $this->createRunAt(now()->subMonths(2)->toDateTimeString());

        $this->artisan('clean:queue-job-runs --dry-run')->assertSuccessful();

        $this->assertNotNull(QueueJobRun::find($expiredId), '乾跑不得刪除資料');
    }
}
