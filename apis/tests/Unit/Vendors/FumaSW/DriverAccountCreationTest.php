<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\FumaSW;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorAccountPoolService;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\FumaSW\Driver;
use App\Vendors\Models\FumaswAccount;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * FumaSW 帳號池補建迴歸測試
 *
 * DB 儲存帳號帶底線（`<account_prefix>_<8 位流水號>`），Driver 先前未覆寫
 * getStoredAccountPrefix()，流水號解析把底線後字串 CAST 成 0，導致每輪重建
 * 出格式錯誤（無底線）的帳號，與 RD-Studio 舊排程仍在跑同一個池時尤其明顯
 * （見 vendor_info 444 實際產出的 `E2fumasw00000001`）。此檔為修復後的迴歸測試。
 *
 * php artisan test tests/Unit/Vendors/FumaSW/DriverAccountCreationTest.php --compact
 */
class DriverAccountCreationTest extends TestCase
{
    private const string PREFIX = 'utfumasw';

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::find(VendorIdEnum::FUMASW->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::FUMASW->value, 'name' => 'FumaSW']);

        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'status' => 1,
                'account_prefix' => self::PREFIX,
                'prepared_account_number' => 3,
            ])
        );
        $this->vendorInfo->info = ['API_URL' => 'https://api.fumasw.test', 'PARENT' => 'ut-parent', 'DC' => 'ut-dc', 'KEY' => str_repeat('a', 16), 'IV' => str_repeat('b', 16)];
        $this->vendorInfo->save();
        $this->vendor->load('vendorInfos');
    }

    #[TestDox('FumaSW 的儲存帳號 prefix 帶底線，流水號才不會被解析成 0')]
    public function test_stored_account_prefix_includes_underscore_separator(): void
    {
        $driver = new Driver(VendorIdEnum::FUMASW->value, null);

        $this->assertSame(self::PREFIX . '_', $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    #[TestDox('補建帳號自現存最大流水號續號，不會因底線解析錯誤重建出格式不一致的帳號')]
    public function test_replenish_continues_from_latest_serial_instead_of_restarting(): void
    {
        FumaswAccount::query()->create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => self::PREFIX . '_' . str_pad('9', 8, '0', STR_PAD_LEFT),
            'member_id' => 8888,
            'status' => 1,
        ]);

        $driver = new Driver(VendorIdEnum::FUMASW->value, null);
        $result = app(VendorAccountPoolService::class)->replenishVendor($this->vendor, $driver);

        $created = FumaswAccount::query()
            ->where('vendor_info_id', $this->vendorInfo->id)
            ->where('member_id', 0)
            ->orderBy('username')
            ->pluck('username')
            ->all();

        $this->assertSame([
            self::PREFIX . '_00000010',
            self::PREFIX . '_00000011',
            self::PREFIX . '_00000012',
        ], $created);
        $this->assertSame([], $result->merchantResults[0]->failedUsernames);
    }

    #[TestDox('FumaSW 已納入自動補建排程範圍')]
    public function test_driver_is_marked_for_scheduled_account_creation(): void
    {
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::FUMASW->value], ShouldScheduleAccountCreation::class, true));
    }
}
