<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Data\Vendor\MerchantAccountPoolResultData;
use App\Data\Vendor\VendorAccountPoolResultData;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\QueueJobRunStatusEnum;
use App\Jobs\ReplenishVendorAccountPool;
use App\Models\QueueJobRun;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorAccountPoolService;
use App\Services\VendorService;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\SupportsAccountCreation;
use Mockery;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Jobs/ReplenishVendorAccountPoolTest.php --compact
 */
class ReplenishVendorAccountPoolTest extends TestCase
{
    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::factory()->create(['status' => 1]);
        VendorInfo::factory()->create(['vendor_id' => $this->vendor->id, 'status' => 1]);
    }

    /** 模擬派發端：建 pending 列並綁上 job */
    private function makeJob(QueueJobRunSourceEnum $source = QueueJobRunSourceEnum::SCHEDULE): ReplenishVendorAccountPool
    {
        $job = new ReplenishVendorAccountPool($this->vendor->id, $source);
        $job->setQueueJobRunId(QueueJobRun::createPending(
            jobKey: $job->queueJobRunKey(),
            workKey: $job->queueJobRunWorkKey(),
            bucketTime: $job->queueJobRunBucketTime(),
            source: $source,
        ));

        return $job;
    }

    /**
     * @return array{0: VendorService, 1: VendorAccountPoolService}
     */
    private function services(bool $replenishCalled): array
    {
        // getDriver 回傳型別為 AbstractTransferWallet|AbstractSingleWallet，mock 需同時滿足抽象類與能力契約
        $driver = Mockery::mock(AbstractSingleWallet::class, SupportsAccountCreation::class);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);

        $poolService = Mockery::mock(VendorAccountPoolService::class);
        $poolService->shouldReceive('replenishVendor')
            ->times($replenishCalled ? 1 : 0)
            ->andReturn(new VendorAccountPoolResultData(
                vendorId: $this->vendor->id,
                vendorName: $this->vendor->name,
                merchantResults: [new MerchantAccountPoolResultData(
                    vendorInfoId: 1,
                    unboundCount: 1,
                    preparedAccountNumber: 3,
                    lackNumber: 2,
                    createdUsernames: ['utvap00000002', 'utvap00000003'],
                )],
            ));

        return [$vendorService, $poolService];
    }

    #[TestDox('補池跑完後把派發時建立的執行列收尾為成功，output 載明各商戶補建統計')]
    public function test_finishes_run_after_replenish(): void
    {
        $job = $this->makeJob(QueueJobRunSourceEnum::MANUAL);

        $job->handle(...$this->services(replenishCalled: true));

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, $run->status);
        $this->assertStringContainsString('補建 2（失敗 0）', $run->output);
        $this->assertSame(QueueJobRunSourceEnum::MANUAL->value, $run->source);
        $this->assertSame((string) $this->vendor->id, $run->work_key);
        $this->assertSame(QueueJobKeyEnum::CREATE_VENDOR_ACCOUNT->value, $run->job_key);
    }

    #[TestDox('遊戲商已停用而略過：列收尾為成功、output 載明略過原因（派發了但沒做事有跡可循）')]
    public function test_skip_finishes_run_with_reason(): void
    {
        Vendor::where('id', $this->vendor->id)->update(['status' => 0]);
        $job = $this->makeJob();

        $job->handle(...$this->services(replenishCalled: false));

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::SUCCESS, $run->status);
        $this->assertSame('略過：遊戲商或商戶已停用', $run->output);
    }

    #[TestDox('driver 未實作 SupportsAccountCreation：列收尾為成功、output 載明略過原因')]
    public function test_skip_when_driver_lacks_account_creation(): void
    {
        $driver = Mockery::mock(AbstractSingleWallet::class);
        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);
        $poolService = Mockery::mock(VendorAccountPoolService::class);
        $poolService->shouldNotReceive('replenishVendor');
        $job = $this->makeJob();

        $job->handle($vendorService, $poolService);

        $this->assertSame('略過：driver 未實作 SupportsAccountCreation', QueueJobRun::find($job->queueJobRunId)->output);
    }

    #[TestDox('補池拋出例外時列停在未處理，failed hook 收尾為失敗並記例外訊息')]
    public function test_failed_hook_marks_run_failed(): void
    {
        $driver = Mockery::mock(AbstractSingleWallet::class, SupportsAccountCreation::class);
        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);
        $poolService = Mockery::mock(VendorAccountPoolService::class);
        $poolService->shouldReceive('replenishVendor')->once()->andThrow(new RuntimeException('三方逾時'));

        $job = $this->makeJob();

        try {
            $job->handle($vendorService, $poolService);
            $this->fail('預期 replenishVendor 的例外會上拋');
        } catch (RuntimeException $e) {
            // 例外照常上拋交由 queue 處理；框架最終會呼叫 failed()
            $this->assertSame(QueueJobRunStatusEnum::PENDING, QueueJobRun::find($job->queueJobRunId)->status);
            $job->failed($e);
        }

        $run = QueueJobRun::find($job->queueJobRunId);
        $this->assertSame(QueueJobRunStatusEnum::FAILED, $run->status);
        $this->assertSame('三方逾時', $run->output);
    }
}
