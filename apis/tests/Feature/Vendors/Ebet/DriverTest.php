<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Ebet;

use App\Data\Vendor\GetGameListParamsData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldSyncGameList;
use App\Vendors\Ebet\Driver;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

#[Group('ticket-4405')]
class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.ebet.test/api/';

    private const string CHANNEL_ID = '4381';

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    /** 測試用 RSA 公鑰（PEM），驗證 Driver 送出的簽章 */
    private string $publicKey;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::EBET->value);

        // 每次產一組拋棄式 RSA 金鑰，不在 repo 留任何真實私鑰
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        openssl_pkey_export($keyPair, $privateKey);
        $this->publicKey = openssl_pkey_get_details($keyPair)['key'];

        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $this->vendor->id)->firstOrFail();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'CHANNEL_ID' => self::CHANNEL_ID,
            'SUB_CHANNEL_ID' => '0',
            'CURRENCY' => 'IDR',
            'PUBLIC_KEY' => $this->publicKey,
            'PRIVATE_KEY' => $privateKey,
            'ANDROID_DOWNLOAD_LINK' => '',
            'IOS_DOWNLOAD_LINK' => '',
            'HTML5_URL' => 'https://h5.ebet.test/',
        ];
        $this->vendorInfo->save();
    }

    #[Group('ac-1 [自動] 六家 driver 掛上自動同步標記，每小時排程自動向三方抓清單')]
    #[TestDox('Ebet Driver 標記 ShouldSyncGameList，納入 update_game_list 自動排程')]
    #[Group('rules/game-list-sync#R-2')]
    public function test_driver_is_marked_for_scheduled_game_list_sync(): void
    {
        $driver = new Driver(VendorIdEnum::EBET->value, null);

        $this->assertInstanceOf(ShouldSyncGameList::class, $driver);
    }

    #[Group('ac-3 [自動] 三方有、資料庫沒有的遊戲會被新增')]
    #[Group('ac-4 [自動] 缺遊戲代碼的列不新增，也不影響其他列')]
    #[Group('ac-7 [自動] Ebet：只收老虎機；備註存供應商代碼；無圖；中英文名進多語名稱；新遊戲為停用')]
    #[Group('ac-9 [自動] 抓清單請求的簽章正確，三方不回簽名錯誤')]
    #[TestDox('getGameList 以 channelId + timestamp 的 RSA 簽章請求 slotgamelist，並把 providerId 落 remark、中英文名落多語名稱')]
    #[Group('rules/game-list-sync#R-34')]
    public function test_get_game_list_maps_response_and_signs_request(): void
    {
        Http::fake([
            '*/slotgamelist' => Http::response([
                'status' => 200,
                'apiVersion' => '1.3.51',
                'slotGames' => [
                    [
                        'gameID' => 'gen_001',
                        'english' => 'Golden Dragon',
                        'chinese' => '金龙',
                        'providerId' => 'genesis',
                    ],
                    [
                        // 沒有中文名時只寫英文
                        'gameID' => 'rad_002',
                        'english' => 'Radiant Fruits',
                        'chinese' => '',
                        'providerId' => 'radi8',
                    ],
                    [
                        // 缺 gameID 的列不納入清單
                        'english' => 'No Id Game',
                        'providerId' => 'bunfox',
                    ],
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::EBET->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(2, $result->gameList);

        $game = $result->gameList['gen_001'];
        $this->assertSame('Golden Dragon', $game->gameName);
        $this->assertSame(GameTypeEnum::SLOTS->value, $game->gameType);
        $this->assertSame('genesis', $game->remark);
        $this->assertSame(0, $game->status);
        $this->assertSame('', $game->imageUrl);
        $this->assertSame('Golden Dragon', $game->gameNames[LanguageEnum::EN->value]);
        $this->assertSame('金龙', $game->gameNames[LanguageEnum::ZH_CN->value]);

        $englishOnly = $result->gameList['rad_002'];
        $this->assertSame('radi8', $englishOnly->remark);
        $this->assertArrayNotHasKey(LanguageEnum::ZH_CN->value, $englishOnly->gameNames);

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $signed = openssl_verify(
                self::CHANNEL_ID . $body['timestamp'],
                base64_decode($body['signature']),
                $this->publicKey,
                OPENSSL_ALGO_MD5,
            );

            return str_ends_with($request->url(), '/slotgamelist')
                && $body['channelId'] === self::CHANNEL_ID
                && $signed === 1;
        });
    }

    #[Group('ac-10 [自動] 三方業務碼非成功時回空清單並記 error，既有遊戲不變')]
    #[TestDox('getGameList 三方回應 status 非 200 時回傳空清單，不拋例外')]
    #[Group('rules/game-list-sync#R-23')]
    public function test_get_game_list_returns_empty_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/slotgamelist' => Http::response([
                'status' => 4026,
                'apiVersion' => '1.3.51',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::EBET->value, null);

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
            '*/slotgamelist' => Http::response('Service Unavailable', 503),
        ]);

        $driver = new Driver(VendorIdEnum::EBET->value, null);

        $this->expectException(RequestException::class);

        $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));
    }
}
