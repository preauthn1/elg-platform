<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\VplusSW;

use App\Data\Vendor\CreateAccountParamsData;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\VplusswAccount;
use App\Vendors\VplusSW\Driver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * VplusSW 帳號池補建測試——三方有建號 API（POST /createAccount），
 * 兩段式 member_id：先落 DB 佔流水號（-999999999），三方成功才翻回 0 並存 ext_uid。
 * 帳號 prefix 不帶底線（沿用 VendorAccountPoolService 預設 getStoredAccountPrefix()）。
 *
 * php artisan test tests/Unit/Vendors/VplusSW/DriverAccountCreationTest.php --compact
 */
class DriverAccountCreationTest extends TestCase
{
    private const string PREFIX = 'utvplus';

    private const string API_URL = 'https://api.vplussw.test';

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::find(VendorIdEnum::VPLUSSW->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::VPLUSSW->value, 'name' => 'VPLUS SW']);

        $this->vendorInfo = $this->vendor->vendorInfos()->save(
            VendorInfo::factory()->make([
                'admin' => 0,
                'status' => 1,
                'account_prefix' => self::PREFIX,
                'prepared_account_number' => 1,
            ])
        );
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'GAME_LAUNCH_URL' => 'https://game.vplussw.test/launch',
            'APP_ID' => 'ut-app-id',
            'APP_SECRET' => 'ut-app-secret',
        ];
        $this->vendorInfo->save();
        $this->vendor->load('vendorInfos');
    }

    #[TestDox('createAccount 成功（code=200）時存下三方 uid，帳號翻成可綁定（member_id = 0）')]
    public function test_create_account_stores_ext_uid_when_vendor_api_succeeds(): void
    {
        Http::fake([
            '*/createAccount' => Http::response(['code' => 200, 'msg' => '', 'data' => ['uid' => 'vplus-uid-001']]),
        ]);

        $driver = new Driver(VendorIdEnum::VPLUSSW->value, null);
        $username = self::PREFIX . '00000001';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 0,
            username: $username,
        ));

        $this->assertTrue($result->status);
        $this->assertSame($username, $result->username);

        $account = VplusswAccount::query()->where('username', $username)->firstOrFail();
        $this->assertSame(0, $account->member_id);
        $this->assertSame('vplus-uid-001', $account->ext_uid);

        Http::assertSent(function ($request) use ($username) {
            return str_contains($request->url(), '/createAccount')
                && $request->method() === 'POST'
                && $request['username'] === $username
                && $request['appId'] === 'ut-app-id';
        });
    }

    #[TestDox('三方回傳 code=12「user already exist」視為成功，沿用舊系統判斷')]
    public function test_create_account_treats_already_exists_as_success(): void
    {
        Http::fake([
            '*/createAccount' => Http::response(['code' => 12, 'msg' => 'user already exist', 'data' => ['uid' => 'vplus-uid-existing']]),
        ]);

        $driver = new Driver(VendorIdEnum::VPLUSSW->value, null);
        $username = self::PREFIX . '00000002';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 1,
            username: $username,
        ));

        $this->assertTrue($result->status);

        $account = VplusswAccount::query()->where('username', $username)->firstOrFail();
        $this->assertSame(0, $account->member_id);
    }

    #[TestDox('三方回應非 200 且非已存在時回報失敗，帳號留在 member_id = -999999999 不進帳號池')]
    public function test_create_account_keeps_account_out_of_pool_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/createAccount' => Http::response(['code' => 9999, 'msg' => 'invalid signature']),
        ]);

        $driver = new Driver(VendorIdEnum::VPLUSSW->value, null);
        $username = self::PREFIX . '00000003';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 2,
            username: $username,
        ));

        $this->assertFalse($result->status);

        $account = VplusswAccount::query()->where('username', $username)->firstOrFail();
        $this->assertSame(-999999999, $account->member_id);
    }

    #[TestDox('VPLUS SW 已納入自動補建排程範圍')]
    public function test_driver_is_marked_for_scheduled_account_creation(): void
    {
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::VPLUSSW->value], ShouldScheduleAccountCreation::class, true));
    }
}
