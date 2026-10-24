<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\PegasusSW;

use App\Data\Vendor\GetGameListParamsData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\PegasusSW\Driver;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.pegasus.test/';

    private const string API_APPID = 'pegasus-merchant-id';

    private const string API_KEY = [REDACTED]';

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::PGSSW->value);
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

    #[TestDox('getGameList 以 merchantID 與大寫 MerchantSecretKey 請求 /games 並映射類型、remark、memo')]
    public function test_get_game_list_maps_response_and_calls_correct_endpoint(): void
    {
        Http::fake([
            '*/games*' => Http::response([
                'code' => 0,
                'data' => [
                    [
                        'gameID' => 290089,
                        'gameName' => 'Golden Fortune',
                        'gameType' => 1000,
                        'imageUrl' => 'https://cdn.pegasus.test/290089.png',
                        'gameCode' => 'GoldenFortune',
                        'isPrePaidMode' => true,
                    ],
                    [
                        'gameID' => 250001,
                        'gameName' => 'Live Baccarat',
                        'gameType' => 2500,
                        'imageUrl' => 'https://cdn.pegasus.test/250001.png',
                        'gameCode' => 'LiveBaccarat',
                    ],
                    [
                        // 映射表未列的分類碼落回「活動與其他」
                        'gameID' => 999001,
                        'gameName' => 'Unknown Type Game',
                        'gameType' => 9999,
                        'imageUrl' => '',
                    ],
                    [
                        // 缺 gameID 的列不納入清單
                        'gameName' => 'No Id Game',
                        'gameType' => 1000,
                    ],
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::PGSSW->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(3, $result->gameList);

        $slot = $result->gameList['290089'];
        $this->assertSame('Golden Fortune', $slot->gameName);
        $this->assertSame(GameTypeEnum::SLOTS->value, $slot->gameType);
        $this->assertSame('https://cdn.pegasus.test/290089.png', $slot->imageUrl);
        $this->assertSame('GoldenFortune', $slot->remark);
        $this->assertSame('isPrePaidMode', $slot->memo);
        $this->assertSame(1, $slot->status);

        $live = $result->gameList['250001'];
        $this->assertSame(GameTypeEnum::LIVE_CASINO->value, $live->gameType);
        // 未帶 isPrePaidMode 不寫 memo，避免覆蓋內部人員備註
        $this->assertNull($live->memo);

        $this->assertSame(GameTypeEnum::EVENT_AND_OTHERS->value, $result->gameList['999001']->gameType);

        Http::assertSent(function ($request): bool {
            return str_contains($request->url(), '/games')
                && $request['merchantID'] === self::API_APPID
                // 本端點的密鑰參數是大寫開頭，與其他端點不同
                && $request['MerchantSecretKey'] === self::API_KEY;
        });
    }

    #[TestDox('getGameList 依 languages 表對照多語名稱（zh-tw 為 2、zh-cn 為 3），未支援語系略過')]
    public function test_get_game_list_maps_language_ids_by_languages_table(): void
    {
        Http::fake([
            '*/games*' => Http::response([
                'code' => 0,
                'data' => [
                    [
                        'gameID' => 290089,
                        'gameName' => 'Golden Fortune',
                        'gameType' => 1000,
                        'imageUrl' => '',
                        'gameNameMappings' => [
                            ['languageCode' => 'en-us', 'text' => 'Golden Fortune'],
                            ['languageCode' => 'zh-tw', 'text' => '金吉報喜'],
                            ['languageCode' => 'zh-cn', 'text' => '金吉报喜'],
                            ['languageCode' => 'ko-kr', 'text' => '알 수 없음'],
                        ],
                    ],
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::PGSSW->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $gameNames = $result->gameList['290089']->gameNames;

        $this->assertSame('Golden Fortune', $gameNames[LanguageEnum::EN->value]);
        $this->assertSame('金吉報喜', $gameNames[LanguageEnum::ZH_TW->value]);
        $this->assertSame('金吉报喜', $gameNames[LanguageEnum::ZH_CN->value]);
        $this->assertCount(3, $gameNames);
    }

    #[TestDox('getGameList 三方回應 code 非 0 時回傳空清單，不拋例外')]
    public function test_get_game_list_returns_empty_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/games*' => Http::response([
                'code' => 403001,
                'message' => 'invalid merchant',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::PGSSW->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertSame([], $result->gameList);
    }
}
