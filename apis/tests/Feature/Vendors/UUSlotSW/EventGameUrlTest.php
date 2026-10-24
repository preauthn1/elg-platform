<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\UUSlotSW;

use App\Data\VendorGameUrlParamsData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\VendorSetupException;
use App\Models\EventVendorInfo;
use App\Models\Vendor;
use App\Vendors\Models\EventPgsswAccount;
use App\Vendors\Models\EventUuslotswAccount;
use App\Vendors\UUSlotSW\Driver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * UUSlotSW\Driver::useLegacyEventContext() 測試
 *
 * UUSlotSW（與 VpowerSW 同構）在建構子中把 vendorInfo/vendorAccount 另外轉存到本類別
 * 私有的嚴格型別屬性（$infoDetail／$account），舊版活動情境（`member: null` 建構、
 * 事後注入 EventVendorInfo／事件帳號）必須靠 useLegacyEventContext() override 補做這件事，
 * 否則 getGameUrl() 存取這兩個私有屬性時會是「尚未初始化」的致命錯誤。
 *
 * 與 EventV2（`app/Services/EventV2/`）無關，服務對象是 ticket 4005
 * `refresh_ranking/game_url` 的活動專用帳號池機制。
 */
class EventGameUrlTest extends TestCase
{
    private const string API_URL = 'https://example.invalid';

    protected function setUp(): void
    {
        parent::setUp();
        Context::addHidden(ContextKeyEnum::LANGUAGE_ID->value, LanguageEnum::EN->value);
    }

    private function makeEventVendorInfo(): EventVendorInfo
    {
        Vendor::factory()->create(['id' => VendorIdEnum::UUSLOTSW->value, 'status' => 1]);

        return EventVendorInfo::create([
            'name' => 'UUSlotSW Event',
            'vendor_id' => VendorIdEnum::UUSLOTSW->value,
            'currency' => 'MYR',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => [
                'API_URL' => self::API_URL,
                'Security_key' => 'test-security-key',
                'Merchant_Code' => 'test-merchant',
                'CURRENCY' => 'MYR',
            ],
            'prepared_account_number' => 1,
            'account_prefix' => 'evt',
            'status' => 1,
            'admin' => 0,
        ]);
    }

    public function test_use_legacy_event_context_populates_private_properties_and_launches_game(): void
    {
        $lobbyUrl = 'https://example.invalid/launch/abc';
        Http::fake([
            '*/GameLogin' => Http::response(['Status' => 200, 'Url' => $lobbyUrl]),
        ]);

        $eventVendorInfo = $this->makeEventVendorInfo();
        $eventAccount = EventUuslotswAccount::create([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'EVENT_PLAYER_1',
            'member_id' => 1,
            'admin' => 0,
        ]);

        // 建構時 member: null，跳過一般會員帳號解析（不查 uuslotsw_accounts）
        $driver = new Driver(VendorIdEnum::UUSLOTSW->value, null);
        $driver->useLegacyEventContext($eventVendorInfo, $eventAccount);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'uuslot-game-1',
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
            'ip' => '0.0.0.0',
        ]));

        $this->assertSame($lobbyUrl, $result->pc);
        Http::assertSent(fn ($request) => $request['PlayerId'] === 'EVENT_PLAYER_1'
            && $request->url() === self::API_URL . '/GameLogin');
    }

    public function test_use_legacy_event_context_rejects_incompatible_account_type(): void
    {
        $eventVendorInfo = $this->makeEventVendorInfo();

        // 刻意注入不相容的帳號物件（另一家的 EventPgsswAccount），驗證型別防護會擋下
        $incompatibleAccount = new EventPgsswAccount([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'WRONG_TYPE',
            'member_id' => 1,
            'session_token' => '',
        ]);

        $driver = new Driver(VendorIdEnum::UUSLOTSW->value, null);

        $this->expectException(VendorSetupException::class);
        $driver->useLegacyEventContext($eventVendorInfo, $incompatibleAccount);
    }
}
