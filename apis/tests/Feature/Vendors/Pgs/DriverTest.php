<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\target;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\GetGameListParamsData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldScheduleAccountCreation;
use App\Vendors\Contracts\SupportsAccountCreation;
use App\Vendors\Models\PgsAccount;
use App\Vendors\target\Driver;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

#[Group('op3177')]
class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.target.test';

    private const string API_APPID = 'target-merchant-id';

    private const string API_KEY = [REDACTED]';

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::JILI->value);
        $this->vendor->save();

        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $this->vendor->id)->firstOrFail();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'API_APPID' => self::API_APPID,
            'API_KEY' => self::API_KEY,
        ];
        $this->vendorInfo->save();
    }

    #[TestDox('getGameList 以 merchantID 與 MerchantSecretKey 請求 /v1/games 並映射遊戲類型與 remark')]
    #[Group('rules/game-list-sync#R-37')]
    public function test_get_game_list_maps_response_and_calls_correct_endpoint(): void
    {
        Http::fake([
            '*/v1/games*' => Http::response([
                'code' => 0,
                'data' => [
                    [
                        'gameID' => '1004',
                        'gameName' => '金吉報喜_無盡寶藏',
                        'gameType' => 1000,
                        'imageUrl' => 'https://cdn.target.test/1004.png',
                        'gameCode' => 'GoldenFortune',
                    ],
                    [
                        'gameID' => '2501',
                        'gameName' => 'Live Baccarat',
                        'gameType' => 2500,
                    ],
                    [
                        'gameID' => '9001',
                        'gameName' => 'Unknown Type Game',
                        'gameType' => 9999,
                    ],
                    [
                        // 缺 gameID 的列不納入清單（避免以空字串為唯一鍵誤停用整批遊戲）
                        'gameName' => 'No Id Game',
                        'gameType' => 1000,
                    ],
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::JILI->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(3, $result->gameList);

        $slot = $result->gameList['1004'];
        $this->assertSame('金吉報喜_無盡寶藏', $slot->gameName);
        $this->assertSame(GameTypeEnum::SLOTS->value, $slot->gameType);
        $this->assertSame('https://cdn.target.test/1004.png', $slot->imageUrl);
        $this->assertSame('GoldenFortune', $slot->remark);
        $this->assertSame(1, $slot->status);

        $live = $result->gameList['2501'];
        $this->assertSame(GameTypeEnum::LIVE_CASINO->value, $live->gameType);
        $this->assertNull($live->remark);

        // 未列於映射表的分類碼落回 0（未分類）
        $this->assertSame(0, $result->gameList['9001']->gameType);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/games')
                && $request['merchantID'] === self::API_APPID
                && $request['MerchantSecretKey'] === self::API_KEY;
        });
    }

    #[Group('op3177-ac2')]
    #[TestDox('[AC-2] createAccount 成功時以 MerchantSecretKey 呼叫 /v1/player，並把帳號翻成可綁定（member_id = 0）')]
    #[Group('rules/vendor-account-pool#R-12')]
    public function test_create_account_marks_account_bindable_when_vendor_api_succeeds(): void
    {
        Http::fake([
            '*/v1/player' => Http::response(['code' => 0]),
        ]);

        $driver = new Driver(VendorIdEnum::JILI->value, null);
        $username = 'pgstest00000001';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 0,
            username: $username,
        ));

        $this->assertTrue($result->status);
        $this->assertSame($username, $result->username);

        $account = PgsAccount::query()->where('username', $username)->firstOrFail();
        $this->assertSame(0, $account->member_id);
        $this->assertSame($this->vendorInfo->id, $account->vendor_info_id);

        Http::assertSent(function ($request) use ($username) {
            return str_contains($request->url(), '/v1/player')
                && $request->method() === 'POST'
                && $request['playerName'] === $username
                && $request['merchantID'] === self::API_APPID
                && $request['MerchantSecretKey'] === self::API_KEY;
        });
    }

    #[Group('op3177-ac5')]
    #[TestDox('[AC-5] target 聚合群四家共用同一 driver、皆具備建帳號能力且不在停用清單，補建範圍與選單才選得到它們')]
    #[Group('rules/vendor-account-pool#R-3')]
    public function test_pgs_aggregate_vendors_are_all_account_creation_capable(): void
    {
        $aggregateVendorIds = [
            VendorIdEnum::target->value,
            VendorIdEnum::JILI->value,
            VendorIdEnum::PGS_LIVE->value,
            VendorIdEnum::FUNKY->value,
        ];

        $disabledVendorIds = require database_path('data/disabled_vendors.php');

        foreach ($aggregateVendorIds as $vendorId) {
            $driverClass = config('vendor.driver')[$vendorId] ?? null;

            $this->assertSame(Driver::class, $driverClass, "vendor {$vendorId} 未掛 target 聚合 driver");
            $this->assertNotContains($vendorId, $disabledVendorIds, "vendor {$vendorId} 仍列於停用清單，補建範圍會漏掉它");
        }

        // 具備建帳號能力且掛自動補建排程
        $this->assertTrue(is_a(Driver::class, SupportsAccountCreation::class, true));
        $this->assertTrue(is_a(Driver::class, ShouldScheduleAccountCreation::class, true));
    }

    #[Group('op3177-ac3')]
    #[TestDox('[AC-3] createAccount 三方回應 code 非 0 時回報失敗，帳號留在 member_id = -999999999 不進帳號池')]
    #[Group('rules/vendor-account-pool#R-12')]
    public function test_create_account_keeps_account_out_of_pool_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/v1/player' => Http::response([
                'code' => 403001,
                'message' => 'invalid parameter',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::JILI->value, null);
        $username = 'pgstest00000002';

        $result = $driver->createAccount(new CreateAccountParamsData(
            vendorInfo: $this->vendorInfo,
            serialNumber: 1,
            username: $username,
        ));

        $this->assertFalse($result->status);

        $account = PgsAccount::query()->where('username', $username)->firstOrFail();
        $this->assertSame(-999999999, $account->member_id);
    }

    #[TestDox('getGameList 三方回應 code 非 0 時回傳空清單')]
    #[Group('rules/game-list-sync#R-23')]
    public function test_get_game_list_returns_empty_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/v1/games*' => Http::response([
                'code' => 403001,
                'message' => 'invalid parameter',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::JILI->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(0, $result->gameList);
    }
}
