<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\PPPOP;

use App\Data\Vendor\CreateAccountParamsData;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Models\PpAccount;
use App\Vendors\PPPOP\Driver;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.prerelease-env.biz/IntegrationService/v3/http/CasinoGameAPI';

    private const string SECURE_LOGIN = 'pppop-test-login';

    private const string SECRET_KEY = [REDACTED]';

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::PPPOP->value);
        $this->vendor->save();

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'PPPOP_';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'SECURELOGIN' => self::SECURE_LOGIN,
            'SECRETKEY' => self::SECRET_KEY,
        ];
        $this->vendorInfo->save();
    }

    public function test_create_account_persists_local_record_and_calls_create_player(): void
    {
        Http::fake([
            '*/player/account/create/' => Http::response([
                'error' => '0',
                'description' => 'OK',
                'playerId' => 6749178,
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::PPPOP->value, null);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 1,
            'username' => 'PPPOP_TEST001',
        ]));

        $this->assertTrue($result->status);
        $this->assertSame('PPPOP_TEST001', $result->username);

        $account = PpAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'PPPOP_TEST001')
            ->first();

        $this->assertNotNull($account, '本地 pp_accounts 必須建立 row');
        $this->assertSame(0, (int) $account->member_id, '尚未綁定會員時 member_id 應為 0');
        $this->assertSame('6749178', $account->player_id, '三方回傳的 playerId 應寫回 player_id');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/player/account/create/'));
    }

    public function test_create_account_soft_deletes_row_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/player/account/create/' => Http::response([
                'error' => '5',
                'description' => 'Invalid hash code',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::PPPOP->value, null);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 2,
            'username' => 'PPPOP_TEST_FAIL',
        ]));

        $this->assertFalse($result->status);

        // 三方建立失敗時採軟刪除（member_id = -999999999），不硬刪 row，
        // 避免帳號池污染同時保留審計痕跡（同 AFBSW 慣例）。
        $account = PpAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'PPPOP_TEST_FAIL')
            ->first();

        $this->assertNotNull($account, '三方失敗時本地 row 應保留（軟刪除，非硬刪）');
        $this->assertSame(-999999999, (int) $account->member_id);
    }

    public function test_create_account_returns_false_when_response_is_not_array(): void
    {
        Http::fake([
            '*/player/account/create/' => Http::response('not-json', 200),
        ]);

        $driver = new Driver(VendorIdEnum::PPPOP->value, null);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 3,
            'username' => 'PPPOP_TEST_BADRESP',
        ]));

        $this->assertFalse($result->status);

        $account = PpAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'PPPOP_TEST_BADRESP')
            ->first();

        $this->assertNotNull($account);
        $this->assertSame(-999999999, (int) $account->member_id);
    }
}
