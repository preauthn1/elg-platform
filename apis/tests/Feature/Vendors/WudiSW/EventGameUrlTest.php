<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\WudiSW;

use App\Data\VendorGameUrlParamsData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\VendorSetupException;
use App\Models\EventVendorInfo;
use App\Models\Vendor;
use App\Vendors\Models\EventPgsswAccount;
use App\Vendors\Models\EventWudiswAccount;
use App\Vendors\WudiSW\Driver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * WudiSW\Driver::useLegacyEventContext() 測試
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
        Vendor::factory()->create(['id' => VendorIdEnum::WUDISW->value, 'status' => 1]);

        return EventVendorInfo::create([
            'name' => 'WudiSW Event',
            'vendor_id' => VendorIdEnum::WUDISW->value,
            'currency' => 'CNY',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => [
                'API_URL' => self::API_URL,
                'ACCOUNT_ID' => 'test-account-id',
                'MD5_KEY' => 'test-md5-key',
                'SECURITY_CODE' => 'test-security-code',
            ],
            'prepared_account_number' => 1,
            'account_prefix' => 'evt',
            'status' => 1,
            'admin' => 0,
        ]);
    }

    public function test_use_legacy_event_context_launches_game_with_event_account(): void
    {
        $gameUrl = 'https://example.invalid/launch/abc';
        Http::fake([
            '*/agent/user_login' => Http::response([
                'status' => ['code' => 1000],
                'data' => ['game_url' => $gameUrl],
            ]),
        ]);

        $eventVendorInfo = $this->makeEventVendorInfo();
        $eventAccount = EventWudiswAccount::create([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'EVENT_WUDI_1',
            'member_id' => 1,
            'admin' => 0,
        ]);

        $driver = new Driver(VendorIdEnum::WUDISW->value, null);
        $driver->useLegacyEventContext($eventVendorInfo, $eventAccount);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'wudi-game-1',
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
            'ip' => '0.0.0.0',
        ]));

        $this->assertSame($gameUrl, $result->pc);

        // $infoDetail（check() 會驗）與 $account 都有被填入
        Http::assertSent(fn ($request) => $request['username'] === 'EVENT_WUDI_1'
            && $request['account_id'] === 'test-account-id'
            && $request->url() === self::API_URL . '/agent/user_login');
    }

    public function test_use_legacy_event_context_rejects_incompatible_account_type(): void
    {
        $eventVendorInfo = $this->makeEventVendorInfo();

        $incompatibleAccount = new EventPgsswAccount([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'WRONG_TYPE',
            'member_id' => 1,
            'session_token' => '',
        ]);

        $driver = new Driver(VendorIdEnum::WUDISW->value, null);

        $this->expectException(VendorSetupException::class);
        $driver->useLegacyEventContext($eventVendorInfo, $incompatibleAccount);
    }
}
