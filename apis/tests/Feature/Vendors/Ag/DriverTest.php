<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Ag;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\AccountNotExistsException;
use App\Exceptions\ApiException;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\Encryption\DesService;
use App\Vendors\Ag\Driver;
use App\Vendors\Models\AgAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriverTest extends TestCase
{
    private const string BUSINESS_HOST = 'https://ag.test/doBusiness.do';

    private const string USERNAME = 'TPAG00000001';

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::AG->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::AG->value]);
        }

        $info = [
            'BUSINESS_HOST' => self::BUSINESS_HOST,
            'FORWARD_HOST' => 'https://ag.test/forwardGame.do',
            'AGENT' => 'GB4_TEST',
            'MD5_KEY' => str_repeat('m', 16),
            'DES_KEY' => '12345678',
            'CURRENCY' => 'MYR',
            'GAME_TYPE' => '1',
            'ODD_TYPE' => 'A',
        ];

        $vendorInfo = VendorInfo::factory()->make(['vendor_id' => VendorIdEnum::AG->value]);
        $vendorInfo->info = $info;

        $account = new AgAccount(['username' => self::USERNAME]);
        $account->created_at = Carbon::parse('2026-01-01 00:00:00');

        $this->driver = new Driver(VendorIdEnum::AG->value, null);
        $this->driver->setVendorInfo($vendorInfo);
        $this->driver->setInfo($info);
        $this->driver->setVendorAccount($account);

        // DES-ECB 在 OpenSSL 3 預設被歸到 legacy provider，測試環境加密會直接丟
        // RuntimeException，蓋掉本測試要驗的例外；這裡只在意回應怎麼解讀，加密換成 stub
        $this->app->instance(DesService::class, new class
        {
            public function init(...$args): static
            {
                return $this;
            }

            public function encrypt(string $data): string
            {
                return 'encrypted';
            }
        });
    }

    public function test_get_balance_returns_balance_from_info_attribute(): void
    {
        $this->fakeResponse('<?xml version="1.0" encoding="utf-8"?><result info="123.45" msg=""/>');

        $this->assertSame('123.45', $this->driver->getBalance());
    }

    public function test_get_balance_throws_account_not_exists_on_error_60001(): void
    {
        $this->fakeResponse('<?xml version="1.0" encoding="utf-8"?><result info="error" msg="error:60001,Account not exist with this currency value or account hierarchical error!"/>');

        $this->expectException(AccountNotExistsException::class);

        $this->driver->getBalance();
    }

    public function test_get_balance_throws_api_exception_on_other_error(): void
    {
        $this->fakeResponse('<?xml version="1.0" encoding="utf-8"?><result info="error" msg="error:10000,System busy"/>');

        $this->expectException(ApiException::class);

        $this->driver->getBalance();
    }

    private function fakeResponse(string $xml): void
    {
        Http::fake([
            'ag.test/*' => Http::response($xml, 200, ['Content-Type' => 'application/xml']),
        ]);
    }
}
