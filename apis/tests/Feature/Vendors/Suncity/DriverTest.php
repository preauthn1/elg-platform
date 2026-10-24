<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Suncity;

use App\Data\InsertedBetRecordData;
use App\Data\Vendor\CreateAccountParamsData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorTransferData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Models\Game;
use App\Models\Vendor;
use App\Models\VendorInfo;
use App\Services\BetRecordFetchService;
use App\Vendors\Contracts\ShouldScheduleBetRecordFetch;
use App\Vendors\Models\SuncityAccount;
use App\Vendors\Models\SuncityBetRecord;
use App\Vendors\Suncity\Driver;
use ArrayObject;
use Carbon\Carbon;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use Tests\TestCase;

/**
 * SunCity（vendor 292，iGaming 平台 Clubsuncity 品牌）轉帳錢包：繼承 Play8oy Driver，
 * 注單由 APIs fetch_bet_record 排程拉取（target/tickets#4434）。
 *
 * php artisan test tests/Feature/Vendors/Suncity/DriverTest.php --compact
 */
class DriverTest extends TestCase
{
    private const string API_URL = 'https://api.igamingdemo.test/';

    private const array INFO = [
        'API_URL' => self::API_URL,
        'API_MERCHANT_NAME' => 'hero9stg',
        'API_AGENT' => 'hero9stg01',
        'API_KEY' => 'suncity-test-key',
        'GET_DOWNLOAD_URL' => false,
    ];

    private const string USERNAME = 'hero9stg_00000001';

    /** 文件範例注單列（iGaming_API_Guid_v10.1 game/log/record） */
    private const array RAW_RECORD = [
        'ThemeID' => 57060001,
        'GameNO' => 'G20260929001',
        'GameSerialID' => 345740679818,
        'UserName' => self::USERNAME,
        'BetCoin' => 0.9,
        'WinAmount' => 81.21,
        'AvailTotalBet' => 0.9,
        'AvailTotalWin' => 81.21,
        'CreateTime' => '2026-09-29T11:46:36.047',
    ];

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        if (!Vendor::find(VendorIdEnum::SUNCITY->value)) {
            Vendor::factory()->create(['id' => VendorIdEnum::SUNCITY->value]);
        }
        // SUNCITY 是轉帳錢包：factory 預設 is_single_wallet = 1，會被「單一錢包只放行乾跑」擋下
        Vendor::where('id', VendorIdEnum::SUNCITY->value)->update(['is_single_wallet' => 0]);

        RateLimiter::clear('fetch_bet_record:' . VendorIdEnum::SUNCITY->value);

        $this->driver = new Driver(VendorIdEnum::SUNCITY->value, null);
        $this->driver->setInfo(self::INFO);
    }

    // ── VERIFY_SSL：staging 憑證過期，僅 staging 商戶關閉驗證 ──

    public function test_requests_skip_tls_verification_when_verify_ssl_is_false(): void
    {
        $verifyOptions = $this->fakeAndCaptureVerifyOptions();

        $this->driver->setInfo(['VERIFY_SSL' => false] + self::INFO);
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        $this->driver->getBalance();
        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');

        $this->assertSame(['player/info', 'game/log/record'], array_keys($verifyOptions->getArrayCopy()));
        foreach ($verifyOptions as $path => $verify) {
            $this->assertFalse($verify, $path . ' 應關閉憑證驗證');
        }
    }

    public function test_requests_keep_tls_verification_by_default(): void
    {
        $verifyOptions = $this->fakeAndCaptureVerifyOptions();

        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        $this->driver->getBalance();
        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');

        $this->assertCount(2, $verifyOptions);
        foreach ($verifyOptions as $path => $verify) {
            $this->assertNotFalse($verify, $path . ' 不可關閉憑證驗證');
        }
    }

    // ── 1. deep-link ──

    public function test_get_game_url_returns_lobbyclubsuncity_deep_link(): void
    {
        Http::fake();

        $createdAt = '2026-09-29 10:00:00';
        $account = new SuncityAccount(['username' => self::USERNAME]);
        $account->created_at = Carbon::parse($createdAt);
        $this->driver->setVendorAccount($account);

        $result = $this->driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'lobby',
            'deviceType' => DeviceTypeEnum::MOBILE,
        ]));

        $password = [REDACTED] . strtotime($createdAt)), 8, 12);
        $expected = 'lobbyclubsuncity://lobbyclubsuncity?account=' . self::USERNAME . '&password=' . $password;

        $this->assertSame($expected, $result->app_infos->ios_open);
        $this->assertSame($expected, $result->app_infos->android_open);
        Http::assertNothingSent();
    }

    // ── 2. 簽名獨立驗證 ──

    public function test_sign_matches_vendor_document_constant(): void
    {
        $this->driver->setInfo(['API_KEY' => 'wS%EVgkS2b4sU0@zdiGN'] + self::INFO);

        $sign = (new ReflectionMethod($this->driver, 'sign'))->invoke($this->driver, [
            'Agent' => 'RD_TestEntity1',
            'Password' => '123456',
            'PlayerAccount' => 't1_botest21',
        ]);

        // 期望值取自廠商文件 2.2 範例，非以產線 helper 重算
        $this->assertSame('7XxkcLhd+AbNO2xf7M+VPNUYJ6zuYFcLn1SudAZVp10=', $sign);
    }

    // ── 3. 轉帳三態 ──

    public function test_transfer_to_vendor_sends_positive_amount_and_maps_results(): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/create' => Http::sequence()
            ->push(['Code' => 0, 'Message' => 'Success.'])
            ->push(['Code' => -90606, 'Message' => 'Insufficient balance.'])
            ->push('', 200)]);

        $params = VendorTransferData::from(['transactionLogId' => 1, 'transactionNo' => 'TXN0000000000000001', 'vendorAmount' => '10.5']);

        $this->assertSame(VendorTransferResultEnum::SUCCESS, $this->driver->transferToVendor($params));
        $this->assertSame(VendorTransferResultEnum::FAILED, $this->driver->transferToVendor($params));
        $this->assertSame(VendorTransferResultEnum::TIMEOUT, $this->driver->transferToVendor($params));

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . 'transaction/create'
            && $request['Amount'] === 10.5
            && $request['PlayerAccount'] === self::USERNAME
            && $request['ExternalTransactionId'] === 'TXN0000000000000001'
            && $request->hasHeader('merchantName', 'hero9stg')
            && $request->hasHeader('Sign'));
    }

    public function test_transfer_to_local_sends_negative_amount_and_maps_results(): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/create' => Http::sequence()
            ->push(['Code' => 0, 'Message' => 'Success.'])
            ->push(['Code' => -90606, 'Message' => 'Insufficient balance.'])
            ->push('', 200)]);

        $params = VendorTransferData::from(['transactionLogId' => 2, 'transactionNo' => 'TXN0000000000000002', 'vendorAmount' => '10.5']);

        $this->assertSame(VendorTransferResultEnum::SUCCESS, $this->driver->transferToLocal($params));
        $this->assertSame(VendorTransferResultEnum::FAILED, $this->driver->transferToLocal($params));
        $this->assertSame(VendorTransferResultEnum::TIMEOUT, $this->driver->transferToLocal($params));

        Http::assertSent(fn (Request $request): bool => $request['Amount'] === -10.5
            && $request['ExternalTransactionId'] === 'TXN0000000000000002'
            && $request->hasHeader('merchantName', 'hero9stg')
            && $request->hasHeader('Sign'));
    }

    /**
     * 錯誤碼分類（繼承 Play8oy）：只有三方明確拒絕才 FAILED，結果不明與未知碼一律 TIMEOUT
     */
    #[TestWith([0, VendorTransferResultEnum::SUCCESS])]
    #[TestWith([-90606, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90617, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90208, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90002, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90003, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90500, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90610, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-99999, VendorTransferResultEnum::TIMEOUT])]
    public function test_transfer_maps_vendor_code_category_to_result(int $code, VendorTransferResultEnum $expected): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/create' => Http::response(['Code' => $code, 'Message' => 'message'])]);

        $params = VendorTransferData::from(['transactionLogId' => 3, 'transactionNo' => 'TXN0000000000000003', 'vendorAmount' => '10']);

        $this->assertSame($expected, $this->driver->transferToVendor($params), '轉入 Code ' . $code);
        $this->assertSame($expected, $this->driver->transferToLocal($params), '轉出 Code ' . $code);
    }

    /**
     * iGaming 業務碼走 HTTP 400（2026-10 staging 實測，dev transaction_log 224274994 被 -90607 拒絕卻卡 8888）：
     * 400 帶明確拒絕碼 → FAILED（可退款）；5xx 與非 JSON → TIMEOUT
     */
    #[TestWith([400, '{"Code":-90607,"Message":"Admin blance is not enough deposit."}', VendorTransferResultEnum::FAILED])]
    #[TestWith([400, '{"Code":-90606,"Message":"Insufficient balance."}', VendorTransferResultEnum::FAILED])]
    #[TestWith([400, '{"Code":-90002,"Message":"Unknown error."}', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([400, '<html><body>Bad Request</body></html>', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([403, '<html><body>Forbidden</body></html>', VendorTransferResultEnum::TIMEOUT])]
    // 200／400 以外的 4xx（gateway／WAF）即使 body 帶拒絕碼也不可退款
    #[TestWith([403, '{"Code":-90607,"Message":"Admin blance is not enough deposit."}', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([500, '', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([500, '{"Code":-90607,"Message":"Admin blance is not enough deposit."}', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([200, '{"Code":0,"Message":"Success."}', VendorTransferResultEnum::SUCCESS])]
    public function test_transfer_maps_http_status_and_business_code_to_result(int $status, string $body, VendorTransferResultEnum $expected): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/create' => Http::response($body, $status)]);

        $params = VendorTransferData::from(['transactionLogId' => 4, 'transactionNo' => 'TXN0000000000000004', 'vendorAmount' => '10']);

        $this->assertSame($expected, $this->driver->transferToVendor($params), '轉入 HTTP ' . $status . ' ' . $body);
        $this->assertSame($expected, $this->driver->transferToLocal($params), '轉出 HTTP ' . $status . ' ' . $body);
    }

    public function test_transfer_throws_api_exception_on_http_400_json_without_code(): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/create' => Http::response('{"message":"Bad Request"}', 400)]);

        $params = VendorTransferData::from(['transactionLogId' => 5, 'transactionNo' => 'TXN0000000000000005', 'vendorAmount' => '10']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('format error');

        $this->driver->transferToVendor($params);
    }

    // ── 4. checkTransferStatus ──

    #[TestWith([400, '{"Code":-90608,"Message":"Not yet processed."}', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([400, '{"Code":-90609,"Message":"Transaction does not exist."}', VendorTransferResultEnum::FAILED])]
    #[TestWith([400, '{"Code":-90001,"Message":"fail"}', VendorTransferResultEnum::FAILED])]
    #[TestWith([500, '', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([404, '{"Code":-90609,"Message":"Transaction does not exist."}', VendorTransferResultEnum::TIMEOUT])]
    public function test_check_transfer_status_maps_http_status_and_business_code_to_result(int $status, string $body, VendorTransferResultEnum $expected): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/check' => Http::response($body, $status)]);

        $this->assertSame($expected, $this->driver->checkTransferStatus('TXN1'), 'HTTP ' . $status . ' ' . $body);
    }

    #[TestWith([-90001, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90003, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-99999, VendorTransferResultEnum::TIMEOUT])]
    public function test_check_transfer_status_maps_remaining_code_categories(int $code, VendorTransferResultEnum $expected): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/check' => Http::response(['Code' => $code])]);

        $this->assertSame($expected, $this->driver->checkTransferStatus('TXN1'));
    }

    public function test_check_transfer_status_maps_vendor_codes(): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'transaction/check' => Http::sequence()
            ->push(['Code' => 0])
            ->push(['Code' => -90608])
            ->push(['Code' => -90609])
            ->push('', 200)]);

        $this->assertSame(VendorTransferResultEnum::SUCCESS, $this->driver->checkTransferStatus('TXN1'));
        $this->assertSame(VendorTransferResultEnum::TIMEOUT, $this->driver->checkTransferStatus('TXN1'));
        $this->assertSame(VendorTransferResultEnum::FAILED, $this->driver->checkTransferStatus('TXN1'));
        $this->assertSame(VendorTransferResultEnum::TIMEOUT, $this->driver->checkTransferStatus('TXN1'));
    }

    // ── 5. getBalance ──

    public function test_get_balance_returns_balance_string(): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'player/info' => Http::response(['Code' => 0, 'Data' => ['Balance' => 12.34]])]);

        $this->assertSame('12.34', $this->driver->getBalance());
    }

    public function test_get_balance_throws_on_vendor_error_code(): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'player/info' => Http::response(['Code' => -90201, 'Message' => 'Player not found.'])]);

        $this->expectException(ApiException::class);

        $this->driver->getBalance();
    }

    public function test_get_balance_throws_api_exception_with_code_on_http_400(): void
    {
        $this->driver->setVendorAccount(new SuncityAccount(['username' => self::USERNAME]));

        Http::fake([self::API_URL . 'player/info' => Http::response('{"Code":-90208,"Message":"Player does not exists."}', 400)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('-90208');

        $this->driver->getBalance();
    }

    // ── 6. createAccount 兩段式 ──

    #[Group('rules/vendor-account-pool#R-12')]
    public function test_create_account_releases_account_after_vendor_success(): void
    {
        Http::fake([self::API_URL . 'player/create' => Http::response(['Code' => 0, 'Message' => 'Success.'])]);

        $result = $this->driver->createAccount($this->createAccountParams(self::USERNAME));

        $this->assertTrue($result->status);

        $account = SuncityAccount::where('username', self::USERNAME)->first();
        $this->assertNotNull($account);
        $this->assertSame(0, (int) $account->member_id, '三方建號成功才釋出到帳號池');

        Http::assertSent(fn (Request $request): bool => $request->url() === self::API_URL . 'player/create'
            && $request['Agent'] === 'hero9stg01'
            && $request['PlayerAccount'] === self::USERNAME);
    }

    #[Group('rules/vendor-account-pool#R-12')]
    public function test_create_account_keeps_placeholder_when_vendor_fails(): void
    {
        Http::fake([self::API_URL . 'player/create' => Http::response(['Code' => -90201, 'Message' => 'Player exists.'])]);

        $result = $this->driver->createAccount($this->createAccountParams('hero9stg_00000002'));

        $this->assertFalse($result->status);
        $this->assertSame(-999999999, (int) SuncityAccount::where('username', 'hero9stg_00000002')->value('member_id'));
    }

    #[Group('rules/vendor-account-pool#R-12')]
    public function test_create_account_keeps_placeholder_when_vendor_rejects_with_http_400(): void
    {
        Http::fake([self::API_URL . 'player/create' => Http::response('{"Code":-90201,"Message":"Player exists."}', 400)]);

        $result = $this->driver->createAccount($this->createAccountParams('hero9stg_00000004'));

        $this->assertFalse($result->status);
        $this->assertSame(-999999999, (int) SuncityAccount::where('username', 'hero9stg_00000004')->value('member_id'));
    }

    public function test_create_account_returns_false_when_response_is_not_json(): void
    {
        Http::fake([self::API_URL . 'player/create' => Http::response('not-json', 200)]);

        $result = $this->driver->createAccount($this->createAccountParams('hero9stg_00000003'));

        $this->assertFalse($result->status);
        $this->assertSame(-999999999, (int) SuncityAccount::where('username', 'hero9stg_00000003')->value('member_id'));
    }

    // ── 7. 拉單契約 ──

    public function test_driver_implements_scheduled_bet_record_fetch_contract(): void
    {
        $this->assertInstanceOf(ShouldScheduleBetRecordFetch::class, $this->driver);
        $this->assertSame('bet_id', $this->driver->betRecordUniqueKey());
        // 三方 UserName 即我方 suncity_accounts.username（含 hero9stg_ 前綴）
        $this->assertSame('username', $this->driver->betRecordAccountColumn());
        $this->assertSame(10, $this->driver->betRecordApiWindowMinutes());
        $this->assertNull($this->driver->betRecordMinStartTime());
        $this->assertSame('hero9stg', $this->driver->betRecordCredentialKey(self::INFO));
        // 一次 fetchBetRecords 可能因跨日拆段與翻頁打出多個請求，對三方 40/分再對折
        $this->assertSame(['max_calls' => 20, 'per_seconds' => 60], $this->driver->betRecordApiRateLimit());
        $this->assertSame(SuncityBetRecord::class, $this->driver->getBetRecordModelClass());
        $this->assertSame(SuncityAccount::class, $this->driver->getAccountModelClass());
    }

    // ── 8. fetchBetRecords 時區與格式 ──

    public function test_fetch_bet_records_converts_utc_window_to_utc8_vendor_format(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response($this->recordResponse([self::RAW_RECORD]))]);

        $records = $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');

        $this->assertCount(1, $records);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), 'game/log/record')
            && $request['PageNumber'] === 1
            && $request['StartDate'] === '2026-09-29 11:40:00.000'
            && $request['EndDate'] === '2026-09-29 11:49:59.999'
            && $request->hasHeader('merchantName', 'hero9stg')
            && $request->hasHeader('Sign'));
    }

    // ── 9. 翻頁 ──

    public function test_fetch_bet_records_follows_pagination(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::sequence()
            ->push($this->recordResponse([['GameSerialID' => 1] + self::RAW_RECORD], pageNumber: 1, totalPage: 3))
            ->push($this->recordResponse([['GameSerialID' => 2] + self::RAW_RECORD], pageNumber: 2, totalPage: 3))
            ->push($this->recordResponse([['GameSerialID' => 3] + self::RAW_RECORD], pageNumber: 3, totalPage: 3))]);

        $records = $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');

        $this->assertSame([1, 2, 3], array_column($records, 'GameSerialID'));
        Http::assertSentCount(3);
        $this->assertSame([1, 2, 3], array_map(
            fn (array $pair): int => $pair[0]['PageNumber'],
            Http::recorded()->all(),
        ));
    }

    // ── 10. 跨日拆段 ──

    public function test_fetch_bet_records_splits_window_crossing_utc8_midnight(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response($this->recordResponse([self::RAW_RECORD]))]);

        $records = $this->driver->fetchBetRecords('2026-09-29 15:55:00', '2026-09-29 16:05:00');

        $this->assertCount(2, $records);
        Http::assertSentCount(2);
        $this->assertSame(
            [
                ['2026-09-29 23:55:00.000', '2026-09-29 23:59:59.999'],
                ['2026-09-30 00:00:00.000', '2026-09-30 00:04:59.999'],
            ],
            array_map(
                fn (array $pair): array => [$pair[0]['StartDate'], $pair[0]['EndDate']],
                Http::recorded()->all(),
            ),
        );
    }

    public function test_fetch_bet_records_sends_single_request_for_same_day_window(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response($this->recordResponse([]))]);

        // UTC+8 23:50～00:00：to - 1 秒仍在同一日，不拆段
        $this->driver->fetchBetRecords('2026-09-29 15:50:00', '2026-09-29 16:00:00');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['StartDate'] === '2026-09-29 23:50:00.000'
            && $request['EndDate'] === '2026-09-29 23:59:59.999');
    }

    // ── 11. 錯誤碼 ──

    public function test_fetch_bet_records_returns_empty_array_on_no_data_code(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response(['Code' => -50001, 'Message' => 'No data.'])]);

        $this->assertSame([], $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00'));
    }

    /**
     * 業務碼走 HTTP 400：-50001 查無資料不可被 throw() 攔成時間片失敗
     */
    public function test_fetch_bet_records_returns_empty_array_on_http_400_no_data_code(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response('{"Code":-50001,"Message":"No data."}', 400)]);

        $this->assertSame([], $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00'));
    }

    public function test_fetch_bet_records_throws_api_exception_on_http_400_error_code(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response('{"Code":-90003,"Message":"Unexpected error."}', 400)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('-90003');

        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
    }

    public function test_fetch_bet_records_throws_on_unexpected_error_code(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::sequence()
            ->push(['Code' => -90003, 'Message' => 'Unexpected error.'])
            ->push(['Code' => -10005, 'Message' => 'Bad Request.'])]);

        foreach ([-90003, -10005] as $code) {
            try {
                $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
                $this->fail("Code {$code} 應拋 ApiException，不可回空陣列");
            } catch (ApiException $e) {
                $this->assertStringContainsString((string) $code, $e->getMessage());
            }
        }
    }

    /**
     * Code 0 但缺 Data.Record 或 Data.Page.TotalPage：靜默回空會讓時間片被標記完成而永久缺帳
     */
    public function test_fetch_bet_records_throws_when_record_list_missing(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response([
            'Code' => 0,
            'Data' => ['Page' => ['PageNumber' => 1, 'TotalPage' => 1]],
        ])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('format error');

        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
    }

    public function test_fetch_bet_records_throws_when_total_page_missing(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response([
            'Code' => 0,
            'Data' => ['Page' => ['PageNumber' => 1], 'Record' => [self::RAW_RECORD]],
        ])]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('format error');

        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
    }

    public function test_fetch_bet_records_throws_when_later_page_fails(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::sequence()
            ->push($this->recordResponse([self::RAW_RECORD], pageNumber: 1, totalPage: 2))
            ->push(['Code' => -90003, 'Message' => 'Unexpected error.'])]);

        // 不可回傳第 1 頁的部分結果：時間片會被標記完成，第 2 頁永久缺帳
        $this->expectException(ApiException::class);

        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
    }

    /**
     * 第 1 頁宣告 TotalPage=3、第 2 頁卻回 -50001：三方資料不一致，不可把已拉到的部分當完整結果
     */
    #[TestWith([200])]
    #[TestWith([400])]
    public function test_fetch_bet_records_throws_when_later_page_returns_no_data(int $status): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::sequence()
            ->push($this->recordResponse([self::RAW_RECORD], pageNumber: 1, totalPage: 3))
            ->push(['Code' => -50001, 'Message' => 'No data.'], $status)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('page 2 returned no-data while total pages is 3');

        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
    }

    public function test_fetch_bet_records_throws_when_second_day_segment_fails(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::sequence()
            ->push($this->recordResponse([self::RAW_RECORD]))
            ->push(['Code' => -50004, 'Message' => 'Date range error.'])]);

        $this->expectException(ApiException::class);

        $this->driver->fetchBetRecords('2026-09-29 15:55:00', '2026-09-29 16:05:00');
    }

    public function test_fetch_bet_records_throws_on_http_error(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response('', 500)]);

        // 5xx 必須拋例外讓時間片失敗重跑，絕不可回空
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('500');

        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
    }

    /**
     * 三方對當日查詢要求 EndDate 早於其伺服器現在時間（-50003），窗口尾端貼著現在時須往前夾 120 秒
     */
    public function test_fetch_bet_records_clamps_window_end_to_safety_lag_before_now(): void
    {
        Carbon::setTestNow('2026-10-01 04:00:00');
        Http::fake([self::API_URL . 'game/log/record' => Http::response($this->recordResponse([]))]);

        $this->driver->fetchBetRecords('2026-10-01 03:50:00', '2026-10-01 04:00:00');

        // now − 120 秒 = 03:58:00 UTC，再 −1 秒取 .999 → UTC+8 11:57:59.999
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request['StartDate'] === '2026-10-01 11:50:00.000'
            && $request['EndDate'] === '2026-10-01 11:57:59.999');
    }

    public function test_fetch_bet_records_skips_window_entirely_inside_safety_lag(): void
    {
        Carbon::setTestNow('2026-10-01 04:00:00');
        Http::fake();

        $this->assertSame([], $this->driver->fetchBetRecords('2026-10-01 03:59:00', '2026-10-01 04:00:00'));
        Http::assertNothingSent();
    }

    public function test_fetch_bet_records_keeps_window_end_older_than_safety_lag(): void
    {
        Carbon::setTestNow('2026-10-01 04:00:00');
        Http::fake([self::API_URL . 'game/log/record' => Http::response($this->recordResponse([]))]);

        $this->driver->fetchBetRecords('2026-10-01 03:40:00', '2026-10-01 03:50:00');

        Http::assertSent(fn (Request $request): bool => $request['StartDate'] === '2026-10-01 11:40:00.000'
            && $request['EndDate'] === '2026-10-01 11:49:59.999');
    }

    public function test_fetch_bet_records_returns_empty_without_request_for_empty_window(): void
    {
        Http::fake();

        $this->assertSame([], $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:40:00'));
        Http::assertNothingSent();
    }

    public function test_fetch_bet_records_throws_on_non_json_response(): void
    {
        Http::fake([self::API_URL . 'game/log/record' => Http::response('<html>gateway</html>', 200)]);

        $this->expectException(ApiException::class);

        $this->driver->fetchBetRecords('2026-09-29 03:40:00', '2026-09-29 03:50:00');
    }

    // ── 12. formatBetRecord ──

    public function test_format_bet_record_maps_document_sample(): void
    {
        $row = $this->driver->formatBetRecord(self::RAW_RECORD, '1');

        $this->assertSame('345740679818', $row['bet_id']);
        $this->assertSame(self::USERNAME, $row['username']);
        // ThemeID 前四碼 = GameID（對應 games.game_type）
        $this->assertSame('5706', $row['game_code']);
        $this->assertSame('0.9000', $row['bet']);
        $this->assertSame('0.9000', $row['valid_bet']);
        $this->assertSame('81.2100', $row['win']);
        $this->assertSame('80.3100', $row['win_lose']);
        $this->assertSame('0.0000', $row['rolling_chip']);
        // CreateTime 為 UTC+8，入庫轉 UTC
        $this->assertSame('2026-09-29 03:46:36', $row['bet_time']);
        $this->assertSame('2026-09-29 03:46:36', $row['payout_time']);
        $this->assertSame(1, $row['status']);
        $this->assertSame(self::RAW_RECORD, json_decode($row['info'], true));
        $this->assertArrayNotHasKey('vendor_info_id', $row);
        $this->assertArrayNotHasKey('member_id', $row);
    }

    public function test_format_bet_record_divides_amounts_by_rate(): void
    {
        $row = $this->driver->formatBetRecord(self::RAW_RECORD, '2');

        $this->assertSame('0.4500', $row['bet']);
        $this->assertSame('0.4500', $row['valid_bet']);
        $this->assertSame('40.6050', $row['win']);
        $this->assertSame('40.1550', $row['win_lose']);
    }

    public function test_format_bet_record_treats_zero_rate_as_one(): void
    {
        $row = $this->driver->formatBetRecord(self::RAW_RECORD, '0');

        $this->assertSame('0.9000', $row['bet']);
        $this->assertSame('81.2100', $row['win']);
        $this->assertSame('80.3100', $row['win_lose']);
    }

    // ── 13. formatBaseTotalBetRecord ──

    public function test_format_base_total_bet_record_maps_settled_record(): void
    {
        $row = $this->driver->formatBetRecord(self::RAW_RECORD, '1');

        $base = $this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData($row['bet_id'], $row));

        $this->assertNotNull($base);
        $this->assertSame(self::USERNAME, $base->accountKey);
        $this->assertSame('5706', $base->gameCode);
        $this->assertSame('345740679818', $base->referenceNo);
        $this->assertSame('0.9000', $base->bet);
        $this->assertSame('0.9000', $base->validBet);
        $this->assertSame('80.3100', $base->winLose);
        $this->assertSame('2026-09-29 03:46:36', $base->betTime);
        $this->assertSame('2026-09-29 03:46:36', $base->payoutTime);
    }

    public function test_format_base_total_bet_record_skips_unsettled_record(): void
    {
        $row = ['status' => 0] + $this->driver->formatBetRecord(self::RAW_RECORD, '1');

        $this->assertNull($this->driver->formatBaseTotalBetRecord(new InsertedBetRecordData($row['bet_id'], $row)));
    }

    // ── 拉單整合：寫入 suncity_bet_records 並轉入 total_bet_records ──

    public function test_fetch_chunk_inserts_bet_records_and_transfers_to_total_bet_records(): void
    {
        Vendor::where('id', VendorIdEnum::SUNCITY->value)->update(['status' => 1, 'valid_bet_rate' => 1]);

        $vendorInfo = VendorInfo::factory()->create([
            'vendor_id' => VendorIdEnum::SUNCITY->value,
            'status' => 1,
            'rate' => 1,
            'info' => self::INFO,
        ]);

        SuncityAccount::create([
            'vendor_info_id' => $vendorInfo->id,
            'username' => self::USERNAME,
            'member_id' => 8888,
            'admin' => 0,
        ]);

        $game = Game::factory()->create([
            'vendor_id' => VendorIdEnum::SUNCITY->value,
            'game_type' => '5706',
            'valid_bet_rate' => 0.5,
        ]);

        Http::fake([self::API_URL . 'game/log/record' => Http::response($this->recordResponse([self::RAW_RECORD]))]);

        $result = app(BetRecordFetchService::class)->fetchChunk(
            VendorIdEnum::SUNCITY->value,
            $this->driver,
            '2026-09-29 03:40:00',
            '2026-09-29 03:50:00',
        );

        $this->assertSame(1, $result->inserted);

        $betRecord = SuncityBetRecord::where('bet_id', '345740679818')->first();
        $this->assertNotNull($betRecord);
        $this->assertSame(1, (int) $betRecord->status);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => VendorIdEnum::SUNCITY->value,
            'bet_record_id' => $betRecord->id,
            'member_id' => 8888,
            'game_id' => $game->id,
            'reference_no' => '345740679818',
            'bet' => '0.9000',
            'valid_bet' => '0.9000',
            'win_lose' => '80.3100',
            'valid_bet_rate' => '0.5000',
            'summary_time' => '2026-09-29 03:00:00',
            'bet_time' => '2026-09-29 03:46:36',
            'payout_time' => '2026-09-29 03:46:36',
        ]);
    }

    /**
     * fake 所有請求並記下各路徑實際送出的 Guzzle verify 選項（未設定記 null）
     *
     * @return ArrayObject<string, mixed>
     */
    private function fakeAndCaptureVerifyOptions(): ArrayObject
    {
        $verifyOptions = new ArrayObject;

        Http::fake(function (Request $request, array $options) use ($verifyOptions) {
            $verifyOptions[substr($request->url(), strlen(self::API_URL))] = $options['verify'] ?? null;

            return Http::response(str_ends_with($request->url(), 'game/log/record')
                ? $this->recordResponse([])
                : ['Code' => 0, 'Data' => ['Balance' => 1]]);
        });

        return $verifyOptions;
    }

    private function createAccountParams(string $username): CreateAccountParamsData
    {
        $agent = $this->createAgentAndDomain();
        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::SUNCITY->value);

        $vendorInfo = $vendor->vendorInfos()->first();
        $vendorInfo->account_prefix = 'hero9stg_';
        $vendorInfo->status = 1;
        $vendorInfo->rate = 1.0;
        $vendorInfo->info = self::INFO;
        $vendorInfo->save();

        return CreateAccountParamsData::from([
            'vendorInfo' => $vendorInfo,
            'serialNumber' => 1,
            'username' => $username,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function recordResponse(array $records, int $pageNumber = 1, int $totalPage = 1): array
    {
        return [
            'Code' => 0,
            'Message' => 'Success.',
            'Data' => [
                'Page' => ['PageNumber' => $pageNumber, 'PageSize' => 100, 'TotalSize' => count($records), 'TotalPage' => $totalPage],
                'Record' => $records,
            ],
        ];
    }
}
