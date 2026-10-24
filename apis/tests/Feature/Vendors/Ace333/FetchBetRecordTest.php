<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Ace333;

use App\Data\InsertedBetRecordData;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\BetRecordFetchService;
use App\Vendors\Ace333\Driver;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Models\Ace333Account;
use App\Vendors\Models\Ace333BetRecord;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Vendors/Ace333/FetchBetRecordTest.php --compact
 */
#[Group('op_ticket-3039')]
class FetchBetRecordTest extends TestCase
{
    private const array INFO = [
        'API_URL' => 'https://example.invalid',
        'DATAFEED_API_URL' => 'https://example.invalid',
        'TOKEN' => 'test-token',
        // 拉單服務對每組憑證先 setInfo（與錢包操作同慣例），Ace333::setInfo 讀完整商戶欄位
        'H5_SECRETKEY' => 'test-secret',
        'H5_TOKEN_URL' => 'https://example.invalid/token',
        'H5_GAME_URL' => 'https://example.invalid/game',
        'H5_PASSWORD' => 'test-password',
        'H5_ENCRYPTKEY' => 'test-encrypt-key',
        'H5_MD5ENCRYPTKEY' => 'test-md5-key',
        'DELIMITER' => '_',
        'POSTFIX' => 'ut',
        'CURRENCY' => 'MYR',
    ];

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::ACE333->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::ACE333->value]);
        }
        // ACE333 是轉帳錢包：factory 預設 is_single_wallet = 1，會被「單一錢包只放行乾跑」擋下
        Vendor::where('id', VendorIdEnum::ACE333->value)->update(['is_single_wallet' => 0]);

        $this->driver = new Driver(VendorIdEnum::ACE333->value, null);
    }

    #[TestDox('Driver 實作 ShouldScheduleBetRecordFetch：唯一鍵 referenceID、帳號對應欄位與 rate limit 設定齊備')]
    public function test_driver_implements_should_fetch_bet_record(): void
    {
        $this->assertInstanceOf(ShouldScheduleBetRecordFetch::class, $this->driver);
        $this->assertSame('referenceID', $this->driver->betRecordUniqueKey());
        $this->assertSame('player_id', $this->driver->betRecordAccountColumn());
        // 憑證鍵取 TOKEN：共用 Datafeed 憑證的 vendor_info 同片只拉一次
        $this->assertSame('test-token', $this->driver->betRecordCredentialKey(self::INFO));
        // 台灣時間 2026-08-01 00:00 = UTC 2026-07-31 16:00
        $this->assertSame('2026-07-31 16:00:00', $this->driver->betRecordMinStartTime());

        $rateLimit = $this->driver->betRecordApiRateLimit();
        $this->assertArrayHasKey('max_calls', $rateLimit);
        $this->assertArrayHasKey('per_seconds', $rateLimit);
    }

    #[TestDox('fetchBetRecords 走 Datafeed URL 打 accounttransactions3（UTC epoch 毫秒 timepoint），CSV 自第三行起解析')]
    public function test_fetch_bet_records_parses_csv_and_uses_datafeed_url(): void
    {
        // 標題與欄位順序取自三方實際回應：交易類型欄名為 methodType，且 gameID 後多一欄 playSessionID
        $csv = implode("\n", [
            '1754500000000',
            'playerID,extPlayerID,gameID,methodType,playSessionID,referenceID,status,created,updated,betAmount,winAmount,jackpotModule,jackpotContributionAmt,currency,resultUrl,roundDetails,platform',
            '1001,2001,"slot-a","BR","SESSION-1","REF-1","C","2026-08-06 10:00:00","2026-08-06 10:00:05",10.5,0,"","0","MYR","https://r/1","round-1","WEB"',
            '1001,2001,"slot-a","JW","SESSION-2","REF-2","C","2026-08-06 10:01:00","2026-08-06 10:01:05",0,88,"JPM","1.5","MYR","https://r/2","round-2","MOBILE"',
        ]);

        Http::fake(['example.invalid/*' => Http::response($csv)]);

        $this->driver->setInfo(self::INFO);
        $records = $this->driver->fetchBetRecords('2026-08-06 10:00:00', '2026-08-06 11:00:00');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://example.invalid/api/accounttransactions3')
                && $request->hasHeader('token', 'test-token')
                && str_contains($request->url(), 'timepoint=1786010400000');
        });

        $this->assertCount(2, $records);
        $this->assertSame('REF-1', $records[0]['referenceID']);
        $this->assertSame('BR', $records[0]['methodType']);
        $this->assertSame('SESSION-1', $records[0]['playSessionID']);
        $this->assertSame('10.5', $records[0]['betAmount']);
    }

    #[TestDox('fetchBetRecords 三方 HTTP 錯誤時直接拋例外，由呼叫端處理')]
    public function test_fetch_bet_records_throws_on_http_error(): void
    {
        Http::fake(['example.invalid/*' => Http::response('', 500)]);

        $this->expectException(RequestException::class);

        $this->driver->setInfo(self::INFO);
        $this->driver->fetchBetRecords('2026-08-06 10:00:00', '2026-08-06 11:00:00');
    }

    #[TestDox('fetchBetRecords 回應僅 timepoint 與標題兩行（窗口無注單）時回傳空陣列')]
    public function test_fetch_bet_records_returns_empty_when_no_data_rows(): void
    {
        // 只有 timepoint 與標題兩行 = 該窗口無注單
        Http::fake(['example.invalid/*' => Http::response("1754500000000\nplayerID,extPlayerID")]);

        $this->driver->setInfo(self::INFO);
        $records = $this->driver->fetchBetRecords('2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertSame([], $records);
    }

    #[TestDox('formatBetRecord 映射注單欄位並將金額除以 rate 換算平台點數')]
    public function test_format_bet_record_maps_fields_and_divides_by_rate(): void
    {
        $raw = [
            'playerID' => '1001',
            'extPlayerID' => '2001',
            'gameID' => 'slot-a',
            'methodType' => 'BR',
            'referenceID' => 'REF-1',
            'status' => 'C',
            'created' => '2026-08-06 10:00:00',
            'updated' => '2026-08-06 10:00:05',
            'betAmount' => '10.5',
            'winAmount' => '21',
            'jackpotModule' => '',
            'jackpotContributionAmt' => '0',
            'currency' => 'MYR',
            'resultUrl' => 'https://r/1',
            'roundDetails' => 'round-1',
            'platform' => 'WEB',
        ];

        $row = $this->driver->formatBetRecord($raw, '2');

        $this->assertSame('REF-1', $row['referenceID']);
        $this->assertSame('BR', $row['transactionType']);
        $this->assertSame('5.2500', $row['betAmount']);
        $this->assertSame('10.5000', $row['winAmount']);
        $this->assertSame('slot-a', $row['gameID']);
        $this->assertSame('2026-08-06 10:00:00', $row['created']);
        $this->assertSame(0, $row['admin']);
        $this->assertJson($row['info']);
    }

    #[TestDox('formatBetRecord JW（JackpotWin）交易的 gameID 統一記為 jackpot')]
    public function test_format_bet_record_maps_jackpot_game_id(): void
    {
        $raw = [
            'playerID' => '1001',
            'gameID' => 'slot-a',
            'methodType' => 'JW',
            'referenceID' => 'REF-2',
            'status' => 'C',
            'created' => '2026-08-06 10:01:00',
            'updated' => '2026-08-06 10:01:05',
            'betAmount' => '0',
            'winAmount' => '88',
            'jackpotContributionAmt' => '1.5',
        ];

        $row = $this->driver->formatBetRecord($raw, '1');

        $this->assertSame('jackpot', $row['gameID']);
        $this->assertSame('1.5000', $row['jackpotContributionAmt']);
    }

    #[TestDox('formatBetRecord 三方改用舊欄位名 transactionType 時仍取得交易類型')]
    public function test_format_bet_record_falls_back_to_legacy_transaction_type_key(): void
    {
        $row = $this->driver->formatBetRecord([
            'playerID' => '1001',
            'gameID' => 'slot-a',
            'transactionType' => 'BR',
            'referenceID' => 'REF-3',
            'status' => 'C',
            'created' => '2026-08-06 10:00:00',
            'updated' => '2026-08-06 10:00:05',
        ], '1');

        $this->assertSame('BR', $row['transactionType']);
    }

    #[TestDox('formatBetRecord 兩個交易類型欄位皆缺時記 warning，避免注單靜默不轉 TBR')]
    public function test_format_bet_record_warns_when_transaction_type_missing(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message): bool => str_contains($message, '注單交易類型為空'));

        $row = $this->driver->formatBetRecord([
            'playerID' => '1001',
            'gameID' => 'slot-a',
            'referenceID' => 'REF-4',
            'status' => 'C',
            'created' => '2026-08-06 10:00:00',
            'updated' => '2026-08-06 10:00:05',
        ], '1');

        $this->assertSame('', $row['transactionType']);
    }

    #[Group('ac-3 其他一般局（備註是 spin、bet、gamble）投注照三方給的金額記，輸贏是贏的錢減掉投注')]
    #[TestDox('formatBaseTotalBetRecord BR（BetResult）完成單轉 BaseTotalBetRecordData（win_lose = win - bet）')]
    public function test_format_total_bet_record_maps_bet_result_transaction(): void
    {
        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('REF-1', [
            'playerID' => '1001',
            'gameID' => 'slot-a',
            'transactionType' => 'BR',
            'referenceID' => 'REF-1',
            'status' => 'C',
            'created' => '2026-08-06 10:00:00',
            'betAmount' => '10.0000',
            'winAmount' => '25.0000',
            'roundDetails' => 'round-1',
        ]));

        $this->assertNotNull($base);
        $this->assertSame('1001', $base->accountKey);
        $this->assertSame('slot-a', $base->gameCode);
        $this->assertSame('REF-1', $base->referenceNo);
        $this->assertSame('10.0000', $base->bet);
        $this->assertSame('10.0000', $base->validBet);
        $this->assertSame('15.0000', $base->winLose);
        $this->assertSame('2026-08-06 10:00:00', $base->betTime);
        $this->assertSame('2026-08-06 10:00:00', $base->payoutTime);
    }

    #[Group('ac-1 備註只寫 free spin 的那一局是系統送的免費局，投注記 0，輸贏就等於贏的錢')]
    #[TestDox('formatBaseTotalBetRecord roundDetails 完全等於 free spin 的回合不計投注與有效投注，win_lose 為全額 win')]
    public function test_format_total_bet_record_zeroes_bet_on_free_spin(): void
    {
        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('REF-2', [
            'playerID' => '1001',
            'gameID' => 'slot-a',
            'transactionType' => 'BR',
            'referenceID' => 'REF-2',
            'status' => 'C',
            'created' => '2026-08-06 10:00:00',
            'betAmount' => '10.0000',
            'winAmount' => '25.0000',
            'roundDetails' => 'free spin',
        ]));

        $this->assertNotNull($base);
        $this->assertSame('0.0000', $base->bet);
        $this->assertSame('0.0000', $base->validBet);
        $this->assertSame('25.0000', $base->winLose);
    }

    #[Group('ac-2 備註寫 spin,buy free spin 的那一局是玩家花錢買免費旋轉，花的錢算進投注，輸贏是贏的錢減掉花的錢')]
    #[TestDox('formatBaseTotalBetRecord 購買免費旋轉（roundDetails 為 spin,buy free spin）照計投注，win_lose 為 win 減 bet')]
    public function test_format_total_bet_record_counts_bet_on_buy_free_spin(): void
    {
        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('REF-4', [
            'playerID' => '1001',
            'gameID' => 'slot-a',
            'transactionType' => 'BR',
            'referenceID' => 'REF-4',
            'status' => 'C',
            'created' => '2026-09-17 07:35:00',
            'betAmount' => '20.0000',
            'winAmount' => '1.7500',
            'roundDetails' => 'spin,buy free spin',
        ]));

        $this->assertNotNull($base);
        $this->assertSame('20.0000', $base->bet);
        $this->assertSame('20.0000', $base->validBet);
        $this->assertSame('-18.2500', $base->winLose);
    }

    #[TestDox('formatBaseTotalBetRecord BQ 分注請求／RF／TU 或未完成單回傳 null 不轉入')]
    public function test_format_total_bet_record_skips_non_qualifying_rows(): void
    {
        $row = ['playerID' => '1001', 'gameID' => 'slot-a', 'transactionType' => 'TU',
            'referenceID' => 'REF-3', 'status' => 'C', 'created' => '2026-08-06 10:00:00',
            'betAmount' => '10', 'winAmount' => '0', 'roundDetails' => ''];
        $record = fn (array $overrides): InsertedBetRecordData => new InsertedBetRecordData('REF-3', [...$row, ...$overrides]);

        // BQ 分注請求：同局總注已含於 BR，轉入會重複計注
        $this->assertNull($this->driver->formatBaseTotalBetRecord($record(['transactionType' => 'BQ'])));
        $this->assertNull($this->driver->formatBaseTotalBetRecord($record(['transactionType' => 'RF'])));
        $this->assertNull($this->driver->formatBaseTotalBetRecord($record([])));
        $this->assertNull($this->driver->formatBaseTotalBetRecord($record(['transactionType' => 'BR', 'status' => 'I'])));
    }

    #[TestDox('fetch 窗口整段早於可拉取界線（台灣 2026-08-01 00:00）時不打三方 API、不執行任何動作')]
    #[Group('rules/bet-record-pull#R-11')]
    public function test_fetch_skips_window_entirely_before_min_start_time(): void
    {
        Vendor::where('id', VendorIdEnum::ACE333->value)->update(['status' => 1]);
        VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::ACE333->value,
            'status' => 1,
            'rate' => 1,
            'info' => self::INFO,
        ]);

        Http::fake();
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::ACE333->value);

        $result = app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::ACE333->value,
            $this->driver,
            '2026-07-30 00:00:00',
            '2026-07-31 00:00:00',
        );

        Http::assertNothingSent();
        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->inserted);
    }

    #[TestDox('fetch 窗口跨可拉取界線時起點收斂至界線（UTC 2026-07-31 16:00），timepoint 對應界線')]
    #[Group('rules/bet-record-pull#R-11')]
    public function test_fetch_clamps_start_time_to_min_start_time(): void
    {
        Vendor::where('id', VendorIdEnum::ACE333->value)->update(['status' => 1]);
        VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::ACE333->value,
            'status' => 1,
            'rate' => 1,
            'info' => self::INFO,
        ]);

        // 只回 timepoint 與標題兩行 = 無注單
        Http::fake(['example.invalid/*' => Http::response("1785513600000\nplayerID,extPlayerID")]);
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::ACE333->value);

        app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::ACE333->value,
            $this->driver,
            '2026-07-31 10:00:00',
            '2026-07-31 17:00:00',
        );

        // 1785513600000 = 2026-07-31 16:00:00 UTC（台灣 2026-08-01 00:00）
        Http::assertSent(fn ($request) => str_contains($request->url(), 'timepoint=1785513600000'));
    }

    #[TestDox('fetch 單次只處理一個時間片：窗口超過一片時僅拉第一片，pendingFrom 回報下一片起點供接續')]
    #[Group('rules/bet-record-pull#R-7')]
    public function test_fetch_processes_single_chunk_and_reports_next_chunk_start(): void
    {
        Vendor::where('id', VendorIdEnum::ACE333->value)->update(['status' => 1]);
        VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::ACE333->value,
            'status' => 1,
            'rate' => 1,
            'info' => self::INFO,
        ]);

        Http::fake(['example.invalid/*' => Http::response("1786010400000\nplayerID,extPlayerID")]);
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::ACE333->value);

        $result = app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::ACE333->value,
            $this->driver,
            '2026-08-06 10:00:00',
            '2026-08-06 13:00:00',
        );

        // 三小時窗口只打一次三方 API（單一 vendor_info × 第一片）
        Http::assertSentCount(1);
        // 1786010400000 = 2026-08-06 10:00:00 UTC，timepoint 對應第一片起點
        Http::assertSent(fn ($request) => str_contains($request->url(), 'timepoint=1786010400000'));

        // 剩餘窗口自第二片起點接續；retryAfterSeconds = 0 代表片已完成、可立即派發
        $this->assertSame('2026-08-06 11:00:00', $result->pendingFrom);
        $this->assertSame(0, $result->retryAfterSeconds);
    }

    #[TestDox('fetch 完整流程：注單寫入 ace333_bet_records 並轉入 total_bet_records（member/game/rate 解析）')]
    public function test_fetch_inserts_bet_records_and_transfers_to_total_bet_records(): void
    {
        $ref = 'REF-' . uniqid();
        $playerId = 'P' . random_int(100000, 999999);

        Vendor::where('id', VendorIdEnum::ACE333->value)
            ->update(['status' => 1, 'valid_bet_rate' => 1]);

        $vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::ACE333->value,
            'status' => 1,
            'rate' => 1,
            'info' => self::INFO,
        ]);

        Ace333Account::create([
            'vendor_info_id' => $vendorInfo->id,
            'username' => 'u' . $playerId,
            'player_id' => $playerId,
            'member_id' => 8888,
            'admin' => 0,
        ]);

        $game = Game::factory()->create([
            'vendor_id' => VendorIdEnum::ACE333->value,
            'game_type' => 'slot-b',
            'valid_bet_rate' => 0.5,
        ]);

        $csv = implode("\n", [
            '1754500000000',
            'playerID,extPlayerID,gameID,transactionType,referenceID,status,created,updated,betAmount,winAmount,jackpotModule,jackpotContributionAmt,currency,resultUrl,roundDetails,platform',
            sprintf('%s,2001,"slot-b","BR","%s","C","2026-08-06 10:10:00","2026-08-06 10:10:05",10,25,"","0","MYR","https://r/1","round-1","WEB"', $playerId, $ref),
        ]);
        Http::fake(['example.invalid/*' => Http::response($csv)]);
        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::ACE333->value);

        $result = app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::ACE333->value,
            $this->driver,
            '2026-08-06 10:00:00',
            '2026-08-06 11:00:00',
        );

        $this->assertSame(1, $result->inserted);

        $betRecord = Ace333BetRecord::where('referenceID', $ref)->first();
        $this->assertNotNull($betRecord);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => VendorIdEnum::ACE333->value,
            'bet_record_id' => $betRecord->id,
            'member_id' => 8888,
            'game_id' => $game->id,
            'reference_no' => $ref,
            'bet' => '10.0000',
            'valid_bet' => '10.0000',
            'win_lose' => '15.0000',
            'valid_bet_rate' => '0.5000',
            'summary_time' => '2026-08-06 10:00:00',
            'bet_time' => '2026-08-06 10:10:00',
            'payout_time' => '2026-08-06 10:10:00',
        ]);
    }
}
