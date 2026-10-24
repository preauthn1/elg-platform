<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Data\BaseTotalBetRecordData;
use App\Data\InsertedBetRecordData;
use App\Enums\AdminOperationEnum;
use App\Enums\AdminOperationStatusEnum;
use App\Enums\General\ContextKeyEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\AdminOperationLog;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\BetRecordFetchService;
use App\Services\VendorService;
use App\Vendors\AbstractSingleWallet;
use App\Vendors\AbstractTransferWallet;
use App\Vendors\Contracts\SupportsBetRecordFetch;
use App\Vendors\Models\Ace333Account;
use App\Vendors\Models\Ace333BetRecord;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/BetRecordFetchServiceTest.php --compact
 *
 * 以 Mockery mock 能力契約、借用 Ace333 注單／帳號表當儲存端，
 * 驗證 BetRecordFetchService 自身的編排邏輯（driver 無關）。
 */
class BetRecordFetchServiceTest extends TestCase
{
    private const int VENDOR_ID = VendorIdEnum::ACE333->value;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(self::VENDOR_ID)) {
            Vendor::factory()->create(['id' => self::VENDOR_ID]);
        }
        // ACE333 是轉帳錢包：factory 預設 is_single_wallet = 1，這裡校正回實際型態
        Vendor::where('id', self::VENDOR_ID)->update(['status' => 1, 'valid_bet_rate' => 0.8, 'is_single_wallet' => 0]);

        RateLimiter::clear('fetch_bet_record:' . self::VENDOR_ID);
    }

    #[TestDox('vendor 不存在時不呼叫 driver 任何方法，回空結果')]
    public function test_returns_empty_result_when_vendor_not_found(): void
    {
        $driver = Mockery::mock(SupportsBetRecordFetch::class);

        $result = app(BetRecordFetchService::class)->fetchChunk(999999, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->inserted);
        $this->assertNull($result->pendingFrom);
    }

    #[TestDox('無啟用中的 vendor_info 時不打三方 API，回空結果')]
    public function test_returns_empty_result_when_no_active_vendor_info(): void
    {
        $this->createVendorInfo('token-a', status: 0);
        $driver = Mockery::mock(SupportsBetRecordFetch::class);

        $result = app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertSame(0, $result->fetched);
        $this->assertSame(0, $result->inserted);
    }

    #[TestDox('同一時間片內憑證鍵相同的 vendor_info 只打一次三方 API')]
    public function test_deduplicates_vendor_infos_by_credential_key_within_chunk(): void
    {
        $this->createVendorInfo('token-a');
        $this->createVendorInfo('token-a');
        $this->createVendorInfo('token-b');

        $driver = $this->mockDriver();
        $driver->shouldReceive('fetchBetRecords')->times(2)->andReturn([]);

        $result = app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertSame(0, $result->fetched);
        $this->assertNull($result->pendingFrom);
    }

    #[TestDox('rate limit 預算用罄時中止當前片：回報片起點與等待秒數，已入庫部分先轉檔')]
    public function test_stops_on_rate_limit_and_reports_current_chunk_start(): void
    {
        $this->createVendorInfo('token-a');
        $this->createVendorInfo('token-b');

        $ref = 'UT-' . uniqid();
        $driver = $this->mockDriver(rateLimit: ['max_calls' => 1, 'per_seconds' => 300]);
        // 預算只夠第一個 token；第二個 token 呼叫前即中止
        $driver->shouldReceive('fetchBetRecords')->once()->andReturn([$this->betRecordRow($ref)]);
        // 已入庫的 1 筆在中止前完成轉檔（本測試不驗 TBR 內容，回 null 略過轉入）
        $driver->shouldReceive('formatBaseTotalBetRecord')->once()->andReturnNull();

        $result = app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertSame(1, $result->fetched);
        $this->assertSame(1, $result->inserted);
        $this->assertSame('2026-08-06 10:00:00', $result->pendingFrom);
        $this->assertGreaterThan(0, $result->retryAfterSeconds);
        $this->assertDatabaseHas('ace333_bet_records', ['referenceID' => $ref]);
    }

    #[TestDox('rate limit 重拉不算一片：片數與進度回調只計真正跑完的片')]
    public function test_rate_limited_retry_does_not_count_as_a_chunk(): void
    {
        $this->createVendorInfo('token-a');

        // 預算 1 次／秒，起跑前先用掉：第一片必定撞牆，等預算釋放後重拉同一片
        // getDriver 的回傳型別限定錢包基底，mock 要連基底一起帶（ACE333 是轉帳錢包）
        $driver = $this->mockDriver(rateLimit: ['max_calls' => 1, 'per_seconds' => 1], baseClass: AbstractTransferWallet::class);
        $driver->shouldReceive('fetchBetRecords')->andReturn([]);
        RateLimiter::hit('fetch_bet_record:' . self::VENDOR_ID, 1);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);
        $service = new BetRecordFetchService($vendorService);

        $completed = [];
        $waited = 0;
        $service->onChunkComplete(function ($vendor, int $chunk, int $totalChunks, string $from, $result) use (&$completed, &$waited): void {
            if ($result->retryAfterSeconds > 0) {
                $waited++;

                return;
            }

            $completed[$chunk] = $from;
        });

        // 一片 60 分鐘、窗口 2 小時 ＝ 2 片；撞牆重拉幾次都不該改變這個數字
        $results = $service->fetchNow(self::VENDOR_ID, '2026-08-06 10:00:00', '2026-08-06 12:00:00');

        $this->assertGreaterThan(0, $waited, '應該至少撞過一次 rate limit');
        $this->assertSame(2, $results[0]->chunks);
        $this->assertSame([1 => '2026-08-06 10:00:00', 2 => '2026-08-06 11:00:00'], $completed);
    }

    #[TestDox('單一錢包遊戲商只放行乾跑：寫入模式直接擋下')]
    public function test_single_wallet_vendor_only_allows_dry_run(): void
    {
        Vendor::where('id', self::VENDOR_ID)->update(['is_single_wallet' => 1]);
        $this->createVendorInfo('token-a');

        $driver = $this->mockDriver(baseClass: AbstractSingleWallet::class);
        // 乾跑那趟才會真的打三方；被擋的那趟一次都不打
        $driver->shouldReceive('fetchBetRecords')->once()->andReturn([]);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);
        $service = new BetRecordFetchService($vendorService);

        try {
            $service->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');
            $this->fail('單一錢包的寫入模式應該被擋下');
        } catch (LogicException $e) {
            $this->assertStringContainsString('乾跑', $e->getMessage());
        }

        // 乾跑走同一支 fetchChunk，照常放行
        $results = $service->previewFetch(self::VENDOR_ID, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertCount(1, $results);
        $this->assertNull($results[0]->error);
    }

    #[TestDox('批次內重複唯一鍵與 DB 既有列都會被去重，只純新增新列')]
    public function test_deduplicates_by_unique_key_within_batch_and_against_db(): void
    {
        $this->createVendorInfo('token-a');

        $newRef = 'UT-NEW-' . uniqid();
        $existingRef = 'UT-EXIST-' . uniqid();
        Ace333BetRecord::insert($this->betRecordRow($existingRef));

        $driver = $this->mockDriver();
        $driver->shouldReceive('fetchBetRecords')->once()->andReturn([
            $this->betRecordRow($newRef),
            $this->betRecordRow($newRef),      // 批次內重複
            $this->betRecordRow($existingRef), // DB 已存在
        ]);
        $driver->shouldReceive('formatBaseTotalBetRecord')
            ->once()
            ->withArgs(fn (InsertedBetRecordData $record) => $record->uniqueKeyValue === $newRef)
            ->andReturnNull();

        $result = app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertSame(3, $result->fetched);
        $this->assertSame(1, $result->inserted);
        $this->assertSame(1, Ace333BetRecord::where('referenceID', $newRef)->count());
        $this->assertSame(1, Ace333BetRecord::where('referenceID', $existingRef)->count());
    }

    #[TestDox('TBR 轉入解析：無對應帳號的列不轉入；game 查無記 0 並以 vendor valid_bet_rate fallback')]
    public function test_transfer_resolves_member_and_falls_back_to_vendor_valid_bet_rate(): void
    {
        $vendorInfo = $this->createVendorInfo('token-a');
        Ace333Account::create([
            'vendor_info_id' => $vendorInfo->id,
            'username' => 'ut-user',
            'player_id' => 'UT-PLAYER-A',
            'member_id' => 8888,
            'admin' => 0,
        ]);

        $refWithAccount = 'UT-A-' . uniqid();
        $refWithoutAccount = 'UT-B-' . uniqid();

        $driver = $this->mockDriver();
        $driver->shouldReceive('fetchBetRecords')->once()->andReturn([
            $this->betRecordRow($refWithAccount, playerId: 'UT-PLAYER-A'),
            $this->betRecordRow($refWithoutAccount, playerId: 'UT-PLAYER-NOBODY'),
        ]);
        $driver->shouldReceive('formatBaseTotalBetRecord')
            ->twice()
            ->andReturnUsing(fn (InsertedBetRecordData $record) => new BaseTotalBetRecordData(
                accountKey: (string) $record->attributes['playerID'],
                gameCode: 'no-such-game',
                referenceNo: $record->uniqueKeyValue,
                bet: '10.0000',
                validBet: '10.0000',
                winLose: '10.0000',
                betTime: '2026-08-06 10:10:00',
                payoutTime: '2026-08-06 10:10:00',
            ));

        app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        // 有帳號的列轉入：game 查無記 0、valid_bet_rate 走 vendor fallback、summary_time 取整點
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => self::VENDOR_ID,
            'reference_no' => $refWithAccount,
            'member_id' => 8888,
            'game_id' => 0,
            'valid_bet_rate' => '0.8000',
            'summary_time' => '2026-08-06 10:00:00',
        ]);
        // 無對應帳號的列不轉入
        $this->assertDatabaseMissing('total_bet_records', ['reference_no' => $refWithoutAccount]);
    }

    #[TestDox('窗口早於可拉取界線時整段不執行、跨界線時起點收斂')]
    public function test_respects_min_start_time_boundary(): void
    {
        $this->createVendorInfo('token-a');

        $driver = $this->mockDriver(minStartTime: '2026-08-01 00:00:00');
        $driver->shouldReceive('fetchBetRecords')
            ->once()
            ->withArgs(fn (string $from, string $to) => $from === '2026-08-01 00:00:00' && $to === '2026-08-01 01:00:00')
            ->andReturn([]);

        // 整段早於界線：不打 API
        $early = app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-07-30 00:00:00', '2026-07-31 00:00:00');
        $this->assertSame(0, $early->fetched);
        $this->assertNull($early->pendingFrom);

        // 跨界線：起點收斂至界線，單片 [08-01 00:00, 01:00)，pendingFrom 接續下一片
        $clamped = app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-07-31 23:00:00', '2026-08-01 02:00:00');
        $this->assertSame('2026-08-01 01:00:00', $clamped->pendingFrom);
        $this->assertSame(0, $clamped->retryAfterSeconds);
    }

    #[TestDox('fetchChunk 指定 vendorInfoId 時只對該商戶拉單，其他商戶不打 API')]
    public function test_fetch_chunk_scopes_to_specific_vendor_info(): void
    {
        $vendorInfoA = $this->createVendorInfo('token-a');
        $this->createVendorInfo('token-b');

        $calledTokens = [];

        // 連線參數由服務先 setInfo 載入，從這裡看哪些商戶被拉
        $driver = $this->mockDriver(onSetInfo: function (array $info) use (&$calledTokens): void {
            $calledTokens[] = $info['TOKEN'];
        });
        $driver->shouldReceive('fetchBetRecords')->andReturn([]);

        app(BetRecordFetchService::class)->fetchChunk(
            self::VENDOR_ID,
            $driver,
            '2026-08-06 10:00:00',
            '2026-08-06 11:00:00',
            vendorInfoId: $vendorInfoA->id,
        );

        $this->assertSame(['token-a'], $calledTokens);
    }

    #[TestDox('fetchChunk 憑證迴圈中途拋例外時，已入庫的列先轉入 TBR 再上拋')]
    public function test_fetch_chunk_transfers_inserted_rows_before_rethrow(): void
    {
        $vendorInfo = $this->createVendorInfo('token-a');
        $this->createVendorInfo('token-b');
        Ace333Account::create([
            'vendor_info_id' => $vendorInfo->id,
            'username' => 'ut-user-ex',
            'player_id' => 'UT-PLAYER-EX',
            'member_id' => 7777,
            'admin' => 0,
        ]);

        $ref = 'UT-EX-' . uniqid();
        $calls = 0;

        $driver = $this->mockDriver();
        $driver->shouldReceive('fetchBetRecords')->twice()->andReturnUsing(function () use (&$calls, $ref): array {
            if (++$calls === 1) {
                return [$this->betRecordRow($ref, playerId: 'UT-PLAYER-EX')];
            }

            throw new \RuntimeException('third party down');
        });
        $driver->shouldReceive('formatBaseTotalBetRecord')
            ->once()
            ->andReturnUsing(fn (InsertedBetRecordData $record) => new BaseTotalBetRecordData(
                accountKey: 'UT-PLAYER-EX',
                gameCode: 'no-such-game',
                referenceNo: $record->uniqueKeyValue,
                bet: '10.0000',
                validBet: '10.0000',
                winLose: '10.0000',
                betTime: '2026-08-06 10:10:00',
                payoutTime: '2026-08-06 10:10:00',
            ));

        try {
            app(BetRecordFetchService::class)->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00');
            $this->fail('預期拋出例外');
        } catch (\RuntimeException) {
            // 例外由呼叫端處理，這裡只驗上拋前的 TBR 轉檔
        }

        // 第一組憑證已入庫的列須先完成 TBR 轉檔，否則純新增去重下重拉會被跳過而永遠漏轉
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => self::VENDOR_ID,
            'reference_no' => $ref,
            'member_id' => 7777,
        ]);
    }

    #[TestDox('useCache：同一時間片第二次不再打三方（快取命中不佔 rate limit 預算）')]
    public function test_use_cache_skips_third_party_on_second_run(): void
    {
        $this->createVendorInfo('token-a');
        $ref = 'UT-CACHE-' . uniqid();

        $driver = $this->mockDriver();
        $driver->shouldReceive('fetchBetRecords')->once()->andReturn([$this->betRecordRow($ref)]);
        $driver->shouldReceive('formatBaseTotalBetRecord')->once()->andReturnNull();

        $service = app(BetRecordFetchService::class);
        $first = $service->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00', useCache: true);
        $second = $service->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00', useCache: true);

        $this->assertSame(1, $first->inserted);
        // 第二次的三方筆數來自快取；已入庫故去重不再新增
        $this->assertSame(1, $second->fetched);
        $this->assertSame(0, $second->inserted);
    }

    #[TestDox('useCache：三方拉取失敗不進快取，下一次照常重打')]
    public function test_failed_fetch_is_not_cached(): void
    {
        $this->createVendorInfo('token-a');
        $calls = 0;

        $driver = $this->mockDriver();
        $driver->shouldReceive('fetchBetRecords')->twice()->andReturnUsing(function () use (&$calls): array {
            if (++$calls === 1) {
                throw new \RuntimeException('third party down');
            }

            return [];
        });

        $service = app(BetRecordFetchService::class);

        try {
            $service->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00', useCache: true);
            $this->fail('預期拋出例外');
        } catch (\RuntimeException) {
            // 失敗上拋、不得寫入快取
        }

        $result = $service->fetchChunk(self::VENDOR_ID, $driver, '2026-08-06 10:00:00', '2026-08-06 11:00:00', useCache: true);
        $this->assertSame(0, $result->fetched);
    }

    #[TestDox('後台按下中斷：停在片與片之間，回報續跑起點並與 rate limit 中止區分')]
    public function test_cancel_signal_stops_between_chunks(): void
    {
        $this->createVendorInfo('token-a');
        $this->contextOperationLog(cancelRequested: true);

        $driver = $this->mockDriver(baseClass: AbstractTransferWallet::class);
        // 第一片跑完就該停：剩下兩片不會再打三方
        $driver->shouldReceive('fetchBetRecords')->once()->andReturn([]);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);

        // 窗口 3 小時 ＝ 3 片，預算充足，中止只可能來自中斷旗標
        $results = (new BetRecordFetchService($vendorService))->fetchNow(self::VENDOR_ID, '2026-08-06 10:00:00', '2026-08-06 13:00:00');

        $this->assertTrue($results[0]->cancelled);
        $this->assertSame(1, $results[0]->chunks);
        $this->assertSame('2026-08-06 11:00:00', $results[0]->pendingFrom);
    }

    #[TestDox('有操作紀錄但未按中斷：等預算的拆段輪詢照常睡滿，窗口完整跑完')]
    public function test_rate_limit_wait_is_not_mistaken_for_cancellation(): void
    {
        $this->createVendorInfo('token-a');
        $this->contextOperationLog(cancelRequested: false);

        // 預算 1 次／秒並先用掉：第一片必定撞牆，等待期走拆段輪詢
        $driver = $this->mockDriver(rateLimit: ['max_calls' => 1, 'per_seconds' => 1], baseClass: AbstractTransferWallet::class);
        $driver->shouldReceive('fetchBetRecords')->andReturn([]);
        RateLimiter::hit('fetch_bet_record:' . self::VENDOR_ID, 1);

        $vendorService = Mockery::mock(VendorService::class);
        $vendorService->shouldReceive('getDriver')->andReturn($driver);

        $results = (new BetRecordFetchService($vendorService))->fetchNow(self::VENDOR_ID, '2026-08-06 10:00:00', '2026-08-06 11:00:00');

        $this->assertFalse($results[0]->cancelled);
        $this->assertNull($results[0]->pendingFrom);
        $this->assertSame(1, $results[0]->chunks);
    }

    /**
     * 建一筆操作紀錄並寫進 Context，模擬「由後台 RunAdminOperationJob 觸發」
     */
    private function contextOperationLog(bool $cancelRequested): AdminOperationLog
    {
        $log = AdminOperationLog::create([
            'user_id' => 1,
            'operation' => AdminOperationEnum::FetchBetRecord->value,
            'params' => [],
            'status' => AdminOperationStatusEnum::RUNNING->value,
            'cancel_requested_at' => $cancelRequested ? now() : null,
        ]);

        Context::addHidden(ContextKeyEnum::ADMIN_OPERATION_LOG_ID->value, $log->id);

        return $log;
    }

    /**
     * @return MockInterface&SupportsBetRecordFetch
     */
    private function mockDriver(
        array $rateLimit = ['max_calls' => 10, 'per_seconds' => 300],
        ?string $minStartTime = null,
        ?\Closure $onSetInfo = null,
        ?string $baseClass = null,
    ): MockInterface {
        // VendorService::getDriver() 的回傳型別是 Driver 抽象類別，需要時連基底一起 mock
        $driver = $baseClass === null
            ? Mockery::mock(SupportsBetRecordFetch::class)
            : Mockery::mock($baseClass, SupportsBetRecordFetch::class);
        // 服務對每組憑證先載入商戶狀態，再呼叫其餘拉單方法
        $driver->shouldReceive('setInfo')->andReturnUsing($onSetInfo ?? fn (array $info) => null);
        $driver->shouldReceive('betRecordApiWindowMinutes')->andReturn(60);
        $driver->shouldReceive('betRecordMinStartTime')->andReturn($minStartTime);
        $driver->shouldReceive('betRecordApiRateLimit')->andReturn($rateLimit);
        $driver->shouldReceive('betRecordCredentialKey')
            ->andReturnUsing(fn (array $info): string => (string) ($info['TOKEN'] ?? ''));
        $driver->shouldReceive('betRecordUniqueKey')->andReturn('referenceID');
        $driver->shouldReceive('betRecordAccountColumn')->andReturn('player_id');
        $driver->shouldReceive('getBetRecordModelClass')->andReturn(Ace333BetRecord::class);
        $driver->shouldReceive('getAccountModelClass')->andReturn(Ace333Account::class);
        // 三方原始列已由 betRecordRow() 產成注單表欄位，直接原樣通過
        $driver->shouldReceive('formatBetRecord')->andReturnUsing(fn (array $raw) => $raw);

        return $driver;
    }

    private function createVendorInfo(string $token, int $status = 1): VendorInfo
    {
        return VendorInfo::factory()->create([
            'vendor_id' => self::VENDOR_ID,
            'status' => $status,
            'rate' => 1,
            'info' => ['API_URL' => 'https://example.invalid', 'TOKEN' => $token],
        ]);
    }

    /**
     * ace333_bet_records 完整欄位列（借用作 mock driver 的儲存端）
     *
     * @return array<string, mixed>
     */
    private function betRecordRow(string $ref, string $playerId = 'UT-PLAYER-A'): array
    {
        return [
            'playerID' => $playerId,
            'extPlayerID' => '',
            'gameID' => 'slot-x',
            'transactionType' => 'BR',
            'referenceID' => $ref,
            'status' => 'C',
            'created' => '2026-08-06 10:10:00',
            'updated' => '2026-08-06 10:10:00',
            'betAmount' => '10.0000',
            'winAmount' => '20.0000',
            'jackpotModule' => '',
            'jackpotContributionAmt' => '0.0000',
            'currency' => 'MYR',
            'resultUrl' => '',
            'roundDetails' => '',
            'platform' => 'WEB',
            'info' => '{}',
            'admin' => 0,
        ];
    }
}
