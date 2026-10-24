<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\WudiSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorAccountPoolService;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\WudiswAccount;
use App\Vendors\WudiSW\Driver;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * WUDISW(170) / WUDISW_FREE(223) 帳號池補建測試——兩者共用同一支 Driver，
 * 帳號池以 vendor_info_id 區隔。無三方建號 API（純寫本地帳號池），但 DB 儲存帳號
 * 帶底線（`<account_prefix>_<8 位流水號>`），流水號解析須把底線算進 prefix，
 * 否則會把 `_00000001` CAST 成 0 而每輪重建同一組帳號（同型見 PlayTechSW）。
 *
 * php artisan test tests/Unit/Vendors/WudiSW/DriverAccountCreationTest.php --compact
 */
class DriverAccountCreationTest extends TestCase
{
    private const string PREFIX = 'utwudi';

    private const string FREE_PREFIX = 'utwudifree';

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    private Vendor $freeVendor;

    private VendorInfo $freeVendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::find(VendorIdEnum::WUDISW->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::WUDISW->value, 'name' => 'WUDI SW']);
        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'status' => 1,
                'account_prefix' => self::PREFIX,
                'prepared_account_number' => 3,
            ])
        );
        $this->vendorInfo->info = ['API_URL' => 'https://api.wudisw.test', 'ACCOUNT_ID' => 'ut-account', 'MD5_KEY' => 'ut-md5', 'SECURITY_CODE' => 'ut-code'];
        $this->vendorInfo->save();
        $this->vendor->load('vendorInfos');

        $this->freeVendor = Vendor::find(VendorIdEnum::WUDISW_FREE->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::WUDISW_FREE->value, 'name' => 'WUDI SW Free']);
        $this->freeVendorInfo = $this->freeVendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'status' => 1,
                'account_prefix' => self::FREE_PREFIX,
                'prepared_account_number' => 2,
            ])
        );
        $this->freeVendorInfo->info = $this->vendorInfo->info;
        $this->freeVendorInfo->save();
        $this->freeVendor->load('vendorInfos');
    }

    #[TestDox('WudiSW 的儲存帳號 prefix 帶底線，流水號才不會被解析成 0')]
    public function test_stored_account_prefix_includes_underscore_separator(): void
    {
        $driver = new Driver(VendorIdEnum::WUDISW->value, null);

        $this->assertSame(self::PREFIX . '_', $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    #[TestDox('補建帳號自現存最大流水號續號，不重建既有帳號，且無三方呼叫直接生效（member_id = 0）')]
    public function test_replenish_continues_from_latest_serial_instead_of_restarting(): void
    {
        WudiswAccount::query()->create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => self::PREFIX . '_' . str_pad('7', 8, '0', STR_PAD_LEFT),
            'member_id' => 9999,
        ]);

        $driver = new Driver(VendorIdEnum::WUDISW->value, null);
        $result = app(VendorAccountPoolService::class)->replenishVendor($this->vendor, $driver);

        $created = WudiswAccount::query()
            ->where('vendor_info_id', $this->vendorInfo->id)
            ->where('member_id', 0)
            ->orderBy('username')
            ->pluck('username')
            ->all();

        $this->assertSame([
            self::PREFIX . '_00000008',
            self::PREFIX . '_00000009',
            self::PREFIX . '_00000010',
        ], $created);
        $this->assertSame([], $result->merchantResults[0]->failedUsernames);
    }

    #[TestDox('170 (WUDISW) 與 223 (WUDISW_FREE) 共用同一支 Driver，帳號池以 vendor_info_id 各自獨立不互撞')]
    public function test_shared_driver_replenishes_independent_pools_for_wudisw_and_wudisw_free(): void
    {
        $wudiDriver = new Driver(VendorIdEnum::WUDISW->value, null);
        app(VendorAccountPoolService::class)->replenishVendor($this->vendor, $wudiDriver);

        $freeDriver = new Driver(VendorIdEnum::WUDISW_FREE->value, null);
        app(VendorAccountPoolService::class)->replenishVendor($this->freeVendor, $freeDriver);

        $wudiCreated = WudiswAccount::query()->where('vendor_info_id', $this->vendorInfo->id)->count();
        $freeCreated = WudiswAccount::query()->where('vendor_info_id', $this->freeVendorInfo->id)->count();

        $this->assertSame(3, $wudiCreated);
        $this->assertSame(2, $freeCreated);

        $freeUsernames = WudiswAccount::query()
            ->where('vendor_info_id', $this->freeVendorInfo->id)
            ->pluck('username')
            ->all();
        foreach ($freeUsernames as $username) {
            $this->assertStringStartsWith(self::FREE_PREFIX . '_', $username);
        }
    }

    #[TestDox('WUDISW 與 WUDISW_FREE 皆已納入自動補建排程範圍')]
    public function test_driver_is_marked_for_scheduled_account_creation(): void
    {
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::WUDISW->value], ShouldScheduleAccountCreation::class, true));
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::WUDISW_FREE->value], ShouldScheduleAccountCreation::class, true));
    }
}
