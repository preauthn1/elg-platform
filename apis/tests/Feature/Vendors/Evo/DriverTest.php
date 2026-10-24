<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Evo;

use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\Vendor\GetGameListParamsData;
use App\Enums\Game\GameTypeEnum;
use App\Enums\LanguageEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Evo\Driver;
use App\Vendors\Models\EvoAccount;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.titanhub.test';

    private const string AGENT_ID = 'titan-agent-id';

    private const string PROVIDER = '47';

    /** EVO（PROVIDER 12）屬單一入口（isLobby） */
    private const array INFO = [
        'API_URL' => self::API_URL,
        'AGENT_ID' => 'test-agent',
        'PROVIDER' => '12',
    ];

    private Agent $agent;

    private Vendor $vendor;

    private VendorInfo $vendorInfo;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        if (!Vendor::find(VendorIdEnum::TITAN_EVO->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::TITAN_EVO->value]);
        }
        $this->driver = new Driver(VendorIdEnum::TITAN_EVO->value, null);

        $this->agent = $this->createAgentAndDomain();

        $this->vendor = $this->createBasicVendorGames($this->agent, VendorIdEnum::TITAN_UUS->value);
        $this->vendor->save();

        $this->vendorInfo = VendorInfo::query()->where('vendor_id', $this->vendor->id)->firstOrFail();
        $this->vendorInfo->status = 1;
        $this->vendorInfo->info = [
            'API_URL' => self::API_URL,
            'AGENT_ID' => self::AGENT_ID,
            'PROVIDER' => self::PROVIDER,
        ];
        $this->vendorInfo->save();
    }

    #[TestDox('Driver 僅實作能力契約 SupportsBetRecordFetch（不掛排程 marker，僅供手動補拉）：唯一鍵 bet_id、帳號欄位 username、憑證鍵 AGENT_ID|PROVIDER')]
    public function test_driver_implements_supports_bet_record_fetch_without_schedule_marker(): void
    {
        $this->assertInstanceOf(SupportsBetRecordFetch::class, $this->driver);
        $this->assertNotInstanceOf(ShouldScheduleBetRecordFetch::class, $this->driver);
        $this->assertSame('bet_id', $this->driver->betRecordUniqueKey());
        $this->assertSame('username', $this->driver->betRecordAccountColumn());
        $this->assertNull($this->driver->betRecordMinStartTime());
        // 聚合遊戲商各代理組共用同組 AGENT_ID + PROVIDER 時同片只拉一次
        $this->assertSame('test-agent|12', $this->driver->betRecordCredentialKey(self::INFO));

        $rateLimit = $this->driver->betRecordApiRateLimit();
        $this->assertArrayHasKey('max_calls', $rateLimit);
        $this->assertArrayHasKey('per_seconds', $rateLimit);
    }

    #[TestDox('fetchBetRecords 以 UTC+8 時間範圍分頁撈完所有頁')]
    public function test_fetch_bet_records_walks_through_all_pages_with_utc8_range(): void
    {
        Http::fake([
            self::API_URL . '/Report/TransactionDetail' => Http::sequence()
                ->push(['code' => 1, 'totalPage' => 2, 'data' => [['id' => 'A1']]])
                ->push(['code' => 1, 'totalPage' => 2, 'data' => [['id' => 'A2']]]),
        ]);

        $this->driver->setInfo(self::INFO);
        $records = $this->driver->fetchBetRecords('2026-08-25 03:00:00', '2026-08-25 04:00:00');

        $this->assertSame(['A1', 'A2'], array_column($records, 'id'));

        Http::assertSent(fn ($request) => $request['FromDate'] === '2026-08-25T11:00:00+08:00'
            && $request['ToDate'] === '2026-08-25T12:00:00+08:00'
            && $request['AgentId'] === 'test-agent'
            && $request['GameProviderCode'] === '12');
    }

    #[TestDox('fetchBetRecords 三方回錯誤碼時直接拋例外，由呼叫端處理')]
    public function test_fetch_bet_records_throws_on_error_code(): void
    {
        Http::fake([
            self::API_URL . '/Report/TransactionDetail' => Http::response(['code' => 5, 'message' => 'INVALID_AGENT']),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('INVALID_AGENT');

        $this->driver->setInfo(self::INFO);
        $this->driver->fetchBetRecords('2026-08-25 03:00:00', '2026-08-25 04:00:00');
    }

    #[TestDox('formatBetRecord 投注額取 turnover、win_lose 反向為公司視角，三方即平台點數不做 rate 換算')]
    public function test_format_bet_record_uses_turnover_as_bet(): void
    {
        $this->driver->setInfo(self::INFO);

        $record = $this->driver->formatBetRecord([
            'id' => 'A1',
            'loginId' => 'Y2WEVO00000001',
            'gameCode' => 'baccarat',
            'bet' => 100.5,
            'turnover' => 80.25,
            'winlose' => 5.5,
            'endDate' => '2026-08-25T12:00:00+08:00',
        ], '2');

        $this->assertSame('A1', $record['bet_id']);
        $this->assertSame('Y2WEVO00000001', $record['username']);
        // 單一入口取 PROVIDER，不取三方 gameCode
        $this->assertSame('12', $record['game_code']);
        // 投注額取 turnover（有效投注），不是三方的 bet；rate 參數不參與換算
        $this->assertSame('80.2500', $record['bet']);
        // 三方 winlose 為玩家視角，入庫需反向
        $this->assertSame('-5.5000', $record['win_lose']);
        $this->assertSame('2026-08-25 04:00:00', $record['bet_time']);
    }

    #[TestDox('formatBetRecord 非大廳型 provider 的 game_code 取三方 gameCode')]
    public function test_format_bet_record_uses_vendor_game_code_when_not_lobby(): void
    {
        $this->driver->setInfo(['API_URL' => self::API_URL, 'AGENT_ID' => 'test-agent', 'PROVIDER' => '13']);

        $record = $this->driver->formatBetRecord([
            'id' => 'A2',
            'loginId' => 'Y2WL400000001',
            'gameCode' => 'slot-001',
            'bet' => 100.5,
            'turnover' => 80.25,
            'winlose' => -3,
            'endDate' => '2025-01-01T08:00:00+08:00',
        ], '1');

        $this->assertSame('slot-001', $record['game_code']);
        $this->assertSame('80.2500', $record['bet']);
        $this->assertSame('3.0000', $record['win_lose']);
        $this->assertSame('2025-01-01 00:00:00', $record['bet_time']);
    }

    #[TestDox('formatBaseTotalBetRecord 以注單列組 TBR 基礎欄位，三方只給一個時間故 payout_time 同 bet_time')]
    public function test_format_base_total_bet_record_maps_inserted_row(): void
    {
        $this->driver->setInfo(self::INFO);

        $row = $this->driver->formatBetRecord([
            'id' => 'A1',
            'loginId' => 'Y2WEVO00000001',
            'gameCode' => 'baccarat',
            'bet' => 100.5,
            'turnover' => 80.25,
            'winlose' => 5.5,
            'type' => 3,
            'endDate' => '2026-08-25T12:30:00+08:00',
        ], '1');

        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('A1', $row));

        $this->assertNotNull($base);
        $this->assertSame('Y2WEVO00000001', $base->accountKey);
        $this->assertSame('12', $base->gameCode);
        $this->assertSame('A1', $base->referenceNo);
        $this->assertSame('80.2500', $base->bet);
        $this->assertSame('80.2500', $base->validBet);
        $this->assertSame('-5.5000', $base->winLose);
        $this->assertSame('2026-08-25 04:30:00', $base->betTime);
        $this->assertSame('2026-08-25 04:30:00', $base->payoutTime);
    }

    #[TestDox('formatBaseTotalBetRecord type = 0 活動單（免費旋轉派彩）照常轉入，不比照 SP 排除')]
    public function test_format_base_total_bet_record_keeps_zero_type_campaign_records(): void
    {
        $this->driver->setInfo(self::INFO);

        // type = 0 是活動單：bet / turnover 為 0、只有玩家淨贏。
        // SP_transfer_bet_record_evo 會排除它們，這裡刻意保留並照常規 mapping。
        $row = $this->driver->formatBetRecord([
            'id' => 'CP-1',
            'loginId' => 'Y2WEVO00000001',
            'gameCode' => 'CP_fuvMwwrIMb4sY3_FS',
            'bet' => 0,
            'turnover' => 0,
            // 三方 winlose 為公司視角（負 = 賠給玩家），入庫翻為玩家視角
            'winlose' => -1.782,
            'type' => 0,
            'endDate' => '2026-08-25T12:30:00+08:00',
        ], '1');

        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('CP-1', $row));

        $this->assertNotNull($base);
        $this->assertSame('0.0000', $base->bet);
        $this->assertSame('0.0000', $base->validBet);
        $this->assertSame('1.7820', $base->winLose);
    }

    #[TestDox('getGameList 以 AgentId 與 PROVIDER 請求 /Game/GetList 並映射多語名稱與遊戲類型')]
    #[Group('rules/game-list-sync#R-36')]
    public function test_get_game_list_maps_response_and_calls_correct_endpoint(): void
    {
        Http::fake([
            '*/Game/GetList' => Http::response([
                'code' => 1,
                'data' => [
                    [
                        'code' => 'UUS-SLOT-001',
                        'name' => [
                            'EN' => 'Golden Dragon',
                            'ZH' => '黃金龍',
                            'CN' => '黄金龙',
                            'TH' => 'มังกรทอง',
                        ],
                        'type' => '2',
                        'gameIcon' => 'https://cdn.titanhub.test/uus/slot-001.png',
                    ],
                    [
                        'code' => 'UUS-SPORT-001',
                        'name' => [
                            'EN' => 'Virtual Football',
                        ],
                        'type' => '4',
                    ],
                    [
                        'code' => 'UUS-UNKNOWN-001',
                        'name' => [
                            'EN' => 'Mystery Game',
                        ],
                        'type' => '99',
                    ],
                ],
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::TITAN_UUS->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(3, $result->gameList);

        $slot = $result->gameList['UUS-SLOT-001'];
        $this->assertSame('Golden Dragon', $slot->gameName);
        $this->assertSame(GameTypeEnum::SLOTS->value, $slot->gameType);
        $this->assertSame('https://cdn.titanhub.test/uus/slot-001.png', $slot->imageUrl);
        $this->assertSame(1, $slot->status);
        $this->assertSame([
            LanguageEnum::EN->value => 'Golden Dragon',
            LanguageEnum::ZH_TW->value => '黃金龍',
            LanguageEnum::ZH_CN->value => '黄金龙',
            LanguageEnum::TH_TH->value => 'มังกรทอง',
        ], $slot->gameNames);

        $sport = $result->gameList['UUS-SPORT-001'];
        $this->assertSame(GameTypeEnum::SPORTS->value, $sport->gameType);
        $this->assertSame('', $sport->imageUrl);

        // 未列於映射表的類型落回 0（未分類）
        $this->assertSame(0, $result->gameList['UUS-UNKNOWN-001']->gameType);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/Game/GetList')
                && $request['AgentId'] === self::AGENT_ID
                && $request['GameProviderCode'] === self::PROVIDER;
        });
    }

    #[TestDox('getGameList 三方回應 code 非 1 時回傳空清單')]
    #[Group('rules/game-list-sync#R-23')]
    public function test_get_game_list_returns_empty_when_vendor_api_fails(): void
    {
        Http::fake([
            '*/Game/GetList' => Http::response([
                'code' => 7,
                'message' => 'INVALID_GAME_PROVIDER',
            ]),
        ]);

        $driver = new Driver(VendorIdEnum::TITAN_UUS->value, null);

        $result = $driver->getGameList(GetGameListParamsData::from([
            'vendorInfo' => $this->vendorInfo,
        ]));

        $this->assertCount(0, $result->gameList);
    }

    #[TestDox('建號在 insert 當下就帶 password：evo_accounts 的 password 為 NOT NULL 無 default，漏帶會被 DB 以 1364 擋下整筆補建')]
    #[Group('rules/vendor-account-pool#R-13')]
    public function test_create_account_persists_row_with_password(): void
    {
        Http::fake(['*/User/Register' => Http::response(['code' => '1'])]);

        $this->vendorInfo->account_prefix = 'UUS';
        $this->vendorInfo->save();

        $result = (new Driver(VendorIdEnum::TITAN_UUS->value, null))->createAccount(
            CreateAccountParamsData::from([
                'vendorInfo' => $this->vendorInfo,
                'serialNumber' => 1,
                'username' => 'UUS00000002',
            ]),
        );

        $this->assertTrue($result->status);
        $this->assertSame('UUS00000002', $result->username);

        $account = EvoAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'UUS00000002')
            ->first();

        $this->assertNotNull($account, '本地 evo_accounts 必須建立 row');
        $this->assertNotSame('', (string) $account->password, 'password 必須在 insert 當下帶值，不能依賴 DB default');
        $this->assertSame(0, (int) $account->member_id, '尚未綁定會員時 member_id 應為 0');
    }

    #[TestDox('三方建號失敗時本地 row 保留並軟刪除（member_id = -999999999），password 仍有值')]
    #[Group('rules/vendor-account-pool#R-13')]
    public function test_create_account_soft_deletes_row_when_vendor_api_fails(): void
    {
        Http::fake(['*/User/Register' => Http::response(['code' => '0'])]);

        $this->vendorInfo->account_prefix = 'UUS';
        $this->vendorInfo->save();

        $result = (new Driver(VendorIdEnum::TITAN_UUS->value, null))->createAccount(
            CreateAccountParamsData::from([
                'vendorInfo' => $this->vendorInfo,
                'serialNumber' => 2,
                'username' => 'UUS00000003',
            ]),
        );

        $this->assertFalse($result->status);

        $account = EvoAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'UUS00000003')
            ->first();

        $this->assertNotNull($account, '三方失敗時本地 row 應保留（軟刪除，非硬刪）');
        $this->assertSame(-999999999, (int) $account->member_id);
        $this->assertNotSame('', (string) $account->password);
    }
}
