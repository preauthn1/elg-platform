<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Console\Commands\SummaryBetRecord\SummaryBetRecordRunner;
use App\Console\Commands\SummaryBetRecord\SummaryBetRecordService;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Jobs\SummarizeBetRecordHour;
use App\Models\QueueJobRun;
use App\Services\BotNotifyService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Config;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * SummarizeBetRecordHour：整點 SBR 彙總 queue job 的回看補洞編排（target/tickets#4257）。
 *
 * 關帳判斷（SummaryBetRecordService::hasPastCutoff）以原生 time() 計算、不受
 * Carbon::setTestNow 影響，故「未關帳」情境以真實 now 的近 6 小時 bucket 構造
 * （回看視窗恆晚於關帳線）、「已關帳」情境以固定的舊 bucket 構造。
 *
 * php artisan test tests/Unit/Jobs/SummarizeBetRecordHourTest.php
 */
class SummarizeBetRecordHourTest extends TestCase
{
    use DatabaseTransactions;

    private const int ENABLED_VENDOR_A = 10;

    private const int DISABLED_VENDOR = 20;

    private const int ENABLED_VENDOR_B = 30;

    /** @var array<int, array{third_party_code: string, time_field: string, schedule_enabled?: bool}> */
    private const array CONFIGS = [
        self::ENABLED_VENDOR_A => ['third_party_code' => 'A', 'time_field' => 'payout_time'],
        self::DISABLED_VENDOR => ['third_party_code' => 'B', 'time_field' => 'bet_time', 'schedule_enabled' => false],
        self::ENABLED_VENDOR_B => ['third_party_code' => 'C', 'time_field' => 'payout_time'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('summary_bet_record', self::CONFIGS);
    }

    #[Test]
    public function test_processes_all_missing_pairs_across_lookback_window(): void
    {
        $trigger = $this->recentTriggerBucket();
        $buckets = $this->lookbackBuckets($trigger);

        [$runner, $vendorCalls, $spCalls] = $this->makeRunnerSpy();

        (new SummarizeBetRecordHour($trigger))->handle($runner);

        // 6 buckets × 2 個 schedule_enabled 廠商，舊 → 新逐 bucket 執行
        $this->assertCount(12, $vendorCalls->all());
        $this->assertSame(
            [self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B],
            array_unique($vendorCalls->pluck('vendorId')->all()) === [self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B]
                ? [self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B]
                : array_values(array_unique($vendorCalls->pluck('vendorId')->all())),
            'schedule_enabled=false 的廠商不得被排程觸發',
        );
        $this->assertSame($buckets[0], $vendorCalls->first()['start'], '必須由最舊 bucket 開始補');
        $this->assertSame($trigger, $vendorCalls->last()['start'], '最後處理本輪觸發 bucket');

        // 觸發 bucket 標 schedule、回看 bucket 標 backfill；一律啟用 skipIfCompleted
        foreach ($vendorCalls as $call) {
            $expected = $call['start'] === $trigger
                ? QueueJobRunSourceEnum::SCHEDULE
                : QueueJobRunSourceEnum::BACKFILL;
            $this->assertSame($expected, $call['source'], "bucket {$call['start']} 的 source 標記錯誤");
            $this->assertTrue($call['skipIfCompleted']);
            $this->assertFalse($call['dryRun']);
        }

        // 每個有處理的 bucket 都跑 SP（近 6 小時恆未關帳 → 逐 bucket 以自身 start 執行）
        $this->assertSame($buckets, $spCalls->all());
    }

    #[Test]
    public function test_only_missing_pairs_are_processed(): void
    {
        $trigger = $this->recentTriggerBucket();
        $buckets = $this->lookbackBuckets($trigger);

        // 全部標記完成，只留 (ENABLED_VENDOR_B, buckets[2]) 缺洞
        foreach ($buckets as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                if ($bucket === $buckets[2] && $vendorId === self::ENABLED_VENDOR_B) {
                    continue;
                }

                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            }
        }

        [$runner, $vendorCalls, $spCalls] = $this->makeRunnerSpy();

        (new SummarizeBetRecordHour($trigger))->handle($runner);

        $this->assertCount(1, $vendorCalls->all(), '已有完成標記的 pair 不得重跑');
        $this->assertSame(self::ENABLED_VENDOR_B, $vendorCalls->first()['vendorId']);
        $this->assertSame($buckets[2], $vendorCalls->first()['start']);
        $this->assertSame(QueueJobRunSourceEnum::BACKFILL, $vendorCalls->first()['source']);

        // 補到洞的 bucket 重跑結算；trigger bucket 另有無條件保底結算
        $this->assertSame([$buckets[2], $trigger], $spCalls->all());
    }

    #[Group('ticket-4416')]
    #[Group('ac-1 [自動] 某一小時只有手動補跑的紀錄時，下一個整點排程會自動重算這一小時')]
    #[TestDox('回看視窗內只有手動補跑標記的格子，整點排程照樣重算')]
    public function test_manual_marks_do_not_skip_scheduled_backfill(): void
    {
        $trigger = $this->recentTriggerBucket();
        $buckets = $this->lookbackBuckets($trigger);

        // 全部由排程完成，只有 (ENABLED_VENDOR_A, buckets[3]) 是手動補跑留下的標記
        foreach ($buckets as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                $source = $bucket === $buckets[3] && $vendorId === self::ENABLED_VENDOR_A
                    ? QueueJobRunSourceEnum::MANUAL
                    : QueueJobRunSourceEnum::SCHEDULE;

                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: $source);
            }
        }

        [$runner, $vendorCalls] = $this->makeRunnerSpy();

        (new SummarizeBetRecordHour($trigger))->handle($runner);

        $this->assertCount(1, $vendorCalls->all());
        $this->assertSame(self::ENABLED_VENDOR_A, $vendorCalls->first()['vendorId']);
        $this->assertSame($buckets[3], $vendorCalls->first()['start']);
        $this->assertSame(QueueJobRunSourceEnum::BACKFILL, $vendorCalls->first()['source']);
    }

    #[Test]
    public function test_fully_completed_window_still_settles_trigger_bucket(): void
    {
        $trigger = $this->recentTriggerBucket();

        foreach ($this->lookbackBuckets($trigger) as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            }
        }

        $runner = Mockery::mock(SummaryBetRecordRunner::class);
        $runner->shouldNotReceive('runVendorBucket');
        // 結算沒有完成標記：「vendor 標記已齊、上輪結算失敗」的 retry 也會走到這裡，
        // trigger bucket 必須無條件重跑結算（writer 刪除重建、冪等），否則結算失敗
        // 會被 retry 靜默洗成成功、缺格永遠不補
        $runner->shouldReceive('runSummarySettlement')->once()->with($trigger);

        (new SummarizeBetRecordHour($trigger))->handle($runner);

        // Mockery 於 tearDown 驗證上述期望（once() 驗證計入 assertion 數）
    }

    #[Test]
    public function test_backfill_settlement_failure_resets_marks_so_retry_reruns_settlement(): void
    {
        $trigger = $this->recentTriggerBucket();
        $buckets = $this->lookbackBuckets($trigger);

        // 只留 buckets[2]（回看 bucket）兩廠商缺洞，其餘全數已完成
        foreach ($buckets as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                if ($bucket === $buckets[2]) {
                    continue;
                }

                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            }
        }

        [$runner, $settlementCalls] = $this->makeMarkerWritingRunnerSpy(
            // buckets[2] 的結算第一次失敗、之後成功；trigger 保底結算恆成功
            failSettlementOnceFor: $buckets[2],
        );

        try {
            (new SummarizeBetRecordHour($trigger))->handle($runner);
            $this->fail('回看 bucket 結算失敗時 job 必須拋出例外觸發 retry');
        } catch (RuntimeException) {
            // 預期：進入 retry
        }

        // 結算失敗必須重置該 bucket 的完成標記，retry 才會重新判缺、重跑結算
        $this->assertSame(
            0,
            $this->sbrRuns()->where('bucket_time', $buckets[2])->count(),
            '結算失敗的 bucket 必須重置 vendor 完成標記（否則 retry 因標記已齊跳過結算，缺格永久靜默）',
        );

        // 模擬 retry：同一 trigger 再跑一次
        (new SummarizeBetRecordHour($trigger))->handle($runner);

        $this->assertSame(
            2,
            $settlementCalls->filter(fn (string $time): bool => $time === $buckets[2])->count(),
            'retry 必須重跑失敗 bucket 的結算',
        );
        $this->assertSame(
            2,
            $this->sbrRuns()->where('bucket_time', $buckets[2])->count(),
            'retry 成功後完成標記回填',
        );
    }

    #[Test]
    public function test_past_cutoff_settlement_failure_resets_marks_so_retry_reruns_settlement(): void
    {
        // 固定舊 trigger：整個回看視窗已關帳 → 結算集中以關帳時間跑一次
        $trigger = '2026-08-20 10:00:00';
        $cutoff = SummaryBetRecordService::getDailyCutoffTime();

        [$runner, $settlementCalls] = $this->makeMarkerWritingRunnerSpy(failSettlementOnceFor: $cutoff);

        try {
            (new SummarizeBetRecordHour($trigger))->handle($runner);
            $this->fail('關帳集中結算失敗時 job 必須拋出例外觸發 retry');
        } catch (RuntimeException) {
            // 預期：進入 retry
        }

        $this->assertSame(
            0,
            $this->sbrRuns()->count(),
            '關帳集中結算失敗必須重置本輪全部相關 bucket 的完成標記',
        );

        (new SummarizeBetRecordHour($trigger))->handle($runner);

        $this->assertSame(
            2,
            $settlementCalls->filter(fn (string $time): bool => $time === $cutoff)->count(),
            'retry 必須重跑關帳集中結算',
        );
        $this->assertSame(12, $this->sbrRuns()->count(), 'retry 成功後 6 buckets × 2 廠商標記回填');
    }

    #[Test]
    public function test_settlement_failure_throws_for_retry(): void
    {
        $trigger = $this->recentTriggerBucket();

        $runner = Mockery::mock(SummaryBetRecordRunner::class);
        $runner->shouldReceive('prepareSharedData')->zeroOrMoreTimes();
        $runner->shouldReceive('runVendorBucket')->andReturn(['mode' => 'overwrite', 'written' => 0, 'reversed' => 0]);
        $runner->shouldReceive('runSummarySettlement')->andThrow(new RuntimeException('team lock held'));

        try {
            (new SummarizeBetRecordHour($trigger))->handle($runner);
            $this->fail('彙總結算失敗時 job 必須拋出例外觸發 retry');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('settlement', $e->getMessage());
        }
    }

    #[Test]
    public function test_vendor_failure_continues_others_then_throws_for_retry(): void
    {
        $trigger = $this->recentTriggerBucket();
        $buckets = $this->lookbackBuckets($trigger);

        // 只留觸發 bucket 兩廠商缺洞
        foreach (array_slice($buckets, 0, 5) as $bucket) {
            foreach ([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B] as $vendorId) {
                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $bucket, source: QueueJobRunSourceEnum::SCHEDULE);
            }
        }

        $spCalls = collect();
        $attempted = collect();

        $runner = Mockery::mock(SummaryBetRecordRunner::class);
        $runner->shouldReceive('prepareSharedData')->zeroOrMoreTimes();
        $runner->shouldReceive('runVendorBucket')->andReturnUsing(
            function (int $vendorId) use ($attempted) {
                $attempted->push($vendorId);

                if ($vendorId === self::ENABLED_VENDOR_A) {
                    throw new RuntimeException('vendor A exploded');
                }

                return ['mode' => 'overwrite', 'written' => 0, 'reversed' => 0];
            }
        );
        $runner->shouldReceive('runSummarySettlement')->andReturnUsing(fn (string $time) => $spCalls->push($time));

        try {
            (new SummarizeBetRecordHour($trigger))->handle($runner);
            $this->fail('部分廠商失敗時 job 必須拋出例外觸發 retry');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString((string) self::ENABLED_VENDOR_A, $e->getMessage());
        }

        // 失敗不中斷其他廠商；成功廠商所在 bucket 的 SP 照常執行
        $this->assertSame([self::ENABLED_VENDOR_A, self::ENABLED_VENDOR_B], $attempted->all());
        $this->assertSame([$trigger], $spCalls->all());
    }

    #[Test]
    public function test_past_cutoff_buckets_consolidate_sp_to_single_cutoff_run(): void
    {
        // 固定舊 bucket：整個回看視窗必然已關帳 → SP 只以關帳時間跑一次
        $trigger = '2026-08-20 10:00:00';

        [$runner, $vendorCalls, $spCalls] = $this->makeRunnerSpy();

        (new SummarizeBetRecordHour($trigger))->handle($runner);

        $this->assertCount(12, $vendorCalls->all());
        $this->assertSame([SummaryBetRecordService::getDailyCutoffTime()], $spCalls->all());
    }

    #[Test]
    public function test_failed_sends_bot_notify_with_bucket(): void
    {
        $this->mock(BotNotifyService::class)
            ->shouldReceive('send')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, '2026-08-28 11:00:00') && str_contains($message, 'boom'));

        (new SummarizeBetRecordHour('2026-08-28 11:00:00'))->failed(new RuntimeException('boom'));
    }

    #[Test]
    public function test_queue_settings_guard_lock_and_retry_semantics(): void
    {
        $job = new SummarizeBetRecordHour('2026-08-28 11:23:45');

        // bucket 一律正規化到整點
        $this->assertSame('2026-08-28 11:00:00', $job->bucketStartUtc);

        // 長時 job 專用連線（retry_after=3660 > timeout）；
        // tries=0 + maxExceptions：WithoutOverlapping 重入不計失敗、真實例外有上限
        $this->assertSame('redis-long-running', $job->connection);
        $this->assertSame(0, $job->tries);
        $this->assertSame(3, $job->maxExceptions);
        $this->assertSame(600, $job->timeout);

        $middleware = $job->middleware();
        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);

        // 兩把 Runner 鎖的 TTL 都必須大於 job timeout：settlement 也在 job 內執行
        // （trigger 保底），鎖中途過期會讓 CLI 補跑併入同一足跡互相覆蓋
        $runnerRef = new ReflectionClass(SummaryBetRecordRunner::class);
        $this->assertGreaterThan($job->timeout, $runnerRef->getConstant('VENDOR_LOCK_TTL'));
        $this->assertGreaterThan($job->timeout, $runnerRef->getConstant('SETTLEMENT_LOCK_TTL'));
    }

    /**
     * 本測試只看 SBR 的標記列：表已通用化，斷言一律帶 job_key 過濾，
     * 免得第二個 job 接入後被別的 job 的列帶歪。
     *
     * @return Builder<QueueJobRun>
     */
    private function sbrRuns(): Builder
    {
        return QueueJobRun::query()->where('job_key', QueueJobKeyEnum::SUMMARY_BET_RECORD->value);
    }

    /**
     * 以真實 now 取「上一個整點」：回看視窗必然全在關帳線之後（未關帳）。
     */
    private function recentTriggerBucket(): string
    {
        return date('Y-m-d H:00:00', strtotime('-1 hour'));
    }

    /**
     * @return array<int, string> 回看視窗 bucket 清單（舊 → 新，含觸發 bucket）
     */
    private function lookbackBuckets(string $trigger): array
    {
        $buckets = [];

        for ($i = SummarizeBetRecordHour::LOOKBACK_HOURS - 1; $i >= 0; $i--) {
            $buckets[] = date('Y-m-d H:00:00', strtotime("{$trigger} -{$i} hours"));
        }

        return $buckets;
    }

    /**
     * 建立「會如實寫入完成標記」的 runner mock：runVendorBucket 模擬真實 Runner
     * 於成功時寫入 queue_job_runs，結算對指定 target 第一次拋錯、之後成功
     * ——供「連跑兩次 handle 驗證 retry 狀態轉移」的測試使用。
     *
     * @return array{0: SummaryBetRecordRunner&Mockery\MockInterface, 1: \Illuminate\Support\Collection}
     */
    private function makeMarkerWritingRunnerSpy(string $failSettlementOnceFor): array
    {
        $settlementCalls = collect();
        $failed = false;

        $runner = Mockery::mock(SummaryBetRecordRunner::class);
        $runner->shouldReceive('prepareSharedData')->zeroOrMoreTimes();
        $runner->shouldReceive('runVendorBucket')->andReturnUsing(
            function (int $vendorId, array $config, string $start, string $end, bool $dryRun, QueueJobRunSourceEnum $source, bool $skipIfCompleted) {
                QueueJobRun::markCompleted(jobKey: QueueJobKeyEnum::SUMMARY_BET_RECORD, workKey: $vendorId, bucketTime: $start, source: $source);

                return ['mode' => 'overwrite', 'written' => 0, 'reversed' => 0];
            }
        );
        $runner->shouldReceive('runSummarySettlement')->andReturnUsing(
            function (string $time) use (&$failed, $settlementCalls, $failSettlementOnceFor): void {
                $settlementCalls->push($time);

                if ($time === $failSettlementOnceFor && !$failed) {
                    $failed = true;

                    throw new RuntimeException('settlement boom');
                }
            }
        );

        return [$runner, $settlementCalls];
    }

    /**
     * 建立紀錄呼叫參數的 runner mock。
     *
     * @return array{0: SummaryBetRecordRunner&Mockery\MockInterface, 1: \Illuminate\Support\Collection, 2: \Illuminate\Support\Collection}
     */
    private function makeRunnerSpy(): array
    {
        $vendorCalls = collect();
        $spCalls = collect();

        $runner = Mockery::mock(SummaryBetRecordRunner::class);
        $runner->shouldReceive('prepareSharedData')->zeroOrMoreTimes();
        $runner->shouldReceive('runVendorBucket')->andReturnUsing(
            function (int $vendorId, array $config, string $start, string $end, bool $dryRun, QueueJobRunSourceEnum $source, bool $skipIfCompleted) use ($vendorCalls) {
                $vendorCalls->push(compact('vendorId', 'config', 'start', 'end', 'dryRun', 'source', 'skipIfCompleted'));

                return ['mode' => 'overwrite', 'written' => 0, 'reversed' => 0];
            }
        );
        $runner->shouldReceive('runSummarySettlement')->andReturnUsing(fn (string $time) => $spCalls->push($time));

        return [$runner, $vendorCalls, $spCalls];
    }
}
