<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Sma;

use App\Data\Vendor\GetGameListParamsData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Sma\Driver;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

#[Group('ticket-4405')]
class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.sma.test/';

    private const string API_APPID = 'sma-app-id';

    private const string API_SECRET_KEY = [REDACTED]';

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::SMA->value);

        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $this->vendor->id)->firstOrFail();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'API_APPID' => self::API_APPID,
            'API_SECRET_KEY' => self::API_SECRET_KEY,
        ];
        $this->vendorInfo->save();
    }

    #[Group('ac-1 [自動] 六家 driver 掛上自動同步標記，每小時排程自動向三方抓清單')]
    #[TestDox('Sma Driver 標記 ShouldSyncGameList，納入 update_game_list 自動排程')]
    #[Group('rules/game-list-sync#R-2')]
    public function test_driver_is_marked_for_scheduled_game_list_sync(): void
    {
        $driver = new Driver(VendorIdEnum::SMA->value, null);

        $this->assertInstanceOf(ShouldSyncGameList::class, $driver);
    }

    #[Group('ac-3 [自動] 三方有、資料庫沒有的遊戲會被新增')]
    #[Group('ac-4 [自動] 缺遊戲代碼的列不新增，也不影響其他列')]
    #[Group('ac-6 [自動] SMA：slots 落老虎機、fishing 落捕魚、其他落活動與其他；圖取方形圖；新遊戲為啟用')]
    #[Group('ac-9 [自動] 抓清單請求的簽章正確，三方不回簽名錯誤')]
    #[TestDox('getGameList 以 appId、timestamp、sign 請求 gameList，並映射代碼、名稱、方形圖與類型')]
    #[Group('rules/game-list-sync#R-33')]
    public function test_get_game_list_maps_response_and_signs_request(): void
    {
        Http::fake([
            '*/api/v2/ext/gameList' => Http::response([
                'code' => 200,
                'msg' => 'success',
                'data' => [
                    [
                        'id' => 1001,
                        'name' => 'Lucky Slot',
                        'type' => 'slots',
                        'squareIcon' => 'https://cdn.sma.test/1001-square.png',
                        'portraitIcon' => 'https://cdn.sma.test/1001-portrait.png',
                    ],
                    [
                        'id' => 2001,
                        'name' => 'Fish Hunter',
                        'type' => 'fishing',
                        'squareIcon' => 'https://cdn.sma.test/2001-square.png',
                    ],
                    [
                        // 對應表外的類型落回「活動與其他」
                        'id' => 3001,
                        'name' => 'Mystery Table',
                        'type' => 'table',
                    ],
                    [
                        // 缺 id 的列不納入清單
                        'name' => 'No Id Game',
                        'type' => 'slots',
                    ],
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::SMA->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(3, $result->gameList);

        $slot = $result->gameList['1001'];
        $this->assertSame('Lucky Slot', $slot->gameName);
        $this->assertSame(GameTypeEnum::SLOTS->value, $slot->gameType);
        $this->assertSame('https://cdn.sma.test/1001-square.png', $slot->imageUrl);
        $this->assertSame(1, $slot->status);

        $this->assertSame(GameTypeEnum::FISHING->value, $result->gameList['2001']->gameType);

        $unknown = $result->gameList['3001'];
        $this->assertSame(GameTypeEnum::EVENT_AND_OTHERS->value, $unknown->gameType);
        $this->assertSame('', $unknown->imageUrl);

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $expectedSign = md5(
                'appId=' . self::API_APPID . '&timestamp=' . $body['timestamp'] . self::API_SECRET_KEY
            );

            return str_ends_with($request->url(), '/api/v2/ext/gameList')
                && $body['appId'] === self::API_APPID
                && $body['sign'] === $expectedSign;
        });
    }

    #[Group('ac-10 [自動] 三方業務碼非成功時回空清單並記 error，既有遊戲不變')]
    #[TestDox('getGameList 三方回應 code 非 200 時回傳空清單，不拋例外')]
    #[Group('rules/game-list-sync#R-23')]
    public function test_get_game_list_returns_empty_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/api/v2/ext/gameList' => Http::response([
                'code' => 4,
                'msg' => 'Invalid Signature',
                'data' => null,
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::SMA->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertSame([], $result->gameList);
    }

    #[Group('ac-11 [自動] 三方 HTTP 錯誤時拋例外讓排程失敗可見，既有遊戲不變')]
    #[TestDox('getGameList 三方 HTTP 層錯誤時拋出 ApiException，讓排程吵出來而不是靜默空清單')]
    #[Group('rules/game-list-sync#R-24')]
    public function test_get_game_list_throws_on_http_error(): void
    {
        Http::fake([
            '*/api/v2/ext/gameList' => Http::response('Service Unavailable', 503),
        ]);

        $driver = new Driver(VendorIdEnum::SMA->value, null);

        $this->expectException(ApiException::class);

        $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));
    }
}
