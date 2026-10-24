<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Oppo;

use App\Data\InsertedBetRecordData;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Vendor;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Oppo\Driver;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Oppo（vendor 169）拉注單：轉帳錢包，遷自舊系統 RD-Studio `php artisan oppo_bet_record`。
 *
 * php artisan test tests/Feature/Vendors/Oppo/DriverTest.php --compact
 */
class DriverTest extends TestCase
{
    use DatabaseTransactions;

    private const string API_URL = 'https://oppo.test';

    private const array INFO = [
        'API_URL' => self::API_URL,
        'API_KEY' => 'test-api-key',
        'AGENT_NAME' => 'test-agent',
    ];

    private const string PLAYER_ID = 'YES2WIN-14051f03-b56b-4ea1-a487-cd1da8ce5c65';

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::OPPO->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::OPPO->value]);
        }

        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::OPPO->value);

        $this->driver = new Driver(VendorIdEnum::OPPO->value, null);
        $this->driver->setInfo(self::INFO);
    }

    /**
     * @param  array<int, array<string, mixed>>  $summary
     * @param  array<int, array<string, mixed>>  $gameLog
     */
    private function fakeApi(array $summary, array $gameLog, int $gameLogLastPage = 1): void
    {
        Http::fake([
            self::API_URL . '/api/getGameSummary' => Http::response([
                'data' => $summary,
                'pagination' => ['current_page' => 1, 'last_page' => 1],
            ]),
            self::API_URL . '/api/getGameLog' => Http::response([
                'data' => $gameLog,
                'pagination' => ['current_page' => 1, 'last_page' => $gameLogLastPage],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function betRecord(string $betId, string $bet, string $settle, string $status = 'Settled'): array
    {
        return [
            'betoppo_username' => 'yes2win_op00000003',
            'betoppo_bet_id' => $betId,
            'betoppo_amount' => $bet,
            'betoppo_settle_amount' => $settle,
            'betoppo_validbet_amount' => $bet,
            'betoppo_betdatetime' => '2026-08-05 11:00:17',
            'betoppo_settledatetime' => $status === 'Settled' ? '2026-08-05 11:01:34' : null,
            'betoppo_winjackpot' => 0,
            'betoppo_status' => $status,
            'user_apiplayerkey' => self::PLAYER_ID,
        ];
    }

    #[Group('rules/bet-record-pull#R-3')]
    public function test_driver_supports_bet_record_fetch_without_schedule(): void
    {
        // 退出自動排程（不實作排程 marker），保留 --vendor 手動補拉能力
        $this->assertInstanceOf(SupportsBetRecordFetch::class, $this->driver);
        $this->assertNotInstanceOf(ShouldScheduleBetRecordFetch::class, $this->driver);
        $this->assertSame('bet_id', $this->driver->betRecordUniqueKey());
        // 注單的玩家識別是三方 player_id，不是帳號表的 username
        $this->assertSame('player_id', $this->driver->betRecordAccountColumn());
        $this->assertSame(60, $this->driver->betRecordApiWindowMinutes());
        $this->assertNull($this->driver->betRecordMinStartTime());
        // 三方以 Agent 區分商戶：同 Agent 的 vendor_info 只拉一次
        $this->assertSame('test-agent', $this->driver->betRecordCredentialKey(self::INFO));
    }

    /**
     * 三方沒有整個代理的注單 API：先取有投注的玩家，再逐一取該玩家注單
     */
    #[Group('rules/bet-record-pull#R-36')]
    public function test_fetch_queries_players_first_then_each_player_bet_log(): void
    {
        $this->fakeApi(
            [['user_apiplayerkey' => self::PLAYER_ID], ['user_apiplayerkey' => self::PLAYER_ID]],
            [$this->betRecord('OP250000000174', '10.00', '19.50')],
        );

        $records = $this->driver->fetchBetRecords('2026-08-05 03:00:00', '2026-08-05 04:00:00');

        $this->assertCount(1, $records);
        $this->assertSame('OP250000000174', $records[0]['betoppo_bet_id']);

        // UTC → GMT+8；重複玩家去重後只打一次 getGameLog
        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/api/getGameSummary')
            && $request['StartDate'] === '2026-08-05 11:00:00'
            && $request['EndDate'] === '2026-08-05 12:00:00');
        Http::assertSentCount(2);
    }

    #[Group('rules/bet-record-pull#R-36')]
    public function test_fetch_follows_pagination(): void
    {
        $this->fakeApi(
            [['user_apiplayerkey' => self::PLAYER_ID]],
            [$this->betRecord('OP-1', '10.00', '0')],
            gameLogLastPage: 3,
        );

        $records = $this->driver->fetchBetRecords('2026-08-05 03:00:00', '2026-08-05 04:00:00');

        // getGameSummary 1 頁 + getGameLog 3 頁
        $this->assertCount(3, $records);
        Http::assertSentCount(4);
    }

    /**
     * 拉取失敗要炸出來：回空陣列會被當成「該時段無注單」，該片被標記完成而永久缺帳
     */
    #[Group('rules/bet-record-pull#R-18')]
    public function test_fetch_throws_when_third_party_returns_error(): void
    {
        Http::fake([
            self::API_URL . '/api/getGameSummary' => Http::response(['error' => ['code' => 'AUTH_FAILED']]),
        ]);

        $this->expectException(ApiException::class);

        $this->driver->fetchBetRecords('2026-08-05 03:00:00', '2026-08-05 04:00:00');
    }

    public function test_format_bet_record_restores_rate_and_maps_columns(): void
    {
        $record = $this->driver->formatBetRecord(
            [...$this->betRecord('OP250000000174', '10.00', '19.50'), 'betoppo_winjackpot' => 1],
            '2.0000',
        );

        $this->assertSame('OP250000000174', $record['bet_id']);
        $this->assertSame('yes2win_op00000003', $record['username']);
        $this->assertSame(self::PLAYER_ID, $record['player_id']);
        $this->assertSame('lobby', $record['game_code']);
        $this->assertSame('5.0000', $record['bet']);
        $this->assertSame('5.0000', $record['valid_bet']);
        $this->assertSame('9.7500', $record['win']);
        // 三方給的是派彩，輸贏要自己減
        $this->assertSame('4.7500', $record['win_lose']);
        // winjackpot 為真時 jackpot 取派彩
        $this->assertSame('9.7500', $record['jackpot']);
        // 三方時間為 GMT+8，DB 存 UTC
        $this->assertSame('2026-08-05 03:00:17', $record['bet_time']);
        $this->assertSame('2026-08-05 03:01:34', $record['payout_time']);
        $this->assertSame(1, $record['status']);
    }

    #[Group('rules/bet-record-pull#R-37')]
    public function test_unsettled_record_is_not_transferred_to_total_bet_record(): void
    {
        $unsettled = $this->driver->formatBetRecord($this->betRecord('OP-2', '10.00', '0', 'Betting'), '1');
        $settled = $this->driver->formatBetRecord($this->betRecord('OP-3', '10.00', '19.50'), '1');

        $this->assertSame(0, $unsettled['status']);
        $this->assertNull($unsettled['payout_time']);
        $this->assertNull($this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('OP-2', $unsettled)));

        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('OP-3', $settled));

        $this->assertNotNull($base);
        // TBR 以 player_id 解析會員
        $this->assertSame(self::PLAYER_ID, $base->accountKey);
        $this->assertSame('OP-3', $base->referenceNo);
        $this->assertSame('10.0000', $base->bet);
        $this->assertSame('10.0000', $base->validBet);
        $this->assertSame('9.5000', $base->winLose);
        $this->assertSame('2026-08-05 03:01:34', $base->payoutTime);
    }
}
