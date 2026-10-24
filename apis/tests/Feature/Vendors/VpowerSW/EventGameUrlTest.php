<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\VpowerSW;

use App\Data\VendorGameUrlParamsData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\VendorSetupException;
use App\Models\EventVendorInfo;
use App\Models\Vendor;
use App\Vendors\Models\EventPgsswAccount;
use App\Vendors\Models\EventVpowerswAccount;
use App\Vendors\VpowerSW\Driver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VpowerSW\Driver::useLegacyEventContext() 測試
 *
 * 與 UUSlotSW 同構：建構子會把 vendorInfo/vendorAccount 轉存到本類別私有的
 * $infoDetail／$account，舊版活動情境（`member: null` 建構）必須靠 override 補做，
 * 否則 getGameUrl() 存取這兩個私有屬性時會是「尚未初始化」的致命錯誤。
 *
 * 與 EventV2（`app/Services/EventV2/`）無關，服務對象是 ticket 4005
 * `refresh_ranking/game_url` 的活動專用帳號池機制。
 */
class EventGameUrlTest extends TestCase
{
    private const string API_URL = 'https://example.invalid';

    private const string GAME_URL = 'https://example.invalid/launch';

    protected function setUp(): void
    {
        parent::setUp();
        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::EN->value);
    }

    private function makeEventVendorInfo(): EventVendorInfo
    {
        Vendor::factory()->create(['id' => VendorIdEnum::VPOWERSW->value, 'status' => 1]);

        return EventVendorInfo::create([
            'name' => 'VpowerSW Event',
            'vendor_id' => VendorIdEnum::VPOWERSW->value,
            'currency' => 'CNY',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => [
                'API_URL' => self::API_URL,
                'GAME_URL' => self::GAME_URL,
                'APP_ID' => 'test-app-id',
                'APP_SECRET' => 'test-app-secret',
            ],
            'prepared_account_number' => 1,
            'account_prefix' => 'evt',
            'status' => 1,
            'admin' => 0,
        ]);
    }

    public function test_use_legacy_event_context_populates_private_properties_and_builds_game_url(): void
    {
        Http::fake([
            '*/player/login' => Http::response(['code' => 200, 'data' => ['token' => 'event-token-abc']]),
        ]);

        $eventVendorInfo = $this->makeEventVendorInfo();
        $eventAccount = EventVpowerswAccount::create([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'EVENT_VP_1',
            'member_id' => 1,
            'admin' => 0,
        ]);

        // 建構時 member: null，跳過一般會員帳號解析（不查 vpowersw_accounts）
        $driver = new Driver(VendorIdEnum::VPOWERSW->value, null);
        $driver->useLegacyEventContext($eventVendorInfo, $eventAccount);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'vp-game-1',
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
            'ip' => '0.0.0.0',
        ]));

        // $infoDetail 有被填入（GAME_URL 來自事件商戶設定）
        $this->assertStringStartsWith(self::GAME_URL . '?', $result->pc);
        $this->assertStringContainsString('token=[REDACTED]', $result->pc);
        $this->assertStringContainsString('id=vp-game-1', $result->pc);

        // $account 有被填入：登入時送的是活動專用帳號的 username
        Http::assertSent(fn ($request) => $request['username'] === 'EVENT_VP_1'
            && $request->url() === self::API_URL . '/player/login');
    }

    /**
     * 活動流程要送「玩家實際語言」，不是舊版寫死的英文。
     *
     * 舊版 VendorVpowerSingleWallet 兩支方法刻意不同：一般 getGameUrl() 的參數陣列連 lang
     * 這個 key 都沒有，活動 getEventGameUrl() 則寫死 `'lang' => 'en'`（舊檔 :96）。新版重用
     * 同一支 Driver 後統一改送玩家實際語言，PM 已確認舊版是漏加語言、新行為才正確
     * （2026-08-04）。其餘測試情境都用 EN，寫死 'en' 也會綠燈、守不住這條線，故單獨補這支。
     */
    public function test_event_game_url_sends_member_language_not_hardcoded_english(): void
    {
        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::ZH_TW->value);

        Http::fake([
            '*/player/login' => Http::response(['code' => 200, 'data' => ['token' => 'event-token-abc']]),
        ]);

        $eventVendorInfo = $this->makeEventVendorInfo();
        $eventAccount = EventVpowerswAccount::create([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'EVENT_VP_1',
            'member_id' => 1,
            'admin' => 0,
        ]);

        $driver = new Driver(VendorIdEnum::VPOWERSW->value, null);
        $driver->useLegacyEventContext($eventVendorInfo, $eventAccount);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'vp-game-1',
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::ZH_TW,
            'exitUrl' => 'https://example.invalid/exit',
            'ip' => '0.0.0.0',
        ]));

        $this->assertStringContainsString('lang=cn', $result->pc, '繁中會員應送 cn，不是寫死的 en');
        $this->assertStringNotContainsString('lang=en', $result->pc);
    }

    public function test_use_legacy_event_context_rejects_incompatible_account_type(): void
    {
        $eventVendorInfo = $this->makeEventVendorInfo();

        // 刻意注入另一家的事件帳號，驗證型別防護會擋下（避免把錯的 username 送給三方）
        $incompatibleAccount = new EventPgsswAccount([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'WRONG_TYPE',
            'member_id' => 1,
            'session_token' => '',
        ]);

        $driver = new Driver(VendorIdEnum::VPOWERSW->value, null);

        $this->expectException(VendorSetupException::class);
        $driver->useLegacyEventContext($eventVendorInfo, $incompatibleAccount);
    }
}
