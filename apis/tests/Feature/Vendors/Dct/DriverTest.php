<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Dct;

use App\Data\Vendor\GetGameListParamsData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Dct\Driver;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

#[Group('ticket-4405')]
class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.dcace.test';

    private const string API_KEY = [REDACTED]';

    private const string BRAND_ID = 'brand01';

    private const string PROVIDER = 'png';

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        // 以聚合子商 PNG（97）驗證：共用 Dct Driver、以 info.PROVIDER 指定底層遊戲商
        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::PNG->value);

        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $this->vendor->id)->firstOrFail();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'API_KEY' => self::API_KEY,
            'BRAND_ID' => self::BRAND_ID,
            'CURRENCY' => 'MYR',
            'PROVIDER' => self::PROVIDER,
            'COUNTRY_CODE' => 'MY',
        ];
        $this->vendorInfo->save();
    }

    #[Group('ac-1 [自動] 六家 driver 掛上自動同步標記，每小時排程自動向三方抓清單')]
    #[TestDox('Dct Driver 標記 ShouldSyncGameList，DCT／PNG／RELAX／HS 一併納入 update_game_list 自動排程')]
    #[Group('rules/game-list-sync#R-2')]
    public function test_driver_is_marked_for_scheduled_game_list_sync(): void
    {
        foreach ([VendorIdEnum::DCT, VendorIdEnum::PNG, VendorIdEnum::RELAX, VendorIdEnum::HS] as $vendorId) {
            if (!Vendor::find($vendorId->value)) {
                Vendor::factory()->create(['id' => $vendorId->value]);
            }

            $this->assertInstanceOf(ShouldSyncGameList::class, new Driver($vendorId->value, null));
        }
    }

    #[Group('ac-3 [自動] 三方有、資料庫沒有的遊戲會被新增')]
    #[Group('ac-4 [自動] 缺遊戲代碼的列不新增，也不影響其他列')]
    #[Group('ac-8 [自動] DCT：四家各取自家底層遊戲商的清單；含 slot 落老虎機、InstantWin／Scratchcard 落街機、其他落活動與其他；簡中名進多語名稱；新遊戲為停用')]
    #[Group('ac-9 [自動] 抓清單請求的簽章正確，三方不回簽名錯誤')]
    #[TestDox('getGameList 以 brand_id + api_key 簽名、帶 info.PROVIDER 請求 getGameList，並映射類型、圖片、簡中名與整列 info')]
    #[Group('rules/game-list-sync#R-35')]
    public function test_get_game_list_maps_response_and_signs_request(): void
    {
        Http::fake([
            '*/dct/getGameList' => Http::response([
                'code' => 1000,
                'msg' => 'Success.',
                'data' => [
                    [
                        'provider' => 'png',
                        'game_id' => 'bookofdead',
                        'game_name' => 'Book of Dead',
                        'game_name_cn' => '死亡之书',
                        'game_type' => 'Video Slot',
                        'game_icon' => 'https://cdn.dcace.test/bookofdead.png',
                        'rtp' => '96.21',
                        'content_type' => 'HTML5',
                    ],
                    [
                        'provider' => 'png',
                        'game_id' => 'scratch01',
                        'game_name' => 'Lucky Scratch',
                        'game_name_cn' => '',
                        'game_type' => 'Scratchcard',
                        'game_icon' => '',
                    ],
                    [
                        // 對應表外的類型落回「活動與其他」
                        'provider' => 'png',
                        'game_id' => 'live01',
                        'game_name' => 'Live Roulette',
                        'game_type' => 'Live Casino',
                    ],
                    [
                        // 缺 game_id 的列不納入清單
                        'game_name' => 'No Id Game',
                        'game_type' => 'Slot',
                    ],
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::PNG->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(3, $result->gameList);

        $slot = $result->gameList['bookofdead'];
        $this->assertSame('Book of Dead', $slot->gameName);
        $this->assertSame(GameTypeEnum::SLOTS->value, $slot->gameType);
        $this->assertSame('https://cdn.dcace.test/bookofdead.png', $slot->imageUrl);
        $this->assertSame(0, $slot->status);
        $this->assertSame('死亡之书', $slot->gameNames[LanguageEnum::ZH_CN->value]);
        $this->assertSame('96.21', json_decode($slot->info, true)['rtp']);

        $scratch = $result->gameList['scratch01'];
        $this->assertSame(GameTypeEnum::ARCADE->value, $scratch->gameType);
        $this->assertArrayNotHasKey(LanguageEnum::ZH_CN->value, $scratch->gameNames);

        $this->assertSame(GameTypeEnum::EVENT_AND_OTHERS->value, $result->gameList['live01']->gameType);

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return str_ends_with($request->url(), '/dct/getGameList')
                && $body['brand_id'] === self::BRAND_ID
                && $body['provider'] === self::PROVIDER
                && $body['sign'] === strtoupper(md5(self::BRAND_ID . self::API_KEY));
        });
    }

    #[Group('ac-10 [自動] 三方業務碼非成功時回空清單並記 error，既有遊戲不變')]
    #[TestDox('getGameList 三方回應 code 非 1000 時回傳空清單，不拋例外')]
    #[Group('rules/game-list-sync#R-23')]
    public function test_get_game_list_returns_empty_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/dct/getGameList' => Http::response([
                'code' => 5015,
                'msg' => 'Incorrect provider.',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::PNG->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertSame([], $result->gameList);
    }

    #[Group('ac-11 [自動] 三方 HTTP 錯誤時拋例外讓排程失敗可見，既有遊戲不變')]
    #[TestDox('getGameList 三方 HTTP 層錯誤時拋出例外，讓排程吵出來而不是靜默空清單')]
    #[Group('rules/game-list-sync#R-24')]
    public function test_get_game_list_throws_on_http_error(): void
    {
        Http::fake([
            '*/dct/getGameList' => Http::response('Service Unavailable', 503),
        ]);

        $driver = new Driver(VendorIdEnum::PNG->value, null);

        $this->expectException(RequestException::class);

        $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));
    }
}
