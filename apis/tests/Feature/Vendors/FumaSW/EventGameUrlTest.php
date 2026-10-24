<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\FumaSW;

use App\Data\VendorGameUrlParamsData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\VendorSetupException;
use App\Models\EventVendorInfo;
use App\Models\Vendor;
use App\Vendors\FumaSW\Driver;
use App\Vendors\Models\EventFumaswAccount;
use App\Vendors\Models\EventPgsswAccount;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FumaSW\Driver::useLegacyEventContext() 測試
 *
 * 與 EventV2（`app/Services/EventV2/`）無關，服務對象是 ticket 4005
 * `refresh_ranking/game_url` 的活動專用帳號池機制。
 *
 * FumaSW 只加密 request（AES-128-CBC），回應是明文 JSON，故可直接 fake。
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
        Vendor::factory()->create(['id' => VendorIdEnum::FUMASW->value, 'status' => 1]);

        return EventVendorInfo::create([
            'name' => 'FumaSW Event',
            'vendor_id' => VendorIdEnum::FUMASW->value,
            'currency' => 'CNY',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => [
                'API_URL' => self::API_URL,
                'DC' => 'test-dc',
                // AES-128-CBC：key／iv 必須為 16 bytes
                'KEY' => '0123456789abcdef',
                'IV' => 'fedcba9876543210',
                'PARENT' => 'test-parent',
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
            '*/Se_token.aspx' => Http::response(['status' => '0000', 'path' => $gameUrl]),
        ]);

        $eventVendorInfo = $this->makeEventVendorInfo();
        $eventAccount = EventFumaswAccount::create([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'EVENT_FUMA_1',
            'player_id' => '',
            'password' => '',
            'member_id' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $driver = new Driver(VendorIdEnum::FUMASW->value, null);
        $driver->useLegacyEventContext($eventVendorInfo, $eventAccount);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'fuma-game-1',
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
            'ip' => '0.0.0.0',
        ]));

        $this->assertSame($gameUrl, $result->pc);

        // $infoDetail 有被填入：dc 取自事件商戶設定，且 request 已加密（x 欄位非空）
        Http::assertSent(fn ($request) => $request['dc'] === 'test-dc'
            && !empty($request['x'])
            && $request->url() === self::API_URL . '/Se_token.aspx');
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

        $driver = new Driver(VendorIdEnum::FUMASW->value, null);

        $this->expectException(VendorSetupException::class);
        $driver->useLegacyEventContext($eventVendorInfo, $incompatibleAccount);
    }
}
