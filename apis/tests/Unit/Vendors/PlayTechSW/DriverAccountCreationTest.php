<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\PlayTechSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorAccountPoolService;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\PtswAccount;
use App\Vendors\PlayTechSW\Driver;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * PlayTechSW 帳號池補建測試
 *
 * PTSW 的帳號格式是 `<account_prefix>_<8 位流水號>`，與多數遊戲商的「前綴直接接流水號」
 * 不同；流水號解析若不把底線算進 prefix，會把 `_00000001` CAST 成 0 而每輪重建同一組帳號。
 *
 * php artisan test tests/Unit/Vendors/PlayTechSW/DriverAccountCreationTest.php --compact
 */
class DriverAccountCreationTest extends TestCase
{
    /** 測試專用帳號前綴（流水號以 LIKE 前綴解析，避免撞測試庫既有資料） */
    private const string PREFIX = 'utptsw';

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::find(VendorIdEnum::PTSW->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::PTSW->value, 'name' => 'PlayTech SW']);

        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'status' => 1,
                'account_prefix' => self::PREFIX,
                'prepared_account_number' => 3,
            ])
        );
        $this->vendorInfo->info = ['SERVER_NAME' => 'utptsw', 'KIOSK_KEY' => 'ut-kiosk-key'];
        $this->vendorInfo->save();
        $this->vendor->load('vendorInfos');
    }

    #[TestDox('PTSW 的儲存帳號 prefix 帶底線，流水號才不會被解析成 0')]
    public function test_stored_account_prefix_includes_underscore_separator(): void
    {
        $driver = new Driver(VendorIdEnum::PTSW->value, null);

        $this->assertSame(self::PREFIX . '_', $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    #[TestDox('補建帳號自現存最大流水號續號，不重建既有帳號')]
    public function test_replenish_continues_from_latest_serial_instead_of_restarting(): void
    {
        PtswAccount::query()->create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => self::PREFIX . '_' . str_pad('7', 8, '0', STR_PAD_LEFT),
            'token' => self::PREFIX . '_token',
            'member_id' => 9999,
            'status' => 1,
        ]);

        $driver = new Driver(VendorIdEnum::PTSW->value, null);
        $result = app(VendorAccountPoolService::class)->replenishVendor($this->vendor, $driver);

        $created = PtswAccount::query()
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

    #[TestDox('PTSW 已納入自動補建排程範圍')]
    public function test_driver_is_marked_for_scheduled_account_creation(): void
    {
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::PTSW->value], ShouldScheduleAccountCreation::class, true));
    }
}
