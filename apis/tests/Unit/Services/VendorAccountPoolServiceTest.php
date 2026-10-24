<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\CreateAccountResultData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorGameUrlResultData;
use App\Enums\QueueJobKeyEnum;
use App\Jobs\ReplenishVendorAccountPool;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Models\VendorMaintenances;
use App\Services\VendorAccountPoolService;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\SupportsAccountCreation;
use App\Vendors\Models\KissH5SWAccount;
use App\Vendors\Models\KissH5SWBetRecord;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Tests\TestCase;

/**
 * 遊戲帳號池補充服務測試
 *
 * 核心守護：水位口徑（member_id = 0 且啟用才算池內）、流水號自現存最大值續號
 * （含已綁定帳號，號碼被占用就不可重發）、排程全量範圍由 ShouldScheduleAccountCreation
 * marker 決定而非 vendor id 分界線。
 *
 * php artisan test tests/Unit/Services/VendorAccountPoolServiceTest.php
 */
class VendorAccountPoolServiceTest extends TestCase
{
    /** 測試專用帳號前綴（流水號以 LIKE 前綴解析，避免撞測試庫既有資料） */
    private const string PREFIX = 'utvap';

    private VendorAccountPoolService $poolService;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->poolService = app(VendorAccountPoolService::class);
        $this->vendor = Vendor::factory()->create();
        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'account_prefix' => self::PREFIX,
                'prepared_account_number' => 3,
            ])
        );
        $this->vendor->load('vendorInfos');
    }

    /**
     * 建立實作 SupportsAccountCreation 的假 driver：成功時寫入帳號表（同真實 driver 行為）
     */
    private function makeDriver(bool $shouldFail = false): SupportsAccountCreation
    {
        return new class($shouldFail) implements SupportsAccountCreation
        {
            /** @var list<string> */
            public array $requestedUsernames = [];

            public function __construct(private readonly bool $shouldFail = false) {}

            public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
            {
                $this->requestedUsernames[] = $params->username;

                if ($this->shouldFail) {
                    return new CreateAccountResultData(status: false);
                }

                KissH5SWAccount::query()->create([
                    'vendor_info_id' => $params->vendorInfo->id,
                    'username' => $params->username,
                    'member_id' => 0,
                    'status' => 1,
                ]);

                return new CreateAccountResultData(status: true, username: $params->username);
            }

            public function getAccountModel(): string
            {
                return KissH5SWAccount::class;
            }

            public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
            {
                return (string) $vendorInfo->account_prefix;
            }
        };
    }

    private function createAccount(int $serial, int $memberId = 0, int $status = 1): KissH5SWAccount
    {
        return KissH5SWAccount::query()->create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => self::PREFIX . str_pad((string) $serial, 8, '0', STR_PAD_LEFT),
            'member_id' => $memberId,
            'status' => $status,
        ]);
    }

    #[Group('op3177')]
    #[Group('op3177-ac2')]
    #[Group('op3177-ac4')]
    #[TestDox('[AC-2][AC-4] 帳號池不足時自現存最大流水號續號，補至安全庫存')]
    public function test_replenish_creates_accounts_from_latest_serial_up_to_prepared_number(): void
    {
        $this->createAccount(5);

        $driver = $this->makeDriver();
        $result = $this->poolService->replenishVendor($this->vendor, $driver);

        $merchant = $result->merchantResults[0];
        $this->assertSame(1, $merchant->unboundCount);
        $this->assertSame(2, $merchant->lackNumber);
        $this->assertSame([self::PREFIX . '00000006', self::PREFIX . '00000007'], $merchant->createdUsernames);
        $this->assertSame([], $merchant->failedUsernames);
        $this->assertDatabaseHas('kissh5sw_accounts', ['username' => self::PREFIX . '00000006', 'vendor_info_id' => $this->vendorInfo->id]);
        $this->assertDatabaseHas('kissh5sw_accounts', ['username' => self::PREFIX . '00000007', 'vendor_info_id' => $this->vendorInfo->id]);
    }

    #[Group('op3177')]
    #[Group('op3177-ac2')]
    #[TestDox('[AC-2] 水位足夠時不呼叫 createAccount')]
    public function test_replenish_skips_creation_when_pool_is_full(): void
    {
        $this->createAccount(1);
        $this->createAccount(2);
        $this->createAccount(3);

        $driver = $this->makeDriver();
        $result = $this->poolService->replenishVendor($this->vendor, $driver);

        $this->assertSame(0, $result->merchantResults[0]->lackNumber);
        $this->assertSame([], $driver->requestedUsernames);
    }

    #[Group('op3177')]
    #[Group('op3177-ac4')]
    #[TestDox('[AC-4] 已綁定與停用帳號不計入水位，但佔用的流水號不重發')]
    public function test_bound_and_disabled_accounts_do_not_count_but_keep_their_serials(): void
    {
        $this->createAccount(8, memberId: 123);
        $this->createAccount(9, status: 0);

        $driver = $this->makeDriver();
        $result = $this->poolService->replenishVendor($this->vendor, $driver);

        $merchant = $result->merchantResults[0];
        $this->assertSame(0, $merchant->unboundCount);
        $this->assertSame(3, $merchant->lackNumber);
        // 綁定／停用帳號不算池內，但 9 號已被占用，續號必須從 10 開始
        $this->assertSame(
            [self::PREFIX . '00000010', self::PREFIX . '00000011', self::PREFIX . '00000012'],
            $merchant->createdUsernames,
        );
    }

    #[Group('op3177')]
    #[Group('op3177-ac3')]
    #[TestDox('[AC-3] driver 回報建立失敗時記入 failedUsernames 並繼續補下一個')]
    public function test_failed_creations_are_reported_and_do_not_abort(): void
    {
        $driver = $this->makeDriver(shouldFail: true);
        $result = $this->poolService->replenishVendor($this->vendor, $driver);

        $merchant = $result->merchantResults[0];
        $this->assertSame([], $merchant->createdUsernames);
        $this->assertCount(3, $merchant->failedUsernames);
        // 失敗不寫入帳號表 → 流水號不推進，三次嘗試同一個號
        $this->assertSame(self::PREFIX . '00000001', $merchant->failedUsernames[0]);
    }

    #[Group('op3463')]
    #[TestDox('時間預算用罄即收手，不再對三方送出建號請求（缺口留待下一輪）')]
    public function test_replenish_stops_when_time_budget_is_exhausted(): void
    {
        $driver = $this->makeDriver();

        // deadline 已過：第一圈檢查就該收手。沒有這道煞車時會照缺口補滿 3 筆，
        // 在線上即為「每輪都跑到 job timeout 被 SIGALRM 砍掉」（target/op_tickets#3463）
        $result = $this->poolService->replenishVendor($this->vendor, $driver, microtime(true) - 1);

        $merchant = $result->merchantResults[0];
        $this->assertSame([], $driver->requestedUsernames);
        $this->assertSame([], $merchant->createdUsernames);
        // 水位診斷照常回報，缺口留給下一輪重算
        $this->assertSame(3, $merchant->lackNumber);
    }

    #[TestDox('排程全量只掃 driver 實作 ShouldScheduleAccountCreation 的遊戲商；--vendor-id 指哪打哪')]
    public function test_schedule_scope_follows_should_schedule_marker(): void
    {
        $manualVendor = Vendor::factory()->create();
        $manualVendor->vendorInfos()->save(VendorInfo::factory()->make(['admin' => 0]));

        $manualOnlyDriverClass = get_class(new class implements SupportsAccountCreation
        {
            public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
            {
                return new CreateAccountResultData;
            }

            public function getAccountModel(): string
            {
                return KissH5SWAccount::class;
            }

            public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
            {
                return (string) $vendorInfo->account_prefix;
            }
        });
        $scheduledDriverClass = get_class(new class implements ShouldScheduleAccountCreation
        {
            public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
            {
                return new CreateAccountResultData;
            }

            public function getAccountModel(): string
            {
                return KissH5SWAccount::class;
            }

            public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
            {
                return (string) $vendorInfo->account_prefix;
            }
        });

        config()->set('vendor.driver', [
            $this->vendor->id => $scheduledDriverClass,
            $manualVendor->id => $manualOnlyDriverClass,
        ]);

        $scheduledIds = $this->poolService->availableVendors()->pluck('id')->all();
        $this->assertContains($this->vendor->id, $scheduledIds);
        $this->assertNotContains($manualVendor->id, $scheduledIds);

        // 指定 vendor id 時指哪打哪：只實作 SupportsAccountCreation 也可手動補建
        $specificIds = $this->poolService->availableVendors($manualVendor->id)->pluck('id')->all();
        $this->assertSame([$manualVendor->id], $specificIds);
    }

    #[TestDox('排程派發：逐商建 pending 執行列並推入 queue，同 driver 依序錯開 15 秒')]
    public function test_dispatch_queue_replenish_pushes_jobs_with_same_upstream_stagger(): void
    {
        Queue::fake();

        $siblingVendor = Vendor::factory()->create();
        $siblingVendor->vendorInfos()->save(VendorInfo::factory()->make(['admin' => 0]));

        $scheduledDriverClass = get_class(new class implements ShouldScheduleAccountCreation
        {
            public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
            {
                return new CreateAccountResultData;
            }

            public function getAccountModel(): string
            {
                return KissH5SWAccount::class;
            }

            public function getStoredAccountPrefix(VendorInfo $vendorInfo): string
            {
                return (string) $vendorInfo->account_prefix;
            }
        });

        // 兩商共用同一 driver class（聚合遊戲商情境）→ 派發需錯開
        config()->set('vendor.driver', [
            $this->vendor->id => $scheduledDriverClass,
            $siblingVendor->id => $scheduledDriverClass,
        ]);

        $dispatched = $this->poolService->dispatchQueueReplenish();

        $this->assertCount(2, $dispatched);
        $this->assertSame([0, 15], array_column($dispatched, 'delay'));
        Queue::assertPushed(ReplenishVendorAccountPool::class, 2);

        // 派發即建列：兩商各有一列 pending 執行列
        foreach ([$this->vendor->id, $siblingVendor->id] as $vendorId) {
            $this->assertDatabaseHas('queue_job_runs', [
                'job_key' => QueueJobKeyEnum::CREATE_VENDOR_ACCOUNT->value,
                'work_key' => (string) $vendorId,
            ]);
        }
    }

    #[Group('op3177')]
    #[Group('op3177-ac6')]
    #[TestDox('[AC-6] 單商失敗以 error 欄位回報並繼續，不中斷整批')]
    public function test_replenish_now_isolates_vendor_failures(): void
    {
        // factory vendor 不在 config('vendor.driver') 名單 → driver 解析失敗，
        // replenishNow 應以 error 欄位回報並正常返回，不拋例外中斷整批
        $results = $this->poolService->replenishNow($this->vendor->id);

        $this->assertCount(1, $results);
        $this->assertSame($this->vendor->id, $results[0]->vendorId);
        $this->assertNotNull($results[0]->error);
    }

    #[TestDox('乾跑回滾我方資料庫寫入，結果仍回報本輪會補建的帳號')]
    public function test_preview_rolls_back_database_writes(): void
    {
        // 走 config 解析路徑的假 driver 必須滿足 VendorService::getDriver 的回傳型別
        $driverClass = get_class(new class($this->vendor->id) extends AbstractSingleWallet implements SupportsAccountCreation
        {
            protected string $accountModel = KissH5SWAccount::class;

            protected string $betRecordModel = KissH5SWBetRecord::class;

            public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
            {
                throw new RuntimeException('not used');
            }

            public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
            {
                KissH5SWAccount::query()->create([
                    'vendor_info_id' => $params->vendorInfo->id,
                    'username' => $params->username,
                    'member_id' => 0,
                    'status' => 1,
                ]);

                return new CreateAccountResultData(status: true, username: $params->username);
            }
        });

        config()->set('vendor.driver', [$this->vendor->id => $driverClass]);

        $results = $this->poolService->previewReplenish($this->vendor->id);

        $this->assertNull($results[0]->error);
        $this->assertSame(3, $results[0]->merchantResults[0]->lackNumber);
        $this->assertCount(3, $results[0]->merchantResults[0]->createdUsernames);
        $this->assertDatabaseMissing('kissh5sw_accounts', ['username' => self::PREFIX . '00000001']);
    }

    #[TestDox('庫存查詢逐商戶回報未綁定／目標水位／缺口與排程標記，且純唯讀不補建')]
    public function test_inventory_reports_merchant_stock_without_creating_accounts(): void
    {
        // 走 config 解析路徑的假 driver 必須滿足 VendorService::getDriver 的回傳型別
        $driverClass = get_class(new class($this->vendor->id) extends AbstractSingleWallet implements ShouldScheduleAccountCreation
        {
            protected string $accountModel = KissH5SWAccount::class;

            protected string $betRecordModel = KissH5SWBetRecord::class;

            public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
            {
                throw new RuntimeException('not used');
            }

            public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
            {
                throw new RuntimeException('查詢不得補建');
            }
        });

        config()->set('vendor.driver', [$this->vendor->id => $driverClass]);

        $this->createAccount(1);                          // 池內：未綁定且啟用
        $this->createAccount(2, memberId: 5);             // 已綁定，不計水位
        $this->createAccount(3, status: 0);               // 停用，不計水位

        $result = $this->poolService->inventory($this->vendor->id);

        $this->assertSame([], $result['errors']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame([
            'vendor_id' => $this->vendor->id,
            'vendor_name' => $this->vendor->name,
            'scheduled' => true,
            'maintenance' => false,
            'vendor_info_id' => $this->vendorInfo->id,
            'vendor_info_name' => (string) $this->vendorInfo->name,
            'currency' => (string) $this->vendorInfo->currency,
            'unbound' => 1,
            'target' => 3,
            'lack' => 2,
        ], $result['rows'][0]);

        // 唯讀保證：查詢過程不得呼叫 createAccount（假 driver 會拋例外進 errors）也不得落庫
        $this->assertSame(3, KissH5SWAccount::query()->where('vendor_info_id', $this->vendorInfo->id)->count());

        // 維護中不排除、但列要標註（維護期間排程補池會跳過該商，缺口不會自動補上）
        VendorMaintenances::query()->forceCreate([
            'vendor_id' => $this->vendor->id,
            'status' => 1,
            'type' => 1,
            'start_time' => now()->subHour()->toDateTimeString(),
            'end_time' => now()->addHour()->toDateTimeString(),
            'agent_id' => 0,
            'exclude_agent_id' => 0,
            'memo' => '',
            'admin' => 0,
        ]);

        $result = $this->poolService->inventory($this->vendor->id);

        $this->assertCount(1, $result['rows'], '維護中的商不得被庫存查詢排除');
        $this->assertTrue($result['rows'][0]['maintenance'], '維護中要在列上標註');
    }

    /**
     * 註冊走 config 解析路徑的假 driver（帳號表 KissH5SWAccount），回傳 class name
     */
    private function registerFakeDriver(): string
    {
        $driverClass = get_class(new class($this->vendor->id) extends AbstractSingleWallet implements ShouldScheduleAccountCreation
        {
            protected string $accountModel = KissH5SWAccount::class;

            protected string $betRecordModel = KissH5SWBetRecord::class;

            public function getGameUrl(VendorGameUrlParamsData $params): VendorGameUrlResultData
            {
                throw new RuntimeException('not used');
            }

            public function createAccount(CreateAccountParamsData $params): CreateAccountResultData
            {
                throw new RuntimeException('監測不得補建');
            }
        });

        config()->set('vendor.driver', [$this->vendor->id => $driverClass]);

        return $driverClass;
    }

    /**
     * 建一筆未完成佔號（member_id < 0，三方建號未回成功時的狀態）
     */
    private function createPendingAccount(int $serial, ?int $vendorInfoId = null, ?string $createdAt = null): void
    {
        KissH5SWAccount::query()->forceCreate([
            'vendor_info_id' => $vendorInfoId ?? $this->vendorInfo->id,
            'username' => self::PREFIX . str_pad((string) $serial, 8, '0', STR_PAD_LEFT),
            'member_id' => -999999999,
            'status' => 1,
            'admin' => 0,
            'created_at' => $createdAt ?? now()->toDateTimeString(),
        ]);
    }

    #[Group('op3463')]
    #[TestDox('表尾連續未完成佔號：回報連續筆數、最新一筆與池水位')]
    public function test_detect_stuck_pools_reports_consecutive_pending_accounts(): void
    {
        $this->registerFakeDriver();

        $this->createAccount(1);            // 成功入池的舊列
        $this->createPendingAccount(2);
        $this->createPendingAccount(3);
        $this->createPendingAccount(4);

        $stuck = $this->poolService->detectStuckPools();

        $this->assertCount(1, $stuck);
        $this->assertSame($this->vendorInfo->id, $stuck[0]['vendor_info_id']);
        $this->assertSame(3, $stuck[0]['consecutive']);
        $this->assertSame(self::PREFIX . '00000004', $stuck[0]['latest_username'], '最新一筆應為表尾那筆');
        $this->assertSame(1, $stuck[0]['unbound'], '池水位只算 member_id = 0 的列');
        $this->assertSame(3, $stuck[0]['target']);
    }

    #[Group('op3463')]
    #[TestDox('表尾有成功列夾在中間：連續計數在該筆中斷，不把更早的失敗算進來')]
    public function test_detect_stuck_pools_stops_counting_at_a_successful_account(): void
    {
        $this->registerFakeDriver();

        $this->createPendingAccount(1);
        $this->createPendingAccount(2);
        $this->createAccount(3);            // 補建仍有成功 → 計數到此為止
        $this->createPendingAccount(4);

        $stuck = $this->poolService->detectStuckPools();

        $this->assertCount(1, $stuck);
        $this->assertSame(1, $stuck[0]['consecutive'], '只算表尾到第一筆成功之間的未完成');
    }

    #[Group('op3463')]
    #[TestDox('超出時間窗的未完成佔號不計入：池滿後久未補建的舊列不該告警')]
    public function test_detect_stuck_pools_ignores_rows_outside_the_time_window(): void
    {
        $this->registerFakeDriver();

        $this->createPendingAccount(1, createdAt: now()->subDays(3)->toDateTimeString());
        $this->createPendingAccount(2, createdAt: now()->subDays(3)->toDateTimeString());

        $this->assertSame([], $this->poolService->detectStuckPools(withinHours: 1));
    }

    #[Group('op3463')]
    #[TestDox('同一張帳號表的多個商戶各自取樣：單一商戶的連續失敗不得擠掉同表其他商戶')]
    public function test_detect_stuck_pools_samples_each_merchant_independently_on_a_shared_table(): void
    {
        $this->registerFakeDriver();

        // 聚合遊戲商共用同一張帳號表的情境（Evo 61 商、TTHSW 51 商共表）
        $noisyInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'account_prefix' => self::PREFIX . 'n',
                'prepared_account_number' => 3,
            ])
        );
        $this->vendor->load('vendorInfos');

        // 吵鬧的商戶塞滿 30 筆（超過預設取樣數 20）
        for ($i = 1; $i <= 30; $i++) {
            $this->createPendingAccount(100 + $i, vendorInfoId: $noisyInfo->id);
        }

        // 安靜的商戶只有 2 筆，若整表共用一個 LIMIT 就會被完全擠出視窗
        $this->createPendingAccount(1);
        $this->createPendingAccount(2);

        $stuck = collect($this->poolService->detectStuckPools())->keyBy('vendor_info_id');

        $this->assertCount(2, $stuck, '同表兩個商戶都要被偵測到');
        $this->assertSame(2, $stuck[$this->vendorInfo->id]['consecutive'], '安靜的商戶不得被吵鬧的擠掉');
        $this->assertSame(20, $stuck[$noisyInfo->id]['consecutive'], '連續數以取樣上限為限');
    }
}
