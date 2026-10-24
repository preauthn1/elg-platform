<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\PPSW;

use App\Data\Vendor\CreateAccountParamsData;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorAccountPoolService;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\PpswAccount;
use App\Vendors\PPSW\Driver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * PPSW 帳號池補建測試
 *
 * 原本的 createAccount() 直接用 $params->serialNumber（未 +1 的舊流水號）組 username，
 * 會跟現有最大流水號的帳號撞成一樣的 username；ppsw_accounts.username 只有一般 index
 * 非 unique，不會報錯而是無聲產生重複列，流水號永遠卡在同一個數字。此檔為修復後的
 * 迴歸測試，改用 VendorAccountPoolService 已組好（含 +1）的 $params->username。
 *
 * php artisan test tests/Unit/Vendors/PPSW/DriverAccountCreationTest.php --compact
 */
class DriverAccountCreationTest extends TestCase
{
    private const string PREFIX = 'utppsw';

    private const string API_URL = 'https://api.ppsw.test';

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::find(VendorIdEnum::PPSW->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::PPSW->value, 'name' => 'Pragmatic Play SW']);

        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'status' => 1,
                'account_prefix' => self::PREFIX,
                'prepared_account_number' => 3,
            ])
        );
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'SECURE_LOGIN' => 'ut-secure-login',
            'SECRET_KEY' => 'ut-secret-key',
            'CURRENCY' => 'MYR',
            'COUNTRY' => 'MY',
        ];
        $this->vendorInfo->save();
        $this->vendor->load('vendorInfos');
    }

    #[TestDox('PPSW 帳號 prefix 不帶底線，沿用預設 getStoredAccountPrefix()')]
    public function test_stored_account_prefix_has_no_underscore(): void
    {
        $driver = new Driver(VendorIdEnum::PPSW->value, null);

        $this->assertSame(self::PREFIX, $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    #[TestDox('createAccount 使用 VendorAccountPoolService 組好的 username（含 +1），不會撞出跟既有帳號相同的流水號')]
    public function test_create_account_uses_pool_service_username_not_stale_serial_number(): void
    {
        Http::fake([
            '*/player/account/create/*' => Http::response(['error' => '0', 'playerId' => 900001]),
        ]);

        $driver = new Driver(VendorIdEnum::PPSW->value, null);
        $username = self::PREFIX . '00000005';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 4,
            username: $username,
        ));

        $this->assertTrue($result->status);
        $this->assertSame($username, $result->username);

        $account = PpswAccount::query()->where('username', $username)->firstOrFail();
        $this->assertSame(0, $account->member_id);
        $this->assertSame(900001, $account->player_id);
    }

    #[TestDox('補建帳號自現存最大流水號續號，不會重複產生同一個 username')]
    public function test_replenish_continues_from_latest_serial_instead_of_restarting(): void
    {
        Http::fake([
            '*/player/account/create/*' => Http::response(['error' => '0', 'playerId' => 900002]),
        ]);

        PpswAccount::query()->create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => self::PREFIX . str_pad('7', 8, '0', STR_PAD_LEFT),
            'currency' => 'MYR',
            'country' => 'MY',
            'member_id' => 9999,
        ]);

        $driver = new Driver(VendorIdEnum::PPSW->value, null);
        app(VendorAccountPoolService::class)->replenishVendor($this->vendor, $driver);

        $created = PpswAccount::query()
            ->where('vendor_info_id', $this->vendorInfo->id)
            ->where('member_id', 0)
            ->orderBy('username')
            ->pluck('username')
            ->all();

        $this->assertSame([
            self::PREFIX . '00000008',
            self::PREFIX . '00000009',
            self::PREFIX . '00000010',
        ], $created);
    }

    #[TestDox('三方回應 error 非 0 時回報失敗，帳號留在 member_id = -999999999 不進帳號池')]
    public function test_create_account_keeps_account_out_of_pool_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/player/account/create/*' => Http::response(['error' => '1001']),
        ]);

        $driver = new Driver(VendorIdEnum::PPSW->value, null);
        $username = self::PREFIX . '00000006';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 5,
            username: $username,
        ));

        $this->assertFalse($result->status);

        $account = PpswAccount::query()->where('username', $username)->firstOrFail();
        $this->assertSame(-999999999, $account->member_id);
    }

    #[TestDox('PPSW 已納入自動補建排程範圍')]
    public function test_driver_is_marked_for_scheduled_account_creation(): void
    {
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::PPSW->value], ShouldScheduleAccountCreation::class, true));

        $disabledVendorIds = require database_path('data/disabled_vendors.php');
        $this->assertNotContains(VendorIdEnum::PPSW->value, $disabledVendorIds, 'PPSW 仍列於停用清單，補建範圍會漏掉它');
    }
}
