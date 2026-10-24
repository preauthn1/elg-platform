<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\JokerBaccaratSW;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\VendorGameUrlParamsData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\JokerBaccaratSW\Driver;
use App\Vendors\Models\JkbswAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DriverTest extends TestCase
{
    private const string GAME_CODE = 'jkbsw';

    private const string API_URL = 'https://example.invalid';

    private const string API_ID = 'test-api-id';

    private const string API_TOKEN = [REDACTED]';

    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::JOKER_BACCARAT_SW->value);
        $this->vendor->save();

        // 對齊 Driver/Controller 的 GAME_CODE 常數，避免 BetHelper 等流程找不到 game
        $this->vendor->games()->first()->update(['game_type' => self::GAME_CODE]);

        $this->vendorInfo = $this->vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'JKB_';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'API_ID' => self::API_ID,
            'API_TOKEN' => self::API_TOKEN,
            'CURRENCY' => 'USD',
        ];
        $this->vendorInfo->save();
    }

    public function test_create_account_persists_local_record_and_calls_register(): void
    {
        Http::fake([
            '*/merchant/player/register' => Http::response([
                'status' => true,
                'message' => 'ok',
                'data' => [
                    'playerID' => 'JKB_TEST001',
                    'playerToken' => 'token-abc',
                    'balance' => 0,
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 1,
            'username' => 'JKB_TEST001',
        ]));

        $this->assertTrue($result->status);
        $this->assertSame('JKB_TEST001', $result->username);

        $account = JkbswAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'JKB_TEST001')
            ->first();

        $this->assertNotNull($account, '本地 jkbsw_accounts 必須建立 row');
        $this->assertSame(0, (int) $account->member_id, '尚未綁定會員時 member_id 應為 0');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/merchant/player/register'));
    }

    public function test_create_account_deletes_row_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/merchant/player/register' => Http::response([
                'status' => false,
                'message' => 'duplicate playerID',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 2,
            'username' => 'JKB_TEST_FAIL',
        ]));

        $this->assertFalse($result->status);

        // 三方失敗時必須直接刪 row：留下 raw username 會擋下次排程序號（撞 uniq_jkbsw_username）。
        // 審計痕跡靠 Log::error('sendRequest failed', ...) 留存即可。
        $this->assertFalse(
            JkbswAccount::where('vendor_info_id', $this->vendorInfo->id)
                ->where('username', 'JKB_TEST_FAIL')
                ->exists(),
            '三方註冊失敗時 row 必須被刪除，避免阻塞排程序號'
        );
    }

    public function test_create_account_updates_username_when_vendor_returns_prefixed(): void
    {
        Http::fake([
            '*/merchant/player/register' => Http::response([
                'status' => true,
                'message' => 'ok',
                'data' => [
                    'playerID' => 'joker_X',
                    'playerToken' => 'token-xyz',
                    'balance' => 0,
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 3,
            'username' => 'X',
        ]));

        $this->assertTrue($result->status);
        $this->assertSame('joker_X', $result->username);

        // 原始 username='X' 應被覆寫為三方回傳值
        $this->assertFalse(
            JkbswAccount::where('vendor_info_id', $this->vendorInfo->id)
                ->where('username', 'X')
                ->exists(),
            '原始 username 應被改寫'
        );

        $account = JkbswAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'joker_X')
            ->first();

        $this->assertNotNull($account, '更新後 username 應為三方回傳的 playerID');
    }

    public function test_get_game_url_returns_lobby_url(): void
    {
        $lobbyUrl = 'https://example.invalid/lobby/abc';
        Http::fake([
            '*/merchant/game/open-lobby' => Http::response([
                'status' => true,
                'message' => 'ok',
                'data' => $lobbyUrl,
            ]),
        ]);

        $this->prepareBoundAccount('JKB_PLAYER_OK');

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value, $this->member);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => self::GAME_CODE,
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
        ]));

        $this->assertSame($lobbyUrl, $result->pc);
        $this->assertSame($lobbyUrl, $result->mobile);

        // 驗證 line 101 sendRequest 真有讀到 $this->infoDetail->API_URL（完整 URL 必須以 API_URL 起始）
        Http::assertSent(fn ($request) => $request->url() === self::API_URL . '/merchant/game/open-lobby');
    }

    public function test_get_game_url_throws_api_exception_on_vendor_error(): void
    {
        Http::fake([
            '*/merchant/game/open-lobby' => Http::response([
                'status' => false,
                'message' => 'player not found',
            ]),
        ]);

        $this->prepareBoundAccount('JKB_PLAYER_ERR');

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value, $this->member);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('player not found');

        $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => self::GAME_CODE,
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
        ]));
    }

    public function test_get_game_url_throws_when_url_empty(): void
    {
        Http::fake([
            '*/merchant/game/open-lobby' => Http::response([
                'status' => true,
                'message' => 'ok',
                'data' => '',
            ]),
        ]);

        $this->prepareBoundAccount('JKB_PLAYER_EMPTY');

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value, $this->member);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('empty URL');

        $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => self::GAME_CODE,
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
        ]));
    }

    /**
     * 回歸測試：getStoredAccountPrefix 必須從既有帳號的「實際 username」反推前綴，
     * 而非用 API_ID 推導。
     *
     * 線上事故：API_ID=jbtpower、Joker 回填前綴=JBT，舊邏輯回 'jbtpower' 導致
     * getLatestAccountNumber LIKE 撈不到 row、序號歸零、重撞 Joker 既有 playerID 死循環。
     */
    public function test_stored_account_prefix_is_derived_from_username_not_apiid(): void
    {
        $this->vendorInfo->account_prefix = '';
        $this->vendorInfo->save();

        $this->createUnboundAccount('JBT00000001');
        $this->createUnboundAccount('JBT00000014');

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value);

        // 三方回填前綴是 JBT，不是 API_ID（test-api-id）
        $this->assertSame('JBT', $driver->getStoredAccountPrefix($this->vendorInfo));
        $this->assertStringNotContainsString(self::API_ID, $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    /**
     * 前綴由資料決定，不同 merchant（portal 設定不同前綴）都能正確反推。
     */
    public function test_stored_account_prefix_is_data_driven_per_merchant(): void
    {
        $this->vendorInfo->account_prefix = '';
        $this->vendorInfo->save();

        $this->createUnboundAccount('ABC00000005');

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value);

        $this->assertSame('ABC', $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    /**
     * 反推時必須略過「尚未被三方回填」的 transient bare row（純 8 碼序號、長度=8），
     * 即使它是最新一筆。
     */
    public function test_stored_account_prefix_skips_transient_bare_row(): void
    {
        $this->vendorInfo->account_prefix = '';
        $this->vendorInfo->save();

        $this->createUnboundAccount('JBT00000002'); // 較舊、已回填
        $this->createUnboundAccount('00000099');    // 較新、transient bare（建立中尚未回填）

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value);

        $this->assertSame('JBT', $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    /**
     * 帳號表為空（首次建帳）時無從反推，退回 account_prefix；
     * 此時尚無 row 需被 LIKE 命中，getLatestAccountNumber 本就回 0。
     */
    public function test_stored_account_prefix_falls_back_to_account_prefix_when_empty(): void
    {
        $this->vendorInfo->account_prefix = 'FALLBACK_';
        $this->vendorInfo->save();

        $driver = new Driver(VendorIdEnum::JOKER_BACCARAT_SW->value);

        $this->assertSame('FALLBACK_', $driver->getStoredAccountPrefix($this->vendorInfo));
    }

    /**
     * 建立未綁定 member 的 JkbswAccount（帳號池中的可用帳號）。
     */
    private function createUnboundAccount(string $username): JkbswAccount
    {
        return JkbswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => 0,
            'username' => $username,
            'password' => 'fake-password-12',
            'status' => 1,
        ]);
    }

    /**
     * 預先建立已綁定 member 的 JkbswAccount，讓 Driver 建構時可直接取得 vendorAccount。
     */
    private function prepareBoundAccount(string $username): JkbswAccount
    {
        return JkbswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'username' => $username,
            'password' => 'fake-password-12',
            'status' => 1,
        ]);
    }
}
