<?php

declare(strict_types=1);

namespace Tests\Unit\Console\Commands\SummaryBetRecord;

use App\Jobs\SummarizeBetRecordHour;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * summary_bet_record:dispatch：整點排程的觸發器，只派發帶明確 bucket 的
 * SummarizeBetRecordHour job、不同步執行彙總（target/tickets#4257）。
 *
 * php artisan test tests/Unit/Console/Commands/SummaryBetRecord/DispatchSummaryBetRecordCommandTest.php
 */
class DispatchSummaryBetRecordCommandTest extends TestCase
{
    #[Test]
    public function test_dispatches_job_with_previous_utc_hour_bucket(): void
    {
        Queue::fake();

        // 邊界防抖：跨整點瞬間執行時，命令內部取值可能落在前後任一小時
        $expectedBefore = date('Y-m-d H:00:00', strtotime('-1 hour'));
        $exitCode = Artisan::call('summary_bet_record:dispatch');
        $expectedAfter = date('Y-m-d H:00:00', strtotime('-1 hour'));

        $this->assertSame(0, $exitCode);

        Queue::assertPushed(
            SummarizeBetRecordHour::class,
            fn (SummarizeBetRecordHour $job): bool => in_array($job->bucketStartUtc, [$expectedBefore, $expectedAfter], true),
        );
        Queue::assertPushed(SummarizeBetRecordHour::class, 1);
    }
}
