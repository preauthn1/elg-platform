<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Data\MerchantGameListSyncResultData;
use App\Data\VendorGameListSyncResultData;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\QueueJobRunStatusEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Jobs\SyncVendorGameList;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\GameListSyncService;
use App\Services\VendorService;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\HasGameList;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Jobs/SyncVendorGameListTest.php --compact
 */
class SyncVendorGameListTest extends TestCase
{
    private const int VENDOR_ID = VendorIdEnum::PP->value;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(self::VENDOR_ID)) {
            Vendor::factory()->create(['id' => self::VENDOR_ID, 'name' => 'PP', 'status' => 1]);
        }

        Vendor::where('id', self::VENDOR_ID)->update(['status' => 1]);
        VendorInfo::factory()->create(['vendor_id' => self::VENDOR_ID, 'status' => 1]);
    }

    /** 模擬派發端：建 pending 列並綁上 job */
    private function makeJob(QueueJobRunSourceEnum $source = QueueJobRunSourceEnum::SCHEDULE): SyncVendorGameList
    {
        $job = new SyncVendorGameList(self::VENDOR_ID, $source);
        $job->setQueueJobRunId(QueueJobRun::createPending(
            jobKey: $job->queueJobRunKey(),
            workKey: $job->queueJobRunWorkKey(),
            bucketTime: $job->queueJobRunBucketTime(),
            source: $source,
        ));

        return $job;
    }

    #[TestDox('同步跑完後把派發時建立的執行列收尾為成功，output 載明各商戶統計')]
    public function test_finishes_run_after_sync(): void
    {
        $job = $this->makeJob(QueueJobRunSourceEnum::MANUAL);

        $job->handle(...$this->services(syncCalled: true));

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, $run->status);
        $this->assertStringContainsString('商戶', $run->output);
        $this->assertSame(QueueJobRunSourceEnum::MANUAL->value, $run->source);
        $this->assertSame((string) self::VENDOR_ID, $run->work_key);
    }

    #[TestDox('遊戲商已停用而略過：列收尾為成功、output 載明略過原因（派發了但沒做事有跡可循）')]
    public function test_skip_finishes_run_with_reason(): void
    {
        Vendor::where('id', self::VENDOR_ID)->update(['status' => 0]);
        $job = $this->makeJob();

        $job->handle(...$this->services(syncCalled: false));

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, $run->status);
        $this->assertSame('略過：遊戲商或商戶已停用', $run->output);
    }

    #[TestDox('driver 未實作 HasGameList：列收尾為成功、output 載明略過原因')]
    public function test_skip_when_driver_lacks_game_list(): void
    {
        $driver = Mockery::mock(AbstractTransferWallet::class);
        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);
        $syncService = Mockery::mock(GameListSyncService::class);
        $syncService->shouldNotReceive('syncVendor');
        $job = $this->makeJob();

        $job->handle($vendorService, $syncService);

        $this->assertSame('略過：driver 未實作 HasGameList', QueueJobRun::find($job->queueJobRunId)->output);
    }

    #[TestDox('同一小時內重複派發各自成列，run_uuid 逐列相異（一次執行 = 一列）')]
    public function test_repeated_runs_in_same_hour_create_separate_rows(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->makeJob()->handle(...$this->services(syncCalled: true));
        }

        $this->assertSame(3, $this->runCount());

        $uuids = QueueJobRun::query()
            ->where('job_key', QueueJobKeyEnum::UPDATE_GAME_LIST->value)
            ->where('work_key', (string) self::VENDOR_ID)
            ->pluck('run_uuid')
            ->all();

        $this->assertCount(3, array_unique($uuids));
    }

    #[TestDox('同步拋出例外時列停在未處理，failed hook 收尾為失敗並記例外訊息')]
    public function test_failed_hook_marks_run_failed(): void
    {
        $driver = Mockery::mock(AbstractTransferWallet::class, HasGameList::class);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);

        $syncService = Mockery::mock(GameListSyncService::class);
        $syncService->shouldReceive('syncVendor')->once()->andThrow(new \RuntimeException('三方逾時'));

        $job = $this->makeJob();

        try {
            $job->handle($vendorService, $syncService);
            $this->fail('預期 syncVendor 的例外會上拋');
        } catch (\RuntimeException $e) {
            // 例外照常上拋交由 queue 處理；框架最終會呼叫 failed()
            $this->assertSame(QueueJobRunStatusEnum::PENDING, QueueJobRun::find($job->queueJobRunId)->status);
            $job->failed($e);
        }

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::FAILED, $run->status);
        $this->assertSame('三方逾時', $run->output);
    }

    private function runCount(): int
    {
        return QueueJobRun::query()
            ->where('job_key', QueueJobKeyEnum::UPDATE_GAME_LIST->value)
            ->where('work_key', (string) self::VENDOR_ID)
            ->count();
    }

    /**
     * @return array{0: VendorService, 1: GameListSyncService}
     */
    private function services(bool $syncCalled): array
    {
        // getDriver 回傳型別為 AbstractTransferWallet|AbstractSingleWallet，mock 需同時滿足抽象類與能力契約
        $driver = Mockery::mock(AbstractTransferWallet::class, HasGameList::class);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);

        $syncService = Mockery::mock(GameListSyncService::class);
        $syncService->shouldReceive('syncVendor')
            ->times($syncCalled ? 1 : 0)
            ->andReturn(new VendorGameListSyncResultData(
                vendorId: self::VENDOR_ID,
                vendorName: 'PP',
                merchantResults: [new MerchantGameListSyncResultData(1, 'merchant')],
            ));

        return [$vendorService, $syncService];
    }
}
