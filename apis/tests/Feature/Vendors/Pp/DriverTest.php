<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Pp;

use App\Data\InsertedBetRecordData;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\BetRecordFetchService;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\PpAccount;
use App\Vendors\Models\PpBetRecord;
use App\Vendors\Pp\Driver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * PragmaticPlay（vendor 54）拉注單。
 *
 * php artisan test tests/Feature/Vendors/Pp/DriverTest.php
 */
class DriverTest extends TestCase
{
    use DatabaseTransactions;

    private const string BET_URL = 'https://pp-datafeeds.test/IntegrationService/v3/';

    private const string FEED_URL = 'https://pp-datafeeds.test/IntegrationService/v3/DataFeeds/gamerounds/finished/';

    private const array INFO = [
        'API_URL' => 'https://pp-api.test',
        'BET_URL' => self::BET_URL,
        'SECURELOGIN' => 'test-login',
        'SECRETKEY' => 'test-secret',
    ];

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::PP->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::PP->value]);
        }

        $this->driver = new Driver(VendorIdEnum::PP->value, null);
    }

    /**
     * @param  array<int, string>  $rows
     */
    private function csv(array $rows): string
    {
        $header = 'playSessionID,extPlayerID,gameID,startDate,endDate,bet,win,roundDetails';

        // 真實回應第 0 行是新的 timepoint，第 1 行才是標題（api.md「DataFeeds」章節）
        return implode("\n", ['timepoint=1755648600000', $header, ...$rows]);
    }

    public function test_driver_implements_supports_bet_record_fetch(): void
    {
        $this->assertInstanceOf(SupportsBetRecordFetch::class, $this->driver);
        $this->assertSame('bet_id', $this->driver->betRecordUniqueKey());
        // 注單的 extPlayerID 寫進 username，會員由 pp_accounts.username 解析
        $this->assertSame('username', $this->driver->betRecordAccountColumn());
        // 憑證鍵取 SECURELOGIN：同一組登入回同一批注單，同片只拉一次
        $this->assertSame('test-login', $this->driver->betRecordCredentialKey(self::INFO));
        // 三方一次只回 timepoint 之後 10 分鐘內的注單
        $this->assertSame(10, $this->driver->betRecordApiWindowMinutes());
        $this->assertNull($this->driver->betRecordMinStartTime());

        $rateLimit = $this->driver->betRecordApiRateLimit();
        $this->assertArrayHasKey('max_calls', $rateLimit);
        $this->assertArrayHasKey('per_seconds', $rateLimit);
    }

    public function test_fetch_calls_the_feed_once_per_data_type(): void
    {
        Http::fake([self::FEED_URL . '*' => Http::response($this->csv([]))]);

        // 切片由 BetRecordFetchService 負責，driver 只拉一片：RNG 與 LC 各一次
        $this->driver->setInfo(self::INFO);
        $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:10:00');

        Http::assertSentCount(2);

        $timepoint = Carbon::parse('2026-08-20 00:00:00', 'UTC')->getTimestampMs();

        // RNG 不帶 dataType，LC 要帶並加 addRoundDetails
        Http::assertSent(fn ($request) => (int) $request['timepoint'] === $timepoint
            && $request['login'] === 'test-login'
            && !isset($request['dataType']));
        Http::assertSent(fn ($request) => ($request['dataType'] ?? null) === 'LC'
            && ($request['options'] ?? null) === 'addRoundDetails');
    }

    public function test_fetch_parses_csv_and_attaches_data_type(): void
    {
        Http::fake([
            self::FEED_URL . '*' => Http::response($this->csv([
                'PS-1,player01,vs20fruit,2026-08-20 00:01:00,2026-08-20 00:02:00,100,250,',
            ])),
        ]);

        $this->driver->setInfo(self::INFO);
        $records = $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:10:00');

        // 兩種 dataType 各回一筆
        $this->assertCount(2, $records);
        $this->assertSame('PS-1', $records[0]['playSessionID']);
        $this->assertSame('RNG', $records[0]['data_type']);
        $this->assertSame('LC', $records[1]['data_type']);
    }

    /**
     * 設定錯誤要炸出來：回空陣列會被當成「該時段無注單」，該片被標記完成而永久缺帳
     */
    #[Group('rules/bet-record-pull#R-18')]
    public function test_fetch_throws_when_bet_url_is_missing(): void
    {
        Http::fake();

        $this->expectException(ApiException::class);

        $this->driver->setInfo([...self::INFO, 'BET_URL' => '']);
        $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:10:00');
    }

    /**
     * 429 重試耗盡同理：不能以空陣列偽裝成無注單
     */
    public function test_fetch_throws_when_rate_limited_retries_are_exhausted(): void
    {
        Http::fake([self::FEED_URL . '*' => Http::response('', 429)]);
        Sleep::fake();

        $this->expectException(ApiException::class);

        $this->driver->setInfo(self::INFO);
        $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:10:00');
    }

    public function test_format_bet_record_restores_rate_and_derives_win_lose(): void
    {
        $record = $this->driver->formatBetRecord([
            'playSessionID' => 'PS-1',
            'extPlayerID' => 'player01',
            'gameID' => 'vs20fruit',
            'startDate' => '2026-08-20 00:01:00',
            'endDate' => '2026-08-20 00:02:00',
            'bet' => '100',
            'win' => '250',
            'data_type' => 'RNG',
        ], '2.0000');

        // 三方金額已乘上匯率，取回要除掉
        $this->assertSame('50.0000', $record['bet']);
        // 三方給的是派彩（125），輸贏＝派彩 - 投注
        $this->assertSame('75.0000', $record['win_lose']);
        $this->assertSame('PS-1', $record['bet_id']);
        $this->assertSame('player01', $record['username']);
        $this->assertSame('vs20fruit', $record['game_code']);
        $this->assertSame('2026-08-20 00:02:00', $record['end_time']);
        $this->assertSame(1, $record['bet_status']);
    }

    /**
     * rate 為 0 或空時退回 1，不能拿 0 去除
     */
    public function test_format_bet_record_falls_back_when_rate_is_not_positive(): void
    {
        $record = $this->driver->formatBetRecord([
            'playSessionID' => 'PS-1',
            'extPlayerID' => 'player01',
            'gameID' => 'vs20fruit',
            'startDate' => '2026-08-20 00:01:00',
            'endDate' => '2026-08-20 00:02:00',
            'bet' => '100',
            'win' => '250',
        ], '0');

        $this->assertSame('100.0000', $record['bet']);
        $this->assertSame('150.0000', $record['win_lose']);
    }

    /**
     * 取消單金額歸零：狀態變化反映在金額上，對帳只看金額就認得出來
     */
    #[Group('rules/bet-record-pull#R-48')]
    public function test_format_bet_record_zeroes_cancelled_rounds(): void
    {
        $record = $this->driver->formatBetRecord([
            'playSessionID' => 'PS-2',
            'extPlayerID' => 'player01',
            'gameID' => 'vs20fruit',
            'startDate' => '2026-08-20 00:01:00',
            'endDate' => '2026-08-20 00:02:00',
            'bet' => '100',
            'win' => '300',
            'roundDetails' => '{"result":"Betcancelled"}',
        ], '2.0000');

        $this->assertSame(0, $record['bet_status']);
        $this->assertSame('0.0000', $record['bet']);
        $this->assertSame('0.0000', $record['win_lose']);
        // 原始金額仍留在 info 裡，需要時查得回來
        $this->assertStringContainsString('"bet":"100"', (string) $record['info']);
    }

    /**
     * 真人局 status=F 即取消（PP 確認真人局不會自動完成），不論派彩多少都歸零
     */
    #[Group('rules/bet-record-pull#R-48')]
    public function test_format_bet_record_zeroes_cancelled_live_casino_rounds(): void
    {
        $record = $this->driver->formatBetRecord([
            'playSessionID' => '344267979815008',
            'extPlayerID' => 'player01',
            'gameID' => '2101',
            'startDate' => '2026-10-03 10:00:35',
            'endDate' => '2026-10-03 10:00:59',
            'status' => 'F',
            'bet' => '10.00',
            'win' => '10.00',
            'data_type' => 'LC',
        ], '1.00000');

        $this->assertSame(0, $record['bet_status']);
        $this->assertSame('0.0000', $record['bet']);
        $this->assertSame('0.0000', $record['win_lose']);
    }

    /**
     * RNG 局 status=F 是自動完成，有真實輸贏，照一般注單入帳
     */
    #[Group('rules/bet-record-pull#R-49')]
    public function test_format_bet_record_keeps_auto_finalized_rng_rounds(): void
    {
        $record = $this->driver->formatBetRecord([
            'playSessionID' => 'PS-4',
            'extPlayerID' => 'player01',
            'gameID' => 'vs20fruit',
            'startDate' => '2026-10-03 10:00:35',
            'endDate' => '2026-10-03 10:00:59',
            'status' => 'F',
            'bet' => '10.00',
            'win' => '4.50',
            'data_type' => 'RNG',
        ], '1.00000');

        $this->assertSame(1, $record['bet_status']);
        $this->assertSame('10.0000', $record['bet']);
        $this->assertSame('-5.5000', $record['win_lose']);
    }

    /**
     * 真人局已完成（C）的和局派彩剛好等於投注，仍是有效投注
     */
    #[Group('rules/bet-record-pull#R-49')]
    public function test_format_bet_record_keeps_completed_live_casino_rounds_where_win_equals_bet(): void
    {
        $record = $this->driver->formatBetRecord([
            'playSessionID' => 'PS-5',
            'extPlayerID' => 'player01',
            'gameID' => '2101',
            'startDate' => '2026-10-03 10:00:35',
            'endDate' => '2026-10-03 10:00:59',
            'status' => 'C',
            'bet' => '10.00',
            'win' => '10.00',
            'data_type' => 'LC',
        ], '1.00000');

        $this->assertSame(1, $record['bet_status']);
        $this->assertSame('10.0000', $record['bet']);
        $this->assertSame('0.0000', $record['win_lose']);
    }

    public function test_format_base_total_bet_record_uses_end_time(): void
    {
        $raw = [
            'playSessionID' => 'PS-3',
            'extPlayerID' => 'player01',
            'gameID' => 'vs20fruit',
            'startDate' => '2026-08-20 00:01:00',
            'endDate' => '2026-08-20 01:30:00',
            'bet' => '100',
            'win' => '250',
        ];

        $base = $this->driver->formatBaseTotalBetRecord($this->inserted($raw));

        $this->assertNotNull($base);
        // 對齊 SP：payout_time 取 end_time（summary_time 由 service 取其整點）
        $this->assertSame('2026-08-20 01:30:00', $base->payoutTime);
        $this->assertSame('2026-08-20 00:01:00', $base->betTime);
        $this->assertSame('PS-3', $base->referenceNo);
        $this->assertSame('player01', $base->accountKey);
        $this->assertSame('vs20fruit', $base->gameCode);
        $this->assertSame('50.0000', $base->bet);
        $this->assertSame('50.0000', $base->validBet);
        $this->assertSame('75.0000', $base->winLose);

        // 取消單的金額已在 formatBetRecord 歸零，TBR 沿用；列仍保留
        $cancelled = $this->driver->formatBaseTotalBetRecord(
            $this->inserted([...$raw, 'roundDetails' => '{"result":"Betcancelled"}']),
        );

        $this->assertNotNull($cancelled);
        $this->assertSame('0.0000', $cancelled->bet);
        $this->assertSame('0.0000', $cancelled->validBet);
        $this->assertSame('0.0000', $cancelled->winLose);
    }

    public function test_data_type_can_be_limited_by_vendor_info(): void
    {
        Http::fake([self::FEED_URL . '*' => Http::response($this->csv([]))]);

        $this->driver->setInfo([...self::INFO, 'DATA_TYPE' => 'LC']);
        $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:10:00');

        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => ($request['dataType'] ?? null) === 'LC');
    }

    /**
     * 端到端：經 BetRecordFetchService 拉一片，注單寫進 pp_bet_records 並轉入 TBR。
     * 唯一鍵、帳號對應欄位、TBR 欄位映射任一接錯，這裡就會失敗。
     */
    public function test_fetch_chunk_inserts_bet_records_and_transfers_to_total_bet_records(): void
    {
        // pp_bet_records.bet_id 是 bigint，三方的 playSessionID 本來就是數字
        $betId = (string) random_int(1000000000, 9999999999);
        $username = 'player' . random_int(100000, 999999);

        Vendor::where('id', VendorIdEnum::PP->value)->update(['status' => 1, 'valid_bet_rate' => 1, 'is_single_wallet' => 0]);

        $vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::PP->value,
            'status' => 1,
            'rate' => 2,
            'info' => [...self::INFO, 'DATA_TYPE' => 'RNG'],
        ]);

        PpAccount::create([
            'vendor_info_id' => $vendorInfo->id,
            'username' => $username,
            'player_id' => $username,
            'member_id' => 8888,
        ]);

        $game = Game::factory()->create([
            'vendor_id' => VendorIdEnum::PP->value,
            'game_type' => 'vs20fruit',
            'valid_bet_rate' => 0.5,
        ]);

        Http::fake([
            self::FEED_URL . '*' => Http::response($this->csv([
                sprintf('%s,%s,vs20fruit,2026-08-20 00:01:00,2026-08-20 00:02:00,100,250,', $betId, $username),
            ])),
        ]);
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::PP->value);

        $result = app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::PP->value,
            $this->driver,
            '2026-08-20 00:00:00',
            '2026-08-20 00:10:00',
        );

        $this->assertSame(1, $result->inserted);

        $betRecord = PpBetRecord::where('bet_id', $betId)->first();
        $this->assertNotNull($betRecord);
        $this->assertSame($username, $betRecord->username);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => VendorIdEnum::PP->value,
            'bet_record_id' => $betRecord->id,
            'member_id' => 8888,
            'game_id' => $game->id,
            'reference_no' => $betId,
            // rate = 2：三方的 100 / 250 還原成 50 與 125，輸贏 75
            'bet' => '50.0000',
            'valid_bet' => '50.0000',
            'win_lose' => '75.0000',
            'valid_bet_rate' => '0.5000',
            'summary_time' => '2026-08-20 00:00:00',
            'bet_time' => '2026-08-20 00:01:00',
            'payout_time' => '2026-08-20 00:02:00',
        ]);
    }

    /**
     * 更新模式端到端：已入庫的正常單之後被取消，重拉帶 update 時
     * BR 金額歸零、TBR 以 (vendor_id, bet_record_id) upsert 一併歸零；
     * 不帶 update 則維持純新增，既有列不動。
     */
    #[Group('rules/bet-record-pull#R-13')]
    #[Group('rules/bet-record-pull#R-50')]
    public function test_fetch_chunk_with_update_zeroes_cancelled_rounds(): void
    {
        $betId = (string) random_int(1000000000, 9999999999);
        $username = 'player' . random_int(100000, 999999);

        Vendor::where('id', VendorIdEnum::PP->value)->update(['status' => 1, 'valid_bet_rate' => 1, 'is_single_wallet' => 0]);

        $vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::PP->value,
            'status' => 1,
            'rate' => 2,
            'info' => [...self::INFO, 'DATA_TYPE' => 'RNG'],
        ]);

        PpAccount::create([
            'vendor_info_id' => $vendorInfo->id,
            'username' => $username,
            'player_id' => $username,
            'member_id' => 8888,
        ]);

        $row = sprintf('%s,%s,vs20fruit,2026-08-20 00:01:00,2026-08-20 00:02:00,100,250,%%s', $betId, $username);
        $normalCsv = $this->csv([sprintf($row, '')]);
        $cancelledCsv = $this->csv([sprintf($row, '"{""result"":""Betcancelled""}"')]);

        // Http::fake 疊加註冊時先註冊者先匹配，三輪回應須以 sequence 一次排好
        Http::fake([self::FEED_URL . '*' => Http::sequence()
            ->push($normalCsv)
            ->push($cancelledCsv)
            ->push($cancelledCsv),
        ]);

        // 第一輪：正常單入庫
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::PP->value);
        app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::PP->value,
            $this->driver,
            '2026-08-20 00:00:00',
            '2026-08-20 00:10:00',
        );

        $betRecord = PpBetRecord::where('bet_id', $betId)->firstOrFail();
        $this->assertSame('50.0000', (string) $betRecord->bet);

        // 第二輪：同回合變取消單。不帶 update → 純新增去重，既有列不動
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::PP->value);
        $result = app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::PP->value,
            $this->driver,
            '2026-08-20 00:00:00',
            '2026-08-20 00:10:00',
        );

        $this->assertSame(0, $result->updated);
        $this->assertSame('50.0000', (string) $betRecord->fresh()->bet);

        // 帶 update → 金額異動被認出，BR 歸零、TBR 重轉歸零
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::PP->value);
        $result = app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::PP->value,
            $this->driver,
            '2026-08-20 00:00:00',
            '2026-08-20 00:10:00',
            updateExisting: true,
        );

        $this->assertSame(1, $result->updated);

        $betRecord->refresh();
        $this->assertSame('0.0000', (string) $betRecord->bet);
        $this->assertSame(0, (int) $betRecord->bet_status);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => VendorIdEnum::PP->value,
            'bet_record_id' => $betRecord->id,
            'reference_no' => $betId,
            'bet' => '0.0000',
            'win_lose' => '0.0000',
        ]);
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function inserted(array $raw): InsertedBetRecordData
    {
        $attributes = $this->driver->formatBetRecord($raw, '2.0000');

        return new InsertedBetRecordData((string) $attributes['bet_id'], $attributes);
    }
}
