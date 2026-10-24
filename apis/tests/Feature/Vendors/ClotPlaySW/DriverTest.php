<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\ClotPlaySW;

use App\Enums\Vendor\VendorIdEnum;
use App\Exceptions\ApiException;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\BetRecordFetchService;
use App\Vendors\ClotPlaySW\Driver;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\ClotswBetRecord;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * ClotPlaySW（vendor 142）拉注單：單一錢包，只供三方 ↔ DB 比對。
 *
 * php artisan test tests/Feature/Vendors/ClotPlaySW/DriverTest.php --compact
 */
class DriverTest extends TestCase
{
    use DatabaseTransactions;

    private const string API_URL = 'https://clot.test/api/MOGI';

    private const array INFO = [
        'apiUrl' => self::API_URL,
        'operatorToken' => 'test-operator-token',
    ];

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::CLOTSW->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::CLOTSW->value]);
        }

        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::CLOTSW->value);

        $this->driver = new Driver(VendorIdEnum::CLOTSW->value, null);
        $this->driver->setInfo(self::INFO);
    }

    /**
     * clotsw_bet_records 完整欄位列
     *
     * @return array<string, mixed>
     */
    private function betRecordRow(string $betId, string $payoutTime): array
    {
        return [
            'bet_id' => $betId,
            'username' => 'CLOTSW00010174',
            'game_code' => 'va-maneki-neko',
            'bet' => '1.0000',
            'valid_bet' => '1.0000',
            'win' => '2.0000',
            'win_lose' => '1.0000',
            'rolling_chip' => '0.0000',
            'bet_time' => $payoutTime,
            'payout_time' => $payoutTime,
            'info' => '[]',
            'settle_status' => 0,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $data
     */
    private function fakeTransactions(array $data, int $lastPage = 1): void
    {
        Http::fake([
            self::API_URL . '/get-transaction-details*' => Http::response([
                'data' => $data,
                'pagination' => ['current_page' => 1, 'last_page' => $lastPage],
            ]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function transaction(string $roundId, string $bet, string $win): array
    {
        return [
            'id' => 1,
            'player_unique_id' => 'CLOTSW00010174',
            'game' => 'va-maneki-neko',
            'game_round_id' => $roundId,
            'total_bet_amount' => $bet,
            'winning' => $win,
            'created_at' => '2026-08-20T10:41:23+08:00',
            'updated_at' => '2026-08-20T10:41:30+08:00',
        ];
    }

    #[Group('rules/bet-record-pull#R-45')]
    public function test_driver_implements_supports_bet_record_fetch(): void
    {
        $this->assertInstanceOf(SupportsBetRecordFetch::class, $this->driver);
        $this->assertSame('bet_id', $this->driver->betRecordUniqueKey());
        $this->assertSame('username', $this->driver->betRecordAccountColumn());
        // 三方單次查詢窗最多 30 分鐘、限 10 req/min
        $this->assertSame(30, $this->driver->betRecordApiWindowMinutes());
        $this->assertSame(['max_calls' => 10, 'per_seconds' => 60], $this->driver->betRecordApiRateLimit());
        // 對帳 API 不吃 agent_code，回傳範圍由 operatorToken 決定
        $this->assertSame('test-operator-token', $this->driver->betRecordCredentialKey(self::INFO));
    }

    #[Group('rules/bet-record-pull#R-45')]
    public function test_fetch_sends_taipei_time_window_with_inclusive_end(): void
    {
        $this->fakeTransactions([]);

        $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:30:00');

        // UTC → +8；updated_at_to 為含端點，退 1 秒避免與下一片重疊
        Http::assertSent(fn ($request): bool => $request['updated_at_from'] === '2026-08-20 08:00:00'
            && $request['updated_at_to'] === '2026-08-20 08:29:59'
            && $request->hasHeader('Authorization', 'test-operator-token'));
    }

    #[Group('rules/bet-record-pull#R-46')]
    public function test_fetch_sums_transactions_of_the_same_round(): void
    {
        // 同一回合多筆交易（multi-bet），DB 一回合一列，要先加總才對得上
        $this->fakeTransactions([
            $this->transaction('ROUND-1', '0.5', '0'),
            $this->transaction('ROUND-1', '0.3', '1.2'),
            $this->transaction('ROUND-2', '2', '0'),
        ]);

        $records = $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:30:00');

        $this->assertCount(2, $records);
        $this->assertSame('ROUND-1', $records[0]['game_round_id']);
        $this->assertSame('0.8000', $records[0]['total_bet_amount']);
        $this->assertSame('1.2000', $records[0]['winning']);
        $this->assertSame('2.0000', $records[1]['total_bet_amount']);
    }

    /**
     * 拉取失敗要炸出來：回空陣列會被當成「該時段無注單」，該片被標記完成而永久缺帳
     */
    #[Group('rules/bet-record-pull#R-18')]
    public function test_fetch_throws_when_response_has_no_data(): void
    {
        Http::fake([self::API_URL . '/get-transaction-details*' => Http::response(['code' => 1011])]);

        $this->expectException(ApiException::class);

        $this->driver->fetchBetRecords('2026-08-20 00:00:00', '2026-08-20 00:30:00');
    }

    /**
     * 反向對帳：拉單本身只做「三方 → DB」，DB 有而三方沒回的列由乾跑提示列出
     */
    #[Group('rules/bet-record-pull#R-21')]
    public function test_dry_run_lists_records_the_third_party_did_not_return(): void
    {
        Vendor::where('id', VendorIdEnum::CLOTSW->value)->update(['status' => 1, 'is_single_wallet' => 1]);
        VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::CLOTSW->value,
            'status' => 1,
            'rate' => 1,
            'info' => self::INFO,
        ]);

        // 窗口取未來時間，避免測試庫既有注單列干擾比對
        ClotswBetRecord::insert([
            $this->betRecordRow('UT-BOTH', '2030-01-01 00:10:00'),
            $this->betRecordRow('UT-ONLY-DB', '2030-01-01 00:20:00'),
        ]);

        // 三方只回其中一筆
        $this->fakeTransactions([$this->transaction('UT-BOTH', '1', '2')]);

        $results = app(BetRecordFetchService::class)
            ->previewFetch(VendorIdEnum::CLOTSW->value, '2030-01-01 00:00:00', '2030-01-01 00:30:00');

        $this->assertCount(1, $results);
        $result = $results[0];

        // 三方回的那筆 DB 已有：不新增、不更新
        $this->assertSame(0, $result->inserted);
        $this->assertSame(0, $result->updated);
        // DB 有、三方沒回的那筆進反向清單
        $this->assertSame(1, $result->missing);
        $this->assertSame('UT-ONLY-DB', $result->previewMissing[0]['key']);
        $this->assertSame('1.0000', $result->previewMissing[0]['amounts']['bet']);
    }

    public function test_format_bet_record_restores_rate_and_maps_columns(): void
    {
        $record = $this->driver->formatBetRecord(
            [...$this->transaction('ROUND-1', '0.8', '1.2'), 'total_bet_amount' => '0.8000', 'winning' => '1.2000'],
            '2.0000',
        );

        $this->assertSame('ROUND-1', $record['bet_id']);
        $this->assertSame('CLOTSW00010174', $record['username']);
        $this->assertSame('va-maneki-neko', $record['game_code']);
        $this->assertSame('0.4000', $record['bet']);
        $this->assertSame('0.4000', $record['valid_bet']);
        $this->assertSame('0.6000', $record['win']);
        $this->assertSame('0.2000', $record['win_lose']);
        // 三方時間含 +8 時區，DB 存 UTC
        $this->assertSame('2026-08-20 02:41:23', $record['bet_time']);
        $this->assertSame('2026-08-20 02:41:30', $record['payout_time']);
        // 禮碼與結算狀態是本系統自有資料，三方不知道，不列入比對也不覆蓋
        $this->assertArrayNotHasKey('rolling_chip', $record);
        $this->assertArrayNotHasKey('settle_status', $record);
    }
}
