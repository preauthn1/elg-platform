<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\PPSW;

use App\Data\VendorGameUrlParamsData;
use App\Enums\General\ContextKeyEnum;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\VendorSetupException;
use App\Models\EventVendorInfo;
use App\Models\Vendor;
use App\Vendors\Models\EventPgsswAccount;
use App\Vendors\Models\EventPpswAccount;
use App\Vendors\PPSW\Driver;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PPSW\Driver::useLegacyEventContext() 測試
 *
 * PPSW 的活動流程在 apis 是**唯一連回調側都已存在**的一家（`EventPpswController`
 * ＋ `refresh_ranking/pp/*` 路由 ＋ `EventPpswBetRecord`／`EventPpswDealRecord`），
 * 本次補上的是缺的啟動側（`refresh_ranking/game_url`）。
 *
 * 與 EventV2（`app/Services/EventV2/`）無關。
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
        Vendor::factory()->create(['id' => VendorIdEnum::PPSW->value, 'status' => 1]);

        return EventVendorInfo::create([
            'name' => 'PPSW Event',
            'vendor_id' => VendorIdEnum::PPSW->value,
            'currency' => 'MYR',
            'rate' => 1,
            'transfer_min_amount' => 0.01,
            'info' => [
                'API_URL' => self::API_URL,
                'SECURE_LOGIN' => 'test-secure-login',
                'SECRET_KEY' => 'test-secret-key',
                'CURRENCY' => 'MYR',
                'COUNTRY' => 'MY',
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
            '*/game/url/' => Http::response(['error' => 0, 'gameURL' => $gameUrl]),
        ]);

        $eventVendorInfo = $this->makeEventVendorInfo();
        $eventAccount = EventPpswAccount::create([
            'event_vendor_info_id' => $eventVendorInfo->id,
            'username' => 'EVENT_PP_1',
            'member_id' => 1,
            'currency' => 'MYR',
            'country' => 'MY',
            'status' => 1,
            'admin' => 0,
        ]);

        $driver = new Driver(VendorIdEnum::PPSW->value, null);
        $driver->useLegacyEventContext($eventVendorInfo, $eventAccount);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'pp-game-1',
            'deviceType' => DeviceTypeEnum::PC,
            'language' => LanguageEnum::EN,
            'exitUrl' => 'https://example.invalid/exit',
            'ip' => '0.0.0.0',
        ]));

        $this->assertSame($gameUrl, $result->pc);

        // $config（setInfo 的產物）與 $account 都有被填入
        Http::assertSent(fn ($request) => $request['externalPlayerId'] === 'EVENT_PP_1'
            && $request['secureLogin'] === 'test-secure-login'
            && $request->url() === self::API_URL . '/game/url/');

        // 啟動時會在活動帳號上寫入新 token（供回調端 EventPpswController 驗證）
        $this->assertNotEmpty($eventAccount->fresh()->token);
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

        $driver = new Driver(VendorIdEnum::PPSW->value, null);

        $this->expectException(VendorSetupException::class);
        $driver->useLegacyEventContext($eventVendorInfo, $incompatibleAccount);
    }
}
