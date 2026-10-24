<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\OnebxSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorAccountPoolService;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\OnebxswAccount;
use App\Vendors\OnebxSW\Driver;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * OneBXSW 帳號池補建測試
 *
 * OneBXSW 無三方建號 API（純寫本地帳號池），但 DB 儲存帳號帶底線
 * （`<account_prefix>_<8 位流水號>`），流水號解析須把底線算進 prefix，
 * 否則會把 `_00000001` CAST 成 0 而每輪重建同一組帳號（同型見 PlayTechSW）。
 *
 * php artisan test tests/Unit/Vendors/OnebxSW/DriverAccountCreationTest.php --compact
 */
class DriverAccountCreationTest extends TestCase
{
    /** 測試專用帳號前綴（流水號以 LIKE 前綴解析，避免撞測試庫既有資料） */
    private const string PREFIX = 'utonebx';

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::find(VendorIdEnum::ONEBXSW->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::ONEBXSW->value, 'name' => 'OneBXSW']);

        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'status' => 1,
                'account_prefix' => self::PREFIX,
                'prepared_account_number' => 3,
            ])
        );
        $this->vendorInfo->info = ['API_URL' => 'https://api.onebxsw.test', 'MERCHANT_ID' => 'ut-merchant', 'SECRET_KEY' => 'ut-secret'];
        $this->vendorInfo->save();
        $this->vendor->load('vendorInfos');
    }

    #[TestDox('OneBXSW 的儲存帳號 prefix 帶底線，流水號才不會被解析成 0')]
    public function test_stored_account_prefix_includes_underscore_separator(): void
    {
        $driver = new Driver(VendorIdEnum::ONEBXSW->value, null);

        $this->assertSame(self::PREFIX . '_', $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    #[TestDox('補建帳號自現存最大流水號續號，不重建既有帳號，且無三方呼叫直接生效（member_id = 0）')]
    public function test_replenish_continues_from_latest_serial_instead_of_restarting(): void
    {
        OnebxswAccount::query()->create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => self::PREFIX . '_' . str_pad('7', 8, '0', STR_PAD_LEFT),
            'member_id' => 9999,
            'status' => 1,
        ]);

        $driver = new Driver(VendorIdEnum::ONEBXSW->value, null);
        $result = app(VendorAccountPoolService::class)->replenishVendor($this->vendor, $driver);

        $created = OnebxswAccount::query()
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

    #[TestDox('OneBXSW 已納入自動補建排程範圍')]
    public function test_driver_is_marked_for_scheduled_account_creation(): void
    {
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::ONEBXSW->value], ShouldScheduleAccountCreation::class, true));
    }
}
