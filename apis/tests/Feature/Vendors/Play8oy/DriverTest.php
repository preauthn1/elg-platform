<?php

declare(strict_types=1);

namespace Tests\Feature\Vendors\Play8oy;

use App\Data\Vendor\CreateAccountParamsData;
use App\Data\VendorGameUrlParamsData;
use App\Data\VendorResetPasswordData;
use App\Data\VendorTransferData;
use App\Enums\General\DeviceTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Enums\Vendor\VendorTransferResultEnum;
use App\Exceptions\ApiException;
use App\Models\VendorInfo;
use App\Vendors\Models\Play8oyAccount;
use App\Vendors\Play8oy\Driver;
use ArrayObject;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Play8oy（vendor 30）行為釘樁：SunCity 繼承本 Driver（target/tickets#4434），
 * 參數化重構（deep-link scheme 抽屬性、建帳號屬性抽 hook）不得改變 Play8oy 既有行為；
 * 簽名原始字串化與轉帳錯誤碼分類為刻意的行為變更，以測試釘住新語意。
 *
 * php artisan test tests/Feature/Vendors/Play8oy/DriverTest.php --compact
 */
class DriverTest extends TestCase
{
    private const string API_URL = 'https://play8oy.test/';

    private const string API_AGENT = 'Play8oyAgent01';

    private const array INFO = [
        'API_URL' => self::API_URL,
        'API_MERCHANT_NAME' => 'play8oy-merchant',
        'API_AGENT' => self::API_AGENT,
        'API_KEY' => 'play8oy-test-key',
        'GET_DOWNLOAD_URL' => false,
    ];

    private VendorInfo $vendorInfo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $agent = $this->createAgentAndDomain();

        $vendor = $this->createBasicVendorGames($agent, VendorIdEnum::PLAY8OY->value);

        $this->vendorInfo = $vendor->vendorInfos()->first();
        $this->vendorInfo->account_prefix = 'p8_';
        $this->vendorInfo->status = 1;
        $this->vendorInfo->rate = 1.0;
        $this->vendorInfo->info = self::INFO;
        $this->vendorInfo->save();
    }

    public function test_get_game_url_returns_lobbyplay8oy_deep_link(): void
    {
        Http::fake();

        $username = 'p8_00000001';
        $createdAt = '2026-09-29 10:00:00';

        $account = new Play8oyAccount(['username' => $username]);
        $account->created_at = Carbon::parse($createdAt);

        $driver = new Driver(VendorIdEnum::PLAY8OY->value, null);
        $driver->setInfo(self::INFO);
        $driver->setVendorAccount($account);

        $result = $driver->getGameUrl(VendorGameUrlParamsData::from([
            'gameCode' => 'lobby',
            'deviceType' => DeviceTypeEnum::MOBILE,
        ]));

        // 密碼與建號送三方的同式：md5(username . 建立時間 timestamp) 取第 9 碼起 12 碼
        $password = [REDACTED] . strtotime($createdAt)), 8, 12);
        $expected = 'lobbyplay8oy://lobbyplay8oy?account=' . $username . '&password=' . $password;

        $this->assertSame($expected, $result->app_infos->ios_open);
        $this->assertSame($expected, $result->app_infos->android_open);
        $this->assertSame($username, $result->app_infos->username);
        $this->assertSame($password, $result->app_infos->password);

        // GET_DOWNLOAD_URL = false 時不呼叫三方 appurl
        Http::assertNothingSent();
    }

    /**
     * 純英數參數（轉帳／查單實際送出的組合）新算法與舊 http_build_query 算法等值：轉帳線上行為不變
     */
    public function test_sign_matches_legacy_http_build_query_for_alphanumeric_params(): void
    {
        $cases = [
            ['PlayerAccount' => 'p8_00000001', 'Amount' => 100.0, 'ExternalTransactionId' => '0199a1b2c3d4e5f60718'],
            ['PlayerAccount' => 'p8_00000001', 'Amount' => -50.5, 'ExternalTransactionId' => '0199a1b2c3d4e5f60719'],
            ['PlayerAccount' => 'p8_00000001', 'ExternalTransactionId' => '0199a1b2c3d4e5f60720'],
            ['PlayerAccount' => 'p8_00000001'],
        ];

        foreach ($cases as $data) {
            $sorted = $data;
            ksort($sorted);
            $legacy = base64_encode(hash('sha256', http_build_query($sorted) . '&PrivateKey=' . self::INFO['API_KEY'], true));

            $this->assertSame($legacy, $this->sign($data), json_encode($data));
        }

        // 無參數時僅 PrivateKey
        $this->assertSame(
            base64_encode(hash('sha256', 'PrivateKey=' . self::INFO['API_KEY'], true)),
            $this->sign([]),
        );
    }

    /**
     * 含空格與冒號的日期參數（game/log/record）依廠商文件 2.2 以原始字串簽名，不可被 URL 編碼
     */
    public function test_sign_uses_raw_string_for_datetime_params(): void
    {
        $data = [
            'PageNumber' => 1,
            'StartDate' => '2026-09-29 11:40:00.000',
            'EndDate' => '2026-09-29 11:49:59.999',
        ];

        // 依文件 2.2 公式手寫：參數名升冪、原始值、尾接 PrivateKey
        // 舊 http_build_query 會把空格編成 +、冒號編成 %3A，與廠商驗簽不符
        $rawString = 'EndDate=2026-09-29 11:49:59.999&PageNumber=1&StartDate=2026-09-29 11:40:00.000&PrivateKey=' . self::INFO['API_KEY'];

        $this->assertSame(base64_encode(hash('sha256', $rawString, true)), $this->sign($data));
    }

    /**
     * transaction/create 錯誤碼分類：只有三方明確拒絕才 FAILED（觸發立即退款），結果不明一律 TIMEOUT
     */
    #[TestWith([0, VendorTransferResultEnum::SUCCESS])]
    // 明確拒絕：三方確定沒有入帳／扣款
    #[TestWith([-90208, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90212, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90600, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90601, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90602, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90603, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90604, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90605, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90606, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90607, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90615, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90616, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90617, VendorTransferResultEnum::FAILED])]
    // 結果不明：三方可能已處理，不可自動退款
    #[TestWith([-90002, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90003, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90500, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90501, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90610, VendorTransferResultEnum::TIMEOUT])]
    // 文件外的未知碼
    #[TestWith([-10010, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-99999, VendorTransferResultEnum::TIMEOUT])]
    public function test_transfer_maps_vendor_code_to_result(int $code, VendorTransferResultEnum $expected): void
    {
        Http::fake([self::API_URL . 'transaction/create' => Http::response(['Code' => $code, 'Message' => 'message'])]);

        $driver = $this->driverWithAccount();
        $params = VendorTransferData::from(['transactionLogId' => 1, 'transactionNo' => 'TXN0000000000000001', 'vendorAmount' => '10']);

        $this->assertSame($expected, $driver->transferToVendor($params), '轉入 Code ' . $code);
        $this->assertSame($expected, $driver->transferToLocal($params), '轉出 Code ' . $code);
    }

    public function test_transfer_returns_timeout_on_empty_body(): void
    {
        Http::fake([self::API_URL . 'transaction/create' => Http::response('', 200)]);

        $params = VendorTransferData::from(['transactionLogId' => 1, 'transactionNo' => 'TXN0000000000000001', 'vendorAmount' => '10']);

        $this->assertSame(VendorTransferResultEnum::TIMEOUT, $this->driverWithAccount()->transferToVendor($params));
    }

    /**
     * iGaming 業務碼走 HTTP 400（2026-10 staging 實測），不可被 throw() 攔成 8888：
     * 400 帶明確拒絕碼 → FAILED（可退款）；5xx 與非 JSON（含 WAF HTML）→ TIMEOUT，即使 5xx body 帶拒絕碼也不可退款
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
    #[TestWith([502, '<html>Bad Gateway</html>', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([200, '{"Code":0,"Message":"Success."}', VendorTransferResultEnum::SUCCESS])]
    public function test_transfer_maps_http_status_and_business_code_to_result(int $status, string $body, VendorTransferResultEnum $expected): void
    {
        Http::fake([self::API_URL . 'transaction/create' => Http::response($body, $status)]);

        $driver = $this->driverWithAccount();
        $params = VendorTransferData::from(['transactionLogId' => 1, 'transactionNo' => 'TXN0000000000000001', 'vendorAmount' => '10']);

        $this->assertSame($expected, $driver->transferToVendor($params), '轉入 HTTP ' . $status . ' ' . $body);
        $this->assertSame($expected, $driver->transferToLocal($params), '轉出 HTTP ' . $status . ' ' . $body);
    }

    /**
     * transaction/check 業務碼同樣走 HTTP 400；5xx 一律 TIMEOUT
     */
    #[TestWith([400, '{"Code":-90608,"Message":"Not yet processed."}', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([400, '{"Code":-90609,"Message":"Transaction does not exist."}', VendorTransferResultEnum::FAILED])]
    #[TestWith([400, '{"Code":-90001,"Message":"fail"}', VendorTransferResultEnum::FAILED])]
    #[TestWith([500, '', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([500, '{"Code":-90001,"Message":"fail"}', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([404, '{"Code":-90609,"Message":"Transaction does not exist."}', VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([200, '{"Code":0,"Message":"Success."}', VendorTransferResultEnum::SUCCESS])]
    public function test_check_transfer_status_maps_http_status_and_business_code_to_result(int $status, string $body, VendorTransferResultEnum $expected): void
    {
        Http::fake([self::API_URL . 'transaction/check' => Http::response($body, $status)]);

        $this->assertSame($expected, $this->driverWithAccount()->checkTransferStatus('TXN0000000000000001'), 'HTTP ' . $status . ' ' . $body);
    }

    /**
     * 400 帶 JSON 但沒有 Code：格式錯誤拋例外，不可當明確拒絕退款
     */
    public function test_transfer_throws_api_exception_on_http_400_json_without_code(): void
    {
        Http::fake([self::API_URL . 'transaction/create' => Http::response('{"message":"Bad Request"}', 400)]);

        $params = VendorTransferData::from(['transactionLogId' => 1, 'transactionNo' => 'TXN0000000000000001', 'vendorAmount' => '10']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('format error');

        $this->driverWithAccount()->transferToVendor($params);
    }

    public function test_get_balance_throws_api_exception_with_code_on_http_400(): void
    {
        Http::fake([self::API_URL . 'player/info' => Http::response('{"Code":-90208,"Message":"Player does not exists."}', 400)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('-90208');

        $this->driverWithAccount()->getBalance();
    }

    public function test_get_balance_throws_api_exception_with_status_on_server_error(): void
    {
        Http::fake([self::API_URL . 'player/info' => Http::response('', 503)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('503');

        $this->driverWithAccount()->getBalance();
    }

    public function test_reset_password_returns_failed_on_http_400_business_code(): void
    {
        Http::fake([self::API_URL . 'player/resetpassword' => Http::response('{"Code":-90208,"Message":"Player does not exists."}', 400)]);

        $result = $this->driverWithAccount()->resetPassword(VendorResetPasswordData::from(['newPassword' => 'newpass123']));

        $this->assertSame(VendorTransferResultEnum::FAILED, $result);
    }

    /**
     * transaction/check 錯誤碼分類：-90001 失敗與 -90609 交易不存在為 FAILED，其餘不明一律 TIMEOUT
     */
    #[TestWith([0, VendorTransferResultEnum::SUCCESS])]
    #[TestWith([-90001, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90609, VendorTransferResultEnum::FAILED])]
    #[TestWith([-90608, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-90003, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-10010, VendorTransferResultEnum::TIMEOUT])]
    #[TestWith([-99999, VendorTransferResultEnum::TIMEOUT])]
    public function test_check_transfer_status_maps_vendor_code_to_result(int $code, VendorTransferResultEnum $expected): void
    {
        Http::fake([self::API_URL . 'transaction/check' => Http::response(['Code' => $code, 'Message' => 'message'])]);

        $this->assertSame($expected, $this->driverWithAccount()->checkTransferStatus('TXN0000000000000001'));
    }

    public function test_check_transfer_status_returns_timeout_on_empty_body(): void
    {
        Http::fake([self::API_URL . 'transaction/check' => Http::response('', 200)]);

        $this->assertSame(VendorTransferResultEnum::TIMEOUT, $this->driverWithAccount()->checkTransferStatus('TXN0000000000000001'));
    }

    /**
     * VERIFY_SSL=false（僅 staging 商戶）：所有出站請求都關閉 TLS 憑證驗證
     */
    public function test_requests_skip_tls_verification_when_verify_ssl_is_false(): void
    {
        $verifyOptions = $this->fakeAndCaptureVerifyOptions();

        $driver = $this->driverWithAccount(['VERIFY_SSL' => false, 'GET_DOWNLOAD_URL' => true] + self::INFO);
        $account = new Play8oyAccount(['username' => 'p8_00000001']);
        $account->created_at = Carbon::parse('2026-09-29 10:00:00');
        $driver->setVendorAccount($account);
        $params = VendorTransferData::from(['transactionLogId' => 1, 'transactionNo' => 'TXN0000000000000001', 'vendorAmount' => '10']);

        $driver->getGameUrl(VendorGameUrlParamsData::from(['gameCode' => 'lobby', 'deviceType' => DeviceTypeEnum::MOBILE]));
        $driver->getBalance();
        $driver->transferToVendor($params);
        $driver->checkTransferStatus('TXN0000000000000001');

        $this->assertSame(['appurl', 'player/info', 'transaction/create', 'transaction/check'], array_keys($verifyOptions->getArrayCopy()));
        foreach ($verifyOptions as $path => $verify) {
            $this->assertFalse($verify, $path . ' 應關閉憑證驗證');
        }
    }

    /**
     * 未設定 VERIFY_SSL：維持預設驗證憑證（fail-closed），正式商戶不受影響
     */
    public function test_requests_keep_tls_verification_by_default(): void
    {
        $verifyOptions = $this->fakeAndCaptureVerifyOptions();

        $driver = $this->driverWithAccount();
        $params = VendorTransferData::from(['transactionLogId' => 1, 'transactionNo' => 'TXN0000000000000001', 'vendorAmount' => '10']);

        $driver->getBalance();
        $driver->transferToVendor($params);
        $driver->checkTransferStatus('TXN0000000000000001');

        $this->assertCount(3, $verifyOptions);
        foreach ($verifyOptions as $path => $verify) {
            $this->assertNotFalse($verify, $path . ' 不可關閉憑證驗證');
        }
    }

    #[Group('rules/vendor-account-pool#R-12')]
    public function test_create_account_writes_username_modified_and_releases_account(): void
    {
        Http::fake([
            self::API_URL . 'player/create' => Http::response(['Code' => 0, 'Message' => 'Success']),
        ]);

        $driver = new Driver(VendorIdEnum::PLAY8OY->value, null);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 1,
            'username' => 'p8_00000001',
        ]));

        $this->assertTrue($result->status);
        $this->assertSame('p8_00000001', $result->username);

        $account = Play8oyAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'p8_00000001')
            ->first();

        $this->assertNotNull($account);
        // 舊系統慣例：username_modified = 小寫 agent + username
        $this->assertSame('play8oyagent01p8_00000001', $account->username_modified);
        $this->assertSame(0, (int) $account->member_id, '三方建號成功才釋出到帳號池');

        // Agent 送原樣大小寫
        Http::assertSent(fn ($request): bool => $request->url() === self::API_URL . 'player/create'
            && $request['Agent'] === self::API_AGENT
            && $request['PlayerAccount'] === 'p8_00000001');
    }

    #[Group('rules/vendor-account-pool#R-12')]
    public function test_create_account_keeps_placeholder_member_id_when_vendor_fails(): void
    {
        Http::fake([
            self::API_URL . 'player/create' => Http::response(['Code' => -90201, 'Message' => 'Player exists']),
        ]);

        $driver = new Driver(VendorIdEnum::PLAY8OY->value, null);

        $result = $driver->createAccount(CreateAccountParamsData::from([
            'vendorInfo' => $this->vendorInfo,
            'serialNumber' => 2,
            'username' => 'p8_00000002',
        ]));

        $this->assertFalse($result->status);

        $account = Play8oyAccount::where('vendor_info_id', $this->vendorInfo->id)
            ->where('username', 'p8_00000002')
            ->first();

        $this->assertNotNull($account, '三方失敗時本地列保留佔位，不進帳號池');
        $this->assertSame(-999999999, (int) $account->member_id);
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

            return Http::response(['Code' => 0, 'Data' => ['Balance' => 1, 'Android' => 'a', 'iOS' => 'i']]);
        });

        return $verifyOptions;
    }

    /**
     * @param  array<string, mixed>  $info
     */
    private function driverWithAccount(array $info = self::INFO): Driver
    {
        $driver = new Driver(VendorIdEnum::PLAY8OY->value, null);
        $driver->setInfo($info);
        $driver->setVendorAccount(new Play8oyAccount(['username' => 'p8_00000001']));

        return $driver;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function sign(array $data): string
    {
        $driver = new Driver(VendorIdEnum::PLAY8OY->value, null);
        $driver->setInfo(self::INFO);

        return (new ReflectionMethod($driver, 'sign'))->invoke($driver, $data);
    }
}
