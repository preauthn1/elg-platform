<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\EventVendor;
use App\Models\EventVendorInfo;
use App\Models\Game;
use App\Models\Member;
use App\Models\Vendor;
use App\Services\RefreshRankingService;
use App\Vendors\Models\EventPgsswAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * RefreshRankingService::getGameUrl 測試（event/refresh_ranking/game_url，tpower 獨有）
 *
 * 覆蓋範圍限 PGSSW（VendorIdEnum::PGSSW，不覆寫建構子的最簡單案例）；UUSlotSW／VpowerSW
 * 兩支需要覆寫 useLegacyEventContext() 轉存私有屬性的路徑另見
 * tests/Feature/Vendors/UUSlotSW/EventGameUrlTest.php。
 *
 * php artisan test tests/Unit/Services/RefreshRankingGameUrlTest.php
 */
class RefreshRankingGameUrlTest extends TestCase
{
    private const string API_URL = 'https://example.invalid';

    private RefreshRankingService $service;

    private Agent $agent;

    private Member $member;

    private Vendor $vendor;

    private EventVendorInfo $eventVendorInfo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RefreshRankingService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);

        $this->vendor = Vendor::factory()->create([
            'id' => VendorIdEnum::PGSSW->value,
            // 活動帳號表以 third_party_code 對應，factory 給的是隨機值，必須指定
            'third_party_code' => 'PGSSW',
            'status' => 1,
            'is_single_wallet' => 1,
            // 刻意與 games.open_window（factory 給 0）不同，供 open_window 取錯表的回歸斷言用
            'open_window' => 1,
        ]);

        $this->eventVendorInfo = EventVendorInfo::create([
            'name' => 'PGSSW Event',
            'vendor_id' => $this->vendor->id,
            'currency' => 'CNY',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            // 傳陣列讓 Eloquent 的 array cast 負責 json_encode；傳已編碼字串會被雙重編碼，
            // 讀出時 info() accessor 只解一層，回傳的還是字串而非陣列
            'info' => [
                'API_URL' => self::API_URL,
                'API_APPID' => 'test-app-id',
                'API_KEY' => 'test-api-key',
            ],
            'prepared_account_number' => 1,
            'account_prefix' => 'evt',
            'status' => 1,
            'admin' => 0,
        ]);

        EventVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $this->vendor->id,
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'status' => 1,
            'admin' => 0,
        ]);
    }

    private function makeGame(array $overrides = []): Game
    {
        $game = Game::factory()->create(array_merge([
            'vendor_id' => $this->vendor->id,
            'game_type' => 'pgssw-slot-1',
            'status' => 1,
            // 明確指定而非依賴 factory 預設：這個值刻意與 setUp() 的 vendors.open_window（1）
            // 不同，是「open_window 有沒有取錯表」的回歸守門員；若改為依賴 factory 預設，
            // 日後預設值一改成 1 就會與 vendor 同值，守門員靜默失效而測試照樣綠燈
            'open_window' => 0,
        ], $overrides));

        DB::table('event_vendor_info_games')->insert([
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'game_id' => $game->id,
            'is_new' => 0,
            'is_hot' => 0,
            'sort' => 0,
            'status' => 1,
            'created_at' => now(),
            'updated_at' => now(),
            'admin' => 0,
        ]);

        return $game;
    }

    private function callGetGameUrl(): array
    {
        return $this->callGetGameUrlFor($this->makeGame()->id);
    }

    private function callGetGameUrlFor(int $gameId): array
    {
        return $this->service->getGameUrl(
            member: $this->member,
            agent: $this->agent,
            gameId: $gameId,
            language: LanguageEnum::EN,
            deviceType: DeviceTypeEnum::PC,
            exitUrl: 'https://example.invalid/exit',
            ip: '0.0.0.0',
        );
    }

    public function test_claims_unbound_pool_account_and_launches_game(): void
    {
        $lobbyUrl = 'https://example.invalid/launch/abc';
        Http::fake([
            '*/game/launch-link*' => Http::response(['code' => 0, 'data' => ['link' => $lobbyUrl]]),
        ]);

        EventPgsswAccount::create([
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'username' => 'POOL_USER_1',
            'member_id' => 0,
            'session_token' => '',
            'admin' => 0,
        ]);

        $game = $this->makeGame();

        $result = $this->service->getGameUrl(
            member: $this->member,
            agent: $this->agent,
            gameId: $game->id,
            language: LanguageEnum::EN,
            deviceType: DeviceTypeEnum::PC,
            exitUrl: 'https://example.invalid/exit',
            ip: '0.0.0.0',
        );

        $this->assertSame($lobbyUrl, $result['result']->pc);
        // open_window 取 games（factory 給 0），不是 vendors（setUp 給 1）——舊版回的是
        // $game->open_window，兩者刻意設成不同值以便這裡就能擋下取錯表的回歸
        $this->assertSame(0, $result['open_window']);

        $account = EventPgsswAccount::where('event_vendor_info_id', $this->eventVendorInfo->id)->first();
        $this->assertSame($this->member->id, $account->member_id, '未綁定帳號應被認領給該會員');
        $this->assertSame('POOL_USER_1', $account->username);
    }

    public function test_open_window_follows_game_not_vendor(): void
    {
        Http::fake([
            '*/game/launch-link*' => Http::response(['code' => 0, 'data' => ['link' => 'https://x.test/a']]),
        ]);

        EventPgsswAccount::create([
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'username' => 'POOL_USER_1',
            'member_id' => 0,
            'session_token' => '',
            'admin' => 0,
        ]);

        // 同一遊戲商（vendors.open_window 固定為 1）底下兩款 open_window 不同的遊戲：
        // 若取的是 vendors 兩者都會回 1，取 games 才會分別回 0 與 1。
        // 正式環境 yes2win 的 WUDI 就是這種情形（vendor=1、旗下 36 款遊戲皆為 0）
        $zeroGame = $this->makeGame(['game_type' => 'pgssw-slot-zero', 'open_window' => 0]);
        $oneGame = $this->makeGame(['game_type' => 'pgssw-slot-one', 'open_window' => 1]);

        $this->assertSame(0, $this->callGetGameUrlFor($zeroGame->id)['open_window']);
        $this->assertSame(1, $this->callGetGameUrlFor($oneGame->id)['open_window']);
    }

    public function test_reuses_already_bound_account_without_claiming_another(): void
    {
        Http::fake([
            '*/game/launch-link*' => Http::response(['code' => 0, 'data' => ['link' => 'https://example.invalid/launch/xyz']]),
        ]);

        EventPgsswAccount::create([
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'username' => 'BOUND_USER',
            'member_id' => $this->member->id,
            'session_token' => '',
            'admin' => 0,
        ]);
        // 池中還有一筆未綁定，確認不會誤認領這筆
        EventPgsswAccount::create([
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'username' => 'POOL_SPARE',
            'member_id' => 0,
            'session_token' => '',
            'admin' => 0,
        ]);

        $this->callGetGameUrl();

        $spare = EventPgsswAccount::where('username', 'POOL_SPARE')->first();
        $this->assertSame(0, $spare->member_id, '會員已有自己的綁定帳號時，不應動到池中其他未綁定帳號');

        Http::assertSent(fn ($request) => str_contains((string) $request['playerUniqueID'], 'BOUND_USER'));
    }

    public function test_throws_when_no_unbound_account_available(): void
    {
        // 池中只有已綁定給別人的帳號，沒有 member_id = 0 可供認領
        $other = $this->createMember($this->agent, ['username' => 'other_' . uniqid()]);
        EventPgsswAccount::create([
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'username' => 'TAKEN_BY_OTHER',
            'member_id' => $other->id,
            'session_token' => '',
            'admin' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9993'));

        $this->callGetGameUrl();
    }

    /**
     * 回歸測試：認領失敗時（拋 ApiException 而非 QueryException）交易必須已關閉。
     *
     * claimEventAccount() 的 catch 只攔 QueryException，自己拋的 ApiException 會穿透
     * finally（只 release cache lock），把未 rollback 的交易留給後續流程——之後同一個
     * request 內的任何寫入都會落在這個懸空交易裡，連線歸還時被隱式 rollback 而遺失。
     */
    public function test_claim_failure_does_not_leak_open_transaction(): void
    {
        $other = $this->createMember($this->agent, ['username' => 'other_' . uniqid()]);
        EventPgsswAccount::create([
            'event_vendor_info_id' => $this->eventVendorInfo->id,
            'username' => 'TAKEN_BY_OTHER',
            'member_id' => $other->id,
            'session_token' => '',
            'admin' => 0,
        ]);

        // DatabaseTransactions 讓測試本身已在一層交易內，故 baseline 為 1
        $baseline = DB::transactionLevel();

        try {
            $this->callGetGameUrl();
            $this->fail('應拋出 ApiException');
        } catch (ApiException) {
            // 預期路徑
        }

        $this->assertSame($baseline, DB::transactionLevel(), '認領失敗後不應殘留未關閉的交易');
    }

    public function test_throws_when_agent_has_no_event_vendor_configured(): void
    {
        // 用 Agent::factory() 直接建，避免 createAgentAndDomain() 的固定 $this->domain 撞唯一鍵
        $bare = Agent::factory()->create(['status' => 1]);
        $bare->hierarchy_path = "/{$bare->id}";
        $bare->save();
        $bareMember = $this->createMember($bare, ['username' => 'bare_' . uniqid()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1426'));

        $this->service->getGameUrl(
            member: $bareMember,
            agent: $bare,
            gameId: $this->makeGame()->id,
            language: LanguageEnum::EN,
            deviceType: DeviceTypeEnum::PC,
            exitUrl: 'https://example.invalid/exit',
            ip: '0.0.0.0',
        );
    }

    public function test_throws_when_game_not_in_configured_event_vendor(): void
    {
        // 遊戲存在，但沒有掛在任何 event_vendor_info_games 底下
        $orphanGame = Game::factory()->create(['vendor_id' => $this->vendor->id, 'status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1406'));

        $this->service->getGameUrl(
            member: $this->member,
            agent: $this->agent,
            gameId: $orphanGame->id,
            language: LanguageEnum::EN,
            deviceType: DeviceTypeEnum::PC,
            exitUrl: 'https://example.invalid/exit',
            ip: '0.0.0.0',
        );
    }

    /**
     * 舊版 get_account() 的 7 個 third_party_code 分支都必須解析到對應的活動帳號表。
     *
     * 以「池中無可認領帳號 → 9993（帳號綁定失敗）」而非「9994（Vendor 不存在）」來斷言
     * 對應表有解析成功並真的查了那張表——不必為每家準備各自的 info 設定與三方 Http fake。
     *
     * 這裡逐一覆蓋「同一個 third_party_code 對多個 vendor_id」的聚合遊戲商（BWG／TIGER9／
     * JokerBaccarat 都是 PGSSW，WudiSW／FumaSW 各有免盤費變體）：曾以 vendor_id 當對應表
     * 的 key，導致本體以外的 vendor_id 全部落到 default 拋 9994，此測試即為該回歸的守門員。
     */
    #[DataProvider('supportedVendorIdProvider')]
    public function test_all_supported_vendors_resolve_to_an_event_account_table(
        int $vendorId,
        string $thirdPartyCode,
        string $label,
    ): void {
        // PGSSW 已由 setUp() 建立，其餘 vendor id 首次出現才建；third_party_code 才是
        // 對應帳號表的 key，factory 給的是隨機值，一律覆寫成本案例要驗的代碼
        $vendor = Vendor::find($vendorId) ?? Vendor::factory()->create(['id' => $vendorId, 'status' => 1]);
        $vendor->update(['third_party_code' => $thirdPartyCode]);
        $info = EventVendorInfo::create([
            'name' => $label, 'vendor_id' => $vendor->id, 'currency' => 'CNY', 'rate' => 1,
            'transfer_min_amount' => 0.01, 'info' => [], 'prepared_account_number' => 0,
            'account_prefix' => 'evt', 'status' => 1, 'admin' => 0,
        ]);
        EventVendor::create([
            'agent_id' => $this->agent->id, 'vendor_id' => $vendor->id,
            'event_vendor_info_id' => $info->id, 'status' => 1, 'admin' => 0,
        ]);
        $game = Game::factory()->create(['vendor_id' => $vendor->id, 'status' => 1]);
        DB::table('event_vendor_info_games')->insert([
            'event_vendor_info_id' => $info->id, 'game_id' => $game->id,
            'is_new' => 0, 'is_hot' => 0, 'sort' => 0, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(), 'admin' => 0,
        ]);

        $this->expectException(ApiException::class);
        // 9993 = 走到了帳號認領、只是池子空；若是 9994 代表 match 沒認得這家遊戲商
        $this->expectExceptionMessage(__('error_message.9993'));

        $this->service->getGameUrl(
            member: $this->member,
            agent: $this->agent,
            gameId: $game->id,
            language: LanguageEnum::EN,
            deviceType: DeviceTypeEnum::PC,
            exitUrl: 'https://example.invalid/exit',
            ip: '0.0.0.0',
        );
    }

    /**
     * @return array<string, array{int, string, string}>
     */
    public static function supportedVendorIdProvider(): array
    {
        return [
            // 7 個 base 分支
            'PGSSW' => [VendorIdEnum::PGSSW->value, 'PGSSW', 'PGSSW'],
            'UUSlotSW' => [VendorIdEnum::UUSLOTSW->value, 'UUSSW', 'UUSlotSW'],
            'VpowerSW' => [VendorIdEnum::VPOWERSW->value, 'VPOWERSW', 'VpowerSW'],
            'WFSW' => [VendorIdEnum::WFSW->value, 'WFSW', 'WFSW'],
            'PPSW' => [VendorIdEnum::PPSW->value, 'PPSW', 'PPSW'],
            'WudiSW' => [VendorIdEnum::WUDISW->value, 'WUDISW', 'WudiSW'],
            'FumaSW' => [VendorIdEnum::FUMASW->value, 'FUMASW', 'FumaSW'],
            // 同 third_party_code、不同 vendor_id 的聚合遊戲商（正式環境實際存在的配置）
            'BWG（third_party_code=PGSSW）' => [VendorIdEnum::BWG->value, 'PGSSW', 'BWG'],
            'TIGER9（third_party_code=PGSSW）' => [VendorIdEnum::TIGER9->value, 'PGSSW', 'TIGER 9'],
            'JokerBaccarat（third_party_code=PGSSW）' => [
                VendorIdEnum::JOKER_BACCARAT->value, 'PGSSW', 'JokerBaccarat',
            ],
            'WudiSW 免盤費' => [VendorIdEnum::WUDISW_FREE->value, 'WUDISW', 'WudiSW free'],
            'FumaSW 免盤費' => [VendorIdEnum::FUMASW_FREE->value, 'FUMASW', 'FumaSW free'],
        ];
    }

    public function test_throws_when_vendor_is_unsupported(): void
    {
        // 舊版 get_account() 的 7 個 third_party_code 分支之外的遊戲商（舊版走 default
        // 拋 1005，新版拋 9994）；即使代理誤把不支援的遊戲商配置進 event_vendors 也應明確拒絕
        $unsupportedVendor = Vendor::factory()->create(['status' => 1, 'third_party_code' => 'NOT_AN_EVENT_VENDOR']);
        $unsupportedInfo = EventVendorInfo::create([
            'name' => 'unsupported', 'vendor_id' => $unsupportedVendor->id, 'currency' => 'CNY', 'rate' => 1,
            'transfer_min_amount' => 0.01, 'info' => [], 'prepared_account_number' => 0,
            'account_prefix' => 'evt', 'status' => 1, 'admin' => 0,
        ]);
        EventVendor::create([
            'agent_id' => $this->agent->id, 'vendor_id' => $unsupportedVendor->id,
            'event_vendor_info_id' => $unsupportedInfo->id, 'status' => 1, 'admin' => 0,
        ]);
        $game = Game::factory()->create(['vendor_id' => $unsupportedVendor->id, 'status' => 1]);
        DB::table('event_vendor_info_games')->insert([
            'event_vendor_info_id' => $unsupportedInfo->id, 'game_id' => $game->id,
            'is_new' => 0, 'is_hot' => 0, 'sort' => 0, 'status' => 1,
            'created_at' => now(), 'updated_at' => now(), 'admin' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9994'));

        $this->service->getGameUrl(
            member: $this->member,
            agent: $this->agent,
            gameId: $game->id,
            language: LanguageEnum::EN,
            deviceType: DeviceTypeEnum::PC,
            exitUrl: 'https://example.invalid/exit',
            ip: '0.0.0.0',
        );
    }
}
