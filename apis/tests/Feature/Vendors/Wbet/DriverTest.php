<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Wbet;

use App\Data\InsertedBetRecordData;
use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\WbetAccount;
use App\Vendors\Wbet\Driver;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Wbet（vendor 102）拉注單：轉帳錢包，遷自舊系統 RD-Studio `php artisan wbet_bet_record`。
 *
 * php artisan test tests/Feature/Vendors/Wbet/DriverTest.php --compact
 */
class DriverTest extends TestCase
{
    private const string AUTH_URL = 'https://wapicall.test';

    private const array INFO = [
        'AUTH_URL' => self::AUTH_URL,
        'CLIENT_ID' => 'test-operator',
        'CLIENT_SECRET' => 'test-secret',
        'CLIENT_CLASS' => 'CLS',
    ];

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::WBET->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::WBET->value]);
        }

        $vendorInfo = VendorInfo::factory()->make(['vendor_id' => VendorIdEnum::WBET->value]);
        $vendorInfo->info = self::INFO;

        // pace() 覆寫為 no-op：對帳/報表 API 節流的 sleep(30) 不應拖慢測試
        $this->driver = new class(VendorIdEnum::WBET->value, null) extends Driver
        {
            protected function pace(): void {}
        };
        $this->driver->setVendorInfo($vendorInfo);
        $this->driver->setInfo(self::INFO);
        $this->driver->setVendorAccount(new WbetAccount(['username' => 'Y2WWBET200000062']));
    }

    public function test_get_balance_creates_member_on_account_not_exists_and_retries(): void
    {
        Http::fake([
            self::AUTH_URL . '/api/getbalance' => Http::sequence()
                ->push(['status' => 200, 'statusdesc' => 'accountNotExists'])
                ->push(['status' => 1, 'statusdesc' => 'OK', 'value' => [['available_balance' => 12.3456]]]),
            self::AUTH_URL . '/api/createmember' => Http::response(['status' => 1, 'statusdesc' => 'OK']),
        ]);

        $this->assertSame('12.34', $this->driver->getBalance());

        Http::assertSent(fn ($request) => $request->url() === self::AUTH_URL . '/api/createmember'
            && $request['account_id'] === 'Y2WWBET200000062');
    }

    public function test_get_balance_does_not_create_member_on_other_errors(): void
    {
        Http::fake([
            self::AUTH_URL . '/api/getbalance' => Http::response(['status' => -1, 'statusdesc' => 'invalidSignature']),
        ]);

        $this->expectExceptionMessage('invalidSignature');

        try {
            $this->driver->getBalance();
        } finally {
            Http::assertNotSent(fn ($request) => $request->url() === self::AUTH_URL . '/api/createmember');
        }
    }

    #[Group('rules/bet-record-pull#R-3')]
    #[Group('rules/bet-record-pull#R-38')]
    public function test_driver_supports_bet_record_fetch_without_schedule(): void
    {
        // 退出自動排程（不實作排程 marker），保留 --vendor 手動補拉能力
        $this->assertInstanceOf(SupportsBetRecordFetch::class, $this->driver);
        $this->assertNotInstanceOf(ShouldScheduleBetRecordFetch::class, $this->driver);
        $this->assertSame('bet_id', $this->driver->betRecordUniqueKey());
        // 沿用框架預設：對應 WbetAccount.vendor_username（見 Driver 類別頂部風險註記）
        $this->assertSame('vendor_username', $this->driver->betRecordAccountColumn());
        $this->assertSame(1440, $this->driver->betRecordApiWindowMinutes());
        $this->assertSame(['max_calls' => 2, 'per_seconds' => 60], $this->driver->betRecordApiRateLimit());
        $this->assertSame('test-operator', $this->driver->betRecordCredentialKey(self::INFO));

        $minStart = $this->driver->betRecordMinStartTime();
        $this->assertNotNull($minStart);
        // 保留期 150 天：容許測試執行耗時的秒級誤差
        $this->assertEqualsWithDelta(
            now('UTC')->subDays(150)->timestamp,
            strtotime($minStart),
            5,
        );
    }

    /**
     * 回歸測試：BetRecordFetchService 是以 `getDriver($vendorId)`（不帶 member）建構 driver，
     * 只呼叫 setInfo()、不會呼叫 setVendorInfo()——$this->vendorInfo 因此不會被初始化。
     * 曾經在 postReportApi() 誤用 $this->getName()（讀 $this->vendorInfo->name）導致
     * "must not be accessed before initialization"，本測試專門鎖住這條無 member 路徑。
     */
    public function test_fetch_bet_records_works_without_member_or_vendor_info(): void
    {
        $driver = new class(VendorIdEnum::WBET->value, null) extends Driver
        {
            protected function pace(): void {}
        };
        $driver->setInfo(self::INFO);

        $this->fakeReportApi(
            bets: [['bet_id' => 101, 'bet_status' => 'Accepted', 'member_id' => 'P1', 'bet_amount' => '10.00', 'bet_time' => '2026-08-05T11:00:00+08:00']],
        );

        $records = $driver->fetchBetRecords('2026-08-05 00:00:00', '2026-08-06 00:00:00');

        $this->assertCount(1, $records);
    }

    /**
     * @param  array<int, array<string, mixed>>  $bets
     * @param  array<int, array<string, mixed>>  $parlays
     * @param  array<int, array<string, mixed>>  $results
     * @param  array<int, array<string, mixed>>  $bonuses
     */
    private function fakeReportApi(array $bets = [], array $parlays = [], array $results = [], array $bonuses = []): void
    {
        Http::fake([
            self::AUTH_URL . '/api/getbetlist' => Http::response(['status' => 1, 'statusdesc' => 'OK', 'value' => $bets]),
            self::AUTH_URL . '/api/getparlaybetlist' => Http::response(['status' => 1, 'statusdesc' => 'OK', 'value' => $parlays]),
            self::AUTH_URL . '/api/getresult' => Http::response(['status' => 1, 'statusdesc' => 'OK', 'value' => $results]),
            self::AUTH_URL . '/api/getbonusresult' => Http::response(['status' => 1, 'statusdesc' => 'OK', 'value' => $bonuses]),
            self::AUTH_URL . '/api/markfetchbetlist' => Http::response(['status' => 1, 'statusdesc' => 'OK']),
            self::AUTH_URL . '/api/markfetchparlaybetlist' => Http::response(['status' => 1, 'statusdesc' => 'OK']),
            self::AUTH_URL . '/api/markfetchresult' => Http::response(['status' => 1, 'statusdesc' => 'OK']),
            self::AUTH_URL . '/api/markfetchbonusresult' => Http::response(['status' => 1, 'statusdesc' => 'OK']),
        ]);
    }

    #[Group('rules/bet-record-pull#R-40')]
    public function test_fetch_only_returns_accepted_bets_and_marks_all_but_pending(): void
    {
        $this->fakeReportApi(
            bets: [
                ['bet_id' => 101, 'bet_status' => 'Accepted', 'member_id' => 'P1', 'bet_amount' => '10.00', 'bet_time' => '2026-08-05T11:00:00+08:00'],
                ['bet_id' => 102, 'bet_status' => 'Pending', 'member_id' => 'P2', 'bet_amount' => '10.00', 'bet_time' => '2026-08-05T11:00:00+08:00'],
                ['bet_id' => 103, 'bet_status' => 'Rejected', 'member_id' => 'P3', 'bet_amount' => '10.00', 'bet_time' => '2026-08-05T11:00:00+08:00'],
                ['bet_id' => 104, 'bet_status' => 'Cancel', 'member_id' => 'P4', 'bet_amount' => '10.00', 'bet_time' => '2026-08-05T11:00:00+08:00'],
            ],
        );

        $records = $this->driver->fetchBetRecords('2026-08-05 00:00:00', '2026-08-06 00:00:00');

        // 只有 Accepted 落地；Pending/Rejected/Cancel 都不落地
        $this->assertCount(1, $records);
        $this->assertSame(101, $records[0]['bet_id']);

        // 標記排除 Pending（102），Accepted/Rejected/Cancel 皆標記
        Http::assertSent(fn ($request): bool => $request->url() === self::AUTH_URL . '/api/markfetchbetlist'
            && $request['bet_list'] === [['a' => 101], ['a' => 103], ['a' => 104]]);
    }

    #[Group('rules/bet-record-pull#R-40')]
    public function test_fetch_marks_all_results_and_bonus_regardless_of_status(): void
    {
        $this->fakeReportApi(
            results: [['result_id' => 301, 'result_status' => 'END', 'member_id' => 'P1', 'bet_amount' => '10.00', 'valid_bet_amount' => '10.00', 'winlose' => '5.00', 'result_time' => '2026-08-05T12:00:00+08:00', 'working_date' => '2026-08-05T12:00:00+08:00']],
            bonuses: [['result_id' => '401_RESULT', 'result_status' => 'END', 'result_type' => 'RESULT', 'member_id' => 'P2', 'bet_amount' => '0', 'winlose' => '500.00', 'result_on' => '2026-08-05T12:30:00+08:00', 'working_date' => '2026-08-05T12:30:00+08:00']],
        );

        $records = $this->driver->fetchBetRecords('2026-08-05 00:00:00', '2026-08-06 00:00:00');

        $this->assertCount(2, $records);

        Http::assertSent(fn ($request): bool => $request->url() === self::AUTH_URL . '/api/markfetchresult'
            && $request['result_list'] === [['a' => 301]]);
        Http::assertSent(fn ($request): bool => $request->url() === self::AUTH_URL . '/api/markfetchbonusresult'
            && $request['bonus_list'] === [['a' => '401_RESULT']]);
    }

    #[Group('rules/bet-record-pull#R-40')]
    public function test_fetch_skips_mark_call_when_nothing_to_mark(): void
    {
        $this->fakeReportApi();

        $records = $this->driver->fetchBetRecords('2026-08-05 00:00:00', '2026-08-06 00:00:00');

        $this->assertSame([], $records);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'markfetch'));
    }

    #[Group('rules/bet-record-pull#R-44')]
    public function test_fetch_treats_status_2_as_empty_not_error(): void
    {
        Http::fake([
            self::AUTH_URL . '/api/getbetlist' => Http::response(['status' => 2, 'statusdesc' => 'no data']),
            self::AUTH_URL . '/api/getparlaybetlist' => Http::response(['status' => 1, 'statusdesc' => 'OK', 'value' => []]),
            self::AUTH_URL . '/api/getresult' => Http::response(['status' => 1, 'statusdesc' => 'OK', 'value' => []]),
            self::AUTH_URL . '/api/getbonusresult' => Http::response(['status' => 1, 'statusdesc' => 'OK', 'value' => []]),
        ]);

        $this->assertSame([], $this->driver->fetchBetRecords('2026-08-05 00:00:00', '2026-08-06 00:00:00'));
    }

    #[Group('rules/bet-record-pull#R-44')]
    public function test_fetch_throws_on_error_status(): void
    {
        Http::fake([
            self::AUTH_URL . '/api/getbetlist' => Http::response(['status' => -1, 'statusdesc' => 'invalidSignature']),
        ]);

        $this->expectExceptionMessage('invalidSignature');

        $this->driver->fetchBetRecords('2026-08-05 00:00:00', '2026-08-06 00:00:00');
    }

    #[Group('rules/bet-record-pull#R-43')]
    public function test_format_bet_record_maps_unsettled_bet(): void
    {
        $record = $this->driver->formatBetRecord([
            'bet_id' => 101,
            'bet_status' => 'Accepted',
            'member_id' => 'P1',
            'bet_amount' => '10.00',
            'bet_time' => '2026-08-05T11:00:00+08:00',
        ], '1');

        $this->assertSame('101', $record['bet_id']);
        // vendor_username 需補 CLIENT_CLASS 後綴，才能與 createAccount() 寫入的 wbet_accounts.vendor_username 對上
        $this->assertSame('P1CLS', $record['vendor_username']);
        $this->assertSame('TP01', $record['game_code']);
        $this->assertSame('10.0000', $record['bet']);
        $this->assertSame('0.0000', $record['valid_bet']);
        $this->assertSame('0.0000', $record['win_lose']);
        $this->assertNull($record['payout_time']);
        $this->assertSame(0, $record['status']);
        // 三方時間為 GMT+8，DB 存 UTC
        $this->assertSame('2026-08-05 03:00:00', $record['bet_time']);
    }

    #[Group('rules/bet-record-pull#R-42')]
    public function test_format_bet_record_maps_settled_result(): void
    {
        $record = $this->driver->formatBetRecord([
            'result_id' => 301,
            'result_status' => 'END',
            'member_id' => 'P1',
            'bet_amount' => '10.00',
            'valid_bet_amount' => '10.00',
            'winlose' => '5.00',
            'bet_time' => '2026-08-05T11:00:00+08:00',
            'result_time' => '2026-08-05T12:00:00+08:00',
            'working_date' => '2026-08-05T12:00:00+08:00',
        ], '2');

        $this->assertSame('301', $record['bet_id']);
        $this->assertSame('5.0000', $record['bet']);
        $this->assertSame('5.0000', $record['valid_bet']);
        $this->assertSame('2.5000', $record['win_lose']);
        $this->assertSame('2026-08-05 04:00:00', $record['payout_time']);
        $this->assertSame(1, $record['status']);
    }

    #[Group('rules/bet-record-pull#R-42')]
    public function test_format_bet_record_reopen_resets_to_unsettled(): void
    {
        $record = $this->driver->formatBetRecord([
            'result_id' => 302,
            'result_status' => 'REOPEN',
            'member_id' => 'P1',
            'bet_amount' => '10.00',
            'valid_bet_amount' => '10.00',
            'winlose' => '5.00',
            'bet_time' => '2026-08-05T11:00:00+08:00',
            'result_time' => '2026-08-05T12:00:00+08:00',
            'working_date' => '2026-08-05T12:00:00+08:00',
        ], '1');

        $this->assertSame('0.0000', $record['win_lose']);
        $this->assertSame('0.0000', $record['jackpot']);
        $this->assertNull($record['payout_time']);
        $this->assertSame(0, $record['status']);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int}>
     */
    public static function bonusResultTypeProvider(): array
    {
        return [
            'createroom' => ['CREATEROOM', 'PRIVATE', 2],
            'joinroom paid' => ['JOINROOM', 'PUBLIC', 3],
            'joinroom free' => ['JOINROOM', 'PUBLIC FREE', 4],
        ];
    }

    #[DataProvider('bonusResultTypeProvider')]
    #[Group('rules/bet-record-pull#R-42')]
    public function test_format_bet_record_maps_bonus_room_states(string $resultType, string $roomType, int $expectedStatus): void
    {
        $record = $this->driver->formatBetRecord([
            'result_id' => '401_' . $resultType,
            'result_status' => 'END',
            'result_type' => $resultType,
            'room_type' => $roomType,
            'member_id' => 'P1',
            'bet_amount' => '20.00',
            'valid_bet_amount' => '20.00',
            'winlose' => '0',
            // getbonusresult 文件未列 bet_time：沿用舊系統以 bet_on 當備援欄位
            'bet_on' => '2026-08-05T08:55:00+08:00',
            'result_on' => '2026-08-05T09:00:00+08:00',
            'working_date' => '2026-08-05T09:00:00+08:00',
        ], '1');

        $this->assertSame($expectedStatus, $record['status']);
    }

    #[Group('rules/bet-record-pull#R-42')]
    public function test_format_bet_record_maps_bonus_result_jackpot(): void
    {
        $record = $this->driver->formatBetRecord([
            'result_id' => '402_RESULT',
            'result_status' => 'END',
            'result_type' => 'RESULT',
            'member_id' => 'P1',
            'bet_amount' => '0',
            'valid_bet_amount' => '0',
            'winlose' => '500.00',
            'bet_on' => '2026-08-05T08:55:00+08:00',
            'result_on' => '2026-08-05T09:00:00+08:00',
            'working_date' => '2026-08-05T09:00:00+08:00',
        ], '1');

        $this->assertSame('0.0000', $record['bet']);
        $this->assertSame('0.0000', $record['valid_bet']);
        $this->assertSame('0.0000', $record['win_lose']);
        $this->assertSame('500.0000', $record['jackpot']);
        $this->assertSame(1, $record['status']);
    }

    #[Group('rules/bet-record-pull#R-42')]
    public function test_format_bet_record_maps_bonus_refund(): void
    {
        $record = $this->driver->formatBetRecord([
            'result_id' => '403_JOINROOM',
            'result_status' => 'REFUND',
            'result_type' => 'JOINROOM',
            'room_type' => 'PRIVATE',
            'member_id' => 'P1',
            'bet_amount' => '15.00',
            'valid_bet_amount' => '15.00',
            'winlose' => '0',
            'bet_on' => '2026-08-05T08:55:00+08:00',
            'result_on' => '2026-08-05T09:00:00+08:00',
            'working_date' => '2026-08-05T09:00:00+08:00',
        ], '1');

        $this->assertSame('0.0000', $record['bet']);
        $this->assertSame('0.0000', $record['valid_bet']);
        $this->assertSame('0.0000', $record['win_lose']);
        $this->assertSame(9, $record['status']);
    }

    #[Group('rules/bet-record-pull#R-44')]
    public function test_format_bet_record_throws_when_bet_time_missing(): void
    {
        $this->expectException(ApiException::class);

        $this->driver->formatBetRecord([
            'bet_id' => 999,
            'member_id' => 'P1',
            'bet_amount' => '10.00',
        ], '1');
    }

    #[Group('rules/bet-record-pull#R-43')]
    public function test_format_base_total_bet_record_only_transfers_settled(): void
    {
        $unsettled = $this->driver->formatBetRecord([
            'bet_id' => 101,
            'bet_status' => 'Accepted',
            'member_id' => 'P1',
            'bet_amount' => '10.00',
            'bet_time' => '2026-08-05T11:00:00+08:00',
        ], '1');

        $settled = $this->driver->formatBetRecord([
            'result_id' => 301,
            'result_status' => 'END',
            'member_id' => 'P1',
            'bet_amount' => '10.00',
            'valid_bet_amount' => '10.00',
            'winlose' => '5.00',
            'bet_time' => '2026-08-05T11:00:00+08:00',
            'result_time' => '2026-08-05T12:00:00+08:00',
            'working_date' => '2026-08-05T12:00:00+08:00',
        ], '1');

        $this->assertNull($this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('101', $unsettled)));

        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData('301', $settled));

        $this->assertNotNull($base);
        $this->assertSame('P1CLS', $base->accountKey);
        $this->assertSame('301', $base->referenceNo);
        $this->assertSame('10.0000', $base->bet);
        $this->assertSame('10.0000', $base->validBet);
        $this->assertSame('5.0000', $base->winLose);
        $this->assertSame('2026-08-05 04:00:00', $base->payoutTime);
    }
}
