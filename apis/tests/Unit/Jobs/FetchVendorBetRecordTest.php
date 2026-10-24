<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Data\BetRecordFetchChunkResultData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\QueueJobRunStatusEnum;
use App\Jobs\FetchVendorBetRecord;
use App\Models\QueueJobRun;
use App\Services\BetRecordFetchService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Queue;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Jobs/FetchVendorBetRecordTest.php --compact
 */
class FetchVendorBetRecordTest extends TestCase
{
    #[TestDox('同商序列化：middleware 掛 WithoutOverlapping、鎖鍵為 vendor_id、取不到鎖 release 重入')]
    public function test_middleware_serializes_same_vendor_with_release(): void
    {
        $middleware = (new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 11:00:00'))->middleware();

        $this->assertCount(1, $middleware);
        $this->assertInstanceOf(WithoutOverlapping::class, $middleware[0]);
        $this->assertSame('6', $middleware[0]->key);
        // releaseAfter 不可為 null（null = 取不到鎖直接丟棄，會斷掉接續鏈）
        $this->assertNotNull($middleware[0]->releaseAfter);
    }

    #[TestDox('執行時將 flow 標記寫入 Context，鏈上所有 log 自動附掛 extra.flow')]
    public function test_writes_flow_context_on_handle(): void
    {
        $job = new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->runJob($job, new BetRecordFetchChunkResultData);

        $this->assertSame('fetch_bet_record', Context::get(ContextKeyEnum::FLOW->value));
    }

    #[TestDox('窗口有剩餘時派發接續任務：hop 遞增、自 pendingFrom 接續至原 endTime')]
    public function test_dispatches_continuation_job_with_remaining_window(): void
    {
        Queue::fake();

        $job = new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 13:00:00');
        $this->runJob($job, new BetRecordFetchChunkResultData(pendingFrom: '2026-08-06 11:00:00'));

        Queue::assertPushed(
            FetchVendorBetRecord::class,
            fn (FetchVendorBetRecord $pushed) => $pushed->hop === 1
                && $pushed->startTime === '2026-08-06 11:00:00'
                && $pushed->endTime === '2026-08-06 13:00:00',
        );
    }

    #[TestDox('窗口已拉完（pendingFrom = null）時不派發接續任務')]
    public function test_does_not_dispatch_when_window_completed(): void
    {
        Queue::fake();

        $job = new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 11:00:00');
        $this->runJob($job, new BetRecordFetchChunkResultData(fetched: 5, inserted: 5));

        Queue::assertNothingPushed();
    }

    /** 模擬派發端：建 pending 列並綁上 job */
    private function bindRun(FetchVendorBetRecord $job): FetchVendorBetRecord
    {
        $job->setQueueJobRunId(QueueJobRun::createPending(
            jobKey: $job->queueJobRunKey(),
            workKey: $job->queueJobRunWorkKey(),
            bucketTime: $job->queueJobRunBucketTime(),
            source: $job->source,
        ));

        return $job;
    }

    #[TestDox('片跑完後把派發時建立的執行列收尾為成功，output 載明取得／新增／更新筆數')]
    public function test_finishes_run_after_chunk_completes(): void
    {
        Queue::fake();

        $job = $this->bindRun(new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 11:00:00', source: QueueJobRunSourceEnum::MANUAL));
        $this->runJob($job, new BetRecordFetchChunkResultData(fetched: 5, inserted: 5));

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, $run->status);
        $this->assertStringContainsString('取得 5 筆', $run->output);
        $this->assertSame('6', $run->work_key);
        $this->assertSame('2026-08-06 10:00:00', $run->bucket_time->format('Y-m-d H:i:s'));
        $this->assertSame(QueueJobRunSourceEnum::MANUAL->value, $run->source);
    }

    #[TestDox('rate limit 用罄未推進窗口：本棒收尾成功，下一棒的列於派發時出生（未處理）')]
    public function test_rate_limited_run_finishes_and_next_hop_gets_own_row(): void
    {
        Queue::fake();

        $job = $this->bindRun(new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 13:00:00'));
        $this->runJob($job, new BetRecordFetchChunkResultData(
            pendingFrom: '2026-08-06 10:00:00',
            retryAfterSeconds: 300,
        ));

        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, QueueJobRun::find($job->queueJobRunId)->status);

        // 續鏈的下一棒有自己的 pending 列，且列 id 已綁上派發的 job
        $this->assertSame(2, $this->runCountFor('6'));
        Queue::assertPushed(
            FetchVendorBetRecord::class,
            fn (FetchVendorBetRecord $pushed): bool => $pushed->queueJobRunId !== null
                && QueueJobRun::find($pushed->queueJobRunId)->status === QueueJobRunStatusEnum::PENDING,
        );
    }

    #[TestDox('例外上拋時列停在未處理，failed hook 收尾為失敗')]
    public function test_failed_hook_marks_run_failed(): void
    {
        Queue::fake();
        $job = $this->bindRun(new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 11:00:00'));

        $job->failed(new \RuntimeException('三方逾時'));

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::FAILED, $run->status);
        $this->assertSame('三方逾時', $run->output);
    }

    #[TestDox('同一片重複派發各自成列，run_uuid 逐列相異（一次執行 = 一列）')]
    public function test_repeated_runs_of_same_chunk_create_separate_rows(): void
    {
        Queue::fake();

        foreach (range(1, 3) as $ignored) {
            $this->runJob(
                $this->bindRun(new FetchVendorBetRecord(6, '2026-08-06 10:00:00', '2026-08-06 11:00:00')),
                new BetRecordFetchChunkResultData(fetched: 5, inserted: 5),
            );
        }

        $this->assertSame(3, $this->runCountFor('6'));

        // run_uuid 為 UUIDv7，逐列相異且依產生順序遞增
        $uuids = QueueJobRun::query()
            ->where('job_key', QueueJobKeyEnum::FETCH_BET_RECORD->value)
            ->where('work_key', '6')
            ->orderBy('id')
            ->pluck('run_uuid')
            ->all();

        $this->assertCount(3, array_unique($uuids));
        $sorted = $uuids;
        sort($sorted);
        $this->assertSame($sorted, $uuids);
    }

    private function runCountFor(string $workKey): int
    {
        return QueueJobRun::query()
            ->where('job_key', QueueJobKeyEnum::FETCH_BET_RECORD->value)
            ->where('work_key', $workKey)
            ->count();
    }

    #[TestDox('接續任務沿用 useCache 與 source，續鏈全程來源一致')]
    public function test_continuation_job_carries_use_cache_and_source(): void
    {
        Queue::fake();

        $job = new FetchVendorBetRecord(
            6,
            '2026-08-06 10:00:00',
            '2026-08-06 13:00:00',
            useCache: true,
            source: QueueJobRunSourceEnum::MANUAL,
        );
        $this->runJob($job, new BetRecordFetchChunkResultData(pendingFrom: '2026-08-06 11:00:00'));

        Queue::assertPushed(
            FetchVendorBetRecord::class,
            fn (FetchVendorBetRecord $pushed): bool => $pushed->useCache === true
                && $pushed->source === QueueJobRunSourceEnum::MANUAL,
        );
    }

    private function runJob(FetchVendorBetRecord $job, BetRecordFetchChunkResultData $result): void
    {
        // getDriver 回傳型別為 AbstractTransferWallet|AbstractSingleWallet，mock 需同時滿足抽象類與能力契約
        $driver = Mockery::mock(AbstractTransferWallet::class, SupportsBetRecordFetch::class);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);

        $fetchService = Mockery::mock(BetRecordFetchService::class);
        $fetchService->shouldReceive('fetchChunk')->once()->andReturn($result);

        $job->handle($vendorService, $fetchService);
    }
}
