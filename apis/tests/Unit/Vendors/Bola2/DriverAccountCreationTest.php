<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\Bola2;

use App\Data\Vendor\CreateAccountParamsData;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\VendorAccountPoolService;
use App\Vendors\Bola2\Driver;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Models\Bola2Account;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * Bola2 帳號池補建測試
 *
 * 舊系統 RD-Studio `create_bola2_account` 排程本來就已經下線（Kernel.php 裡是註解狀態），
 * 三站 vendors.status 也都是 0，目前沒有實際使用需求；這次搬遷純粹是把建帳號能力補上
 * 並保留在 disabled_vendors.php，等哪天真的要用再移出清單即可。
 *
 * php artisan test tests/Unit/Vendors/Bola2/DriverAccountCreationTest.php --compact
 */
class DriverAccountCreationTest extends TestCase
{
    private const string PREFIX = 'utbola';

    private const string API_URL = 'https://api.bola2.test';

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = Vendor::find(VendorIdEnum::BOLA->value)
            ?? Vendor::factory()->create(['id' => VendorIdEnum::BOLA->value, 'name' => 'Bola2']);

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
            'AGENT_NAME' => 'ut-agent',
            'API_KEY' => 'ut-secret-key',
        ];
        $this->vendorInfo->save();
        $this->vendor->load('vendorInfos');
    }

    #[TestDox('Bola2 帳號 prefix 不帶底線，沿用預設 getStoredAccountPrefix()')]
    public function test_stored_account_prefix_has_no_underscore(): void
    {
        $driver = new Driver(VendorIdEnum::BOLA->value, null);

        $this->assertSame(self::PREFIX, $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    #[TestDox('createAccount 成功時以 AgentName/Random/HashKey 呼叫 user/signup，帳號翻成可綁定（member_id = 0）')]
    public function test_create_account_marks_account_bindable_when_vendor_api_succeeds(): void
    {
        Http::fake([
            '*/user/signup' => Http::response(['Status' => 201]),
        ]);

        $driver = new Driver(VendorIdEnum::BOLA->value, null);
        $username = self::PREFIX . '00000001';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 0,
            username: $username,
        ));

        $this->assertTrue($result->status);
        $this->assertSame($username, $result->username);

        $account = Bola2Account::query()->where('username', $username)->firstOrFail();
        $this->assertSame(0, $account->member_id);
        $this->assertNotEmpty($account->password);

        Http::assertSent(function ($request) use ($username) {
            return str_contains($request->url(), '/user/signup')
                && $request->method() === 'POST'
                && $request['Username'] === $username
                && $request['AgentName'] === 'ut-agent'
                && isset($request['HashKey']);
        });
    }

    #[TestDox('三方回應 Status 非 201 時回報失敗，帳號留在 member_id = -999999999 不進帳號池')]
    public function test_create_account_keeps_account_out_of_pool_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/user/signup' => Http::response(['Status' => 400, 'message' => 'invalid parameter']),
        ]);

        $driver = new Driver(VendorIdEnum::BOLA->value, null);
        $username = self::PREFIX . '00000002';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 1,
            username: $username,
        ));

        $this->assertFalse($result->status);

        $account = Bola2Account::query()->where('username', $username)->firstOrFail();
        $this->assertSame(-999999999, $account->member_id);
    }

    #[TestDox('補建帳號自現存最大流水號續號，不會重建既有帳號')]
    public function test_replenish_continues_from_latest_serial_instead_of_restarting(): void
    {
        Http::fake([
            '*/user/signup' => Http::response(['Status' => 201]),
        ]);

        Bola2Account::query()->create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => self::PREFIX . str_pad('7', 8, '0', STR_PAD_LEFT),
            'password' => 'ut-password',
            'member_id' => 9999,
        ]);

        $driver = new Driver(VendorIdEnum::BOLA->value, null);
        app(VendorAccountPoolService::class)->replenishVendor($this->vendor, $driver);

        $created = Bola2Account::query()
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

    #[TestDox('Bola2 已具備自動補建排程能力，但仍列在 disabled_vendors.php 暫不啟用')]
    public function test_driver_has_capability_but_stays_disabled_pending_real_usage(): void
    {
        $this->assertTrue(is_a(config('vendor.driver')[VendorIdEnum::BOLA->value], ShouldScheduleAccountCreation::class, true));

        $disabledVendorIds = require database_path('data/disabled_vendors.php');
        $this->assertContains(VendorIdEnum::BOLA->value, $disabledVendorIds, 'Bola2 目前無實際使用需求，應維持在停用清單內');
    }
}
