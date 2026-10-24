<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Ia;

use App\Enums\Vendor\VendorIdEnum;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Ia\Driver;
use App\Vendors\Models\IaAccount;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriverTest extends TestCase
{
    private const string API_URL = 'https://iaapi.test/api';

    private const string USERNAME = 'ELGIA00000475';

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::IA->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::IA->value]);
        }

        $info = [
            'API_URL' => self::API_URL,
            'API_KEY' => str_repeat('k', 32),
            'API_IV' => str_repeat('i', 16),
            'API_PCH' => '8888',
            'CURRENCY' => 'MYR',
        ];

        $vendorInfo = VendorInfo::factory()->make(['vendor_id' => VendorIdEnum::IA->value]);
        $vendorInfo->info = $info;

        $this->driver = new Driver(VendorIdEnum::IA->value, null);
        $this->driver->setVendorInfo($vendorInfo);
        $this->driver->setInfo($info);
        $this->driver->setVendorAccount(new IaAccount(['username' => self::USERNAME]));
    }

    public function test_get_balance_registers_account_on_account_not_exists_and_retries(): void
    {
        Http::fake([
            self::API_URL . '/user/balance' => Http::sequence()
                ->push(['code' => 1501, 'data' => [], 'msg' => '用戶不存在@#@'])
                ->push(['code' => 1, 'data' => ['data' => '1999.94', 'status' => 1], 'msg' => '操作成功']),
            self::API_URL . '/user/register' => Http::response(['code' => 1, 'data' => [], 'msg' => '操作成功']),
        ]);

        $this->assertSame('1999.9400', $this->driver->getBalance());

        Http::assertSent(fn ($request) => $request->url() === self::API_URL . '/user/register');
    }

    public function test_get_balance_does_not_register_account_on_other_errors(): void
    {
        Http::fake([
            self::API_URL . '/user/balance' => Http::response(['code' => 1002, 'data' => [], 'msg' => '簽名錯誤']),
        ]);

        $this->expectExceptionMessage('[IA][GetBalance] response wrong code');

        try {
            $this->driver->getBalance();
        } finally {
            Http::assertNotSent(fn ($request) => $request->url() === self::API_URL . '/user/register');
        }
    }
}
