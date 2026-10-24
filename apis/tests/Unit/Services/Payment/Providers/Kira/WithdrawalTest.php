<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Kira;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\Kira\Driver as KiraDriver;
use App\Services\Payment\Providers\Kira\Withdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Kira 代付測試
 *
 * 涵蓋 pre-HTTP 路徑（缺參數／不支援銀行／訂單不存在／token 取得失敗）與 HTTP 路徑。
 * 例外分類原則：只有明確拒絕（結構完整且業務碼非 200）才 -2、訂單轉 29 可重新發起；
 * 結構缺失／code=200 後的解密失敗或缺欄位／HTTP 錯誤（含 520、522）／連線例外一律 -1（結果未知，訂單停留 21）。
 * HTTP 路徑以：①setUp 動態 RSA key 滿足 generateSignature 簽章 ②seed token 快取跳過
 * fetchAccessToken ③encryptResponse() 以與 KiraTrait::aesEncrypt 相同邏輯造 encryptedData。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private const SYMMETRIC_KEY = '0123456789abcdef0123456789abcdef'; // 32 bytes（AES-256）

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        // 動態 RSA key pair 僅供 generateSignature 的 rsaSign 簽章成功（請求被 Http::fake 攔截）
        $keyPair = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $privateKeyPem = '';
        openssl_pkey_export($keyPair, $privateKeyPem);

        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => 'test_client_secret',
            'available_banks' => [
                ['BankCode_ELG' => 'FPX', 'BankCode_Third' => 'FPX'],
                ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB'],
            ],
            'CLIENT_PRIVATE_KEY' => $privateKeyPem,
            'CLIENT_SYMMETRIC_KEY' => self::SYMMETRIC_KEY,
            'SERVER_PUBLIC_KEY' => 'test_public_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 100,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->save();

        // 預先 seed access token 快取，跳過 fetchAccessToken 的 RSA/HTTP 流程
        Cache::put('kira_token_' . md5('test_client_id' . 'test_client_secret'), 'fake_token', 3600);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 以與 KiraTrait::aesEncrypt 相同邏輯加密回應內層（iv=md5(key)、AES-256-CBC、回 hex）
     */
    private function encryptResponse(array $inner): string
    {
        $iv = md5(self::SYMMETRIC_KEY, true);
        $encrypted = openssl_encrypt(json_encode($inner), 'aes-256-cbc', self::SYMMETRIC_KEY, OPENSSL_RAW_DATA, $iv);

        return bin2hex($encrypted);
    }

    /**
     * 建立通過 pre-HTTP 檢核（有效銀行、狀態 21）的提款訂單以進入 sendRequest
     */
    private function createPayableOrder(): WithdrawalOrder
    {
        $bank = Bank::where('code', 'FPX')->first() ?? Bank::create([
            'name' => 'FPX',
            'code' => 'FPX',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'FPX',
            'bank_name' => 'FPX',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    /**
     * 測試缺少必要配置參數（CLIENT_ID）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalTest.php --filter test_missing_client_id
     */
    public function test_missing_client_id()
    {
        // Arrange
        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_SECRET' => 'test_secret',
            'CLIENT_PRIVATE_KEY' => 'test_key',
            'CLIENT_SYMMETRIC_KEY' => 'test_symmetric',
            'SERVER_PUBLIC_KEY' => 'test_public_key',
            'available_banks' => [
                ['BankCode_ELG' => 'FPX', 'BankCode_Third' => 'FPX'],
            ],
        ];

        $agentCashFlow = new AgentCashFlow([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 100,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;
        $agentCashFlow->currency = $this->currency;

        $bank = Bank::where('code', 'FPX')->first() ?? Bank::create([
            'name' => 'FPX',
            'code' => 'FPX',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'FPX',
            'bank_name' => 'FPX',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $withdrawal = new Withdrawal($agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('AgentCashFlow missing parameter: CLIENT_ID', $result->errorMessage);
    }

    /**
     * 測試不支援的銀行代碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalTest.php --filter test_unsupported_bank_code
     */
    public function test_unsupported_bank_code()
    {
        // Arrange
        $bank = Bank::where('code', 'UNKNOWN')->first() ?? Bank::create([
            'name' => 'Unknown Bank',
            'code' => 'UNKNOWN',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'UNKNOWN',
            'bank_name' => 'Unknown Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 150.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Unsupported bank code', $result->errorMessage);
    }

    /**
     * 測試不存在的提款訂單
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/WithdrawalTest.php --filter test_non_existent_order
     */
    public function test_non_existent_order()
    {
        // Arrange
        $withdrawalRequestData = new WithdrawalRequestData; // 不存在的訂單

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertFalse($result->isSuccess);
        $this->assertNotNull($result->errorMessage);
    }

    /**
     * HTTP 路徑：受理（解密內層帶 transactionId）→ 記錄維持 PENDING
     */
    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'encryptedData' => $this->encryptResponse(['data' => ['transactionId' => 'KIRA-TX-1']]),
        ], 200)]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('KIRA-TX-1', $record->third_party_transaction_no);
        // 前綴釘住 orderPrefix：Kira 產生的交易號不得與薄繼承 provider（ANTZ*）串線
        $this->assertStringStartsWith('KIRA', $record->transaction_no);
    }

    /**
     * HTTP 路徑：code=200（三方已受理）但解密內層缺 transactionId＝結構異常，
     * 不能排除已進單 → REQUEST_FAILED(-1)、isIndeterminate=true（結果未知，訂單停留 21 待查證）
     */
    public function test_accepted_but_missing_transaction_id_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'encryptedData' => $this->encryptResponse(['data' => []]),
        ], 200)]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 路徑：code=200（三方已受理）但 encryptedData 解密失敗＝結果未知
     * → REQUEST_FAILED(-1)、isIndeterminate=true（不得判 -2 還原重送）
     */
    public function test_accepted_but_undecryptable_payload_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'encryptedData' => 'zzzz-not-valid-hex',
        ], 200)]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 路徑：HTTP 200 但 body 非 JSON（WAF / CDN 挑戰頁）＝結構缺失，
     * 請求已送達、無依據斷定未受理 → REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_unparseable_2xx_response_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response('<html>challenge</html>', 200)]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('unparseable 2xx response', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 路徑：HTTP 200 但 code 為 null（非數值業務碼）＝結構異常，
     * 無依據斷定未建單 → REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_non_numeric_code_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['code' => null, 'message' => 'malformed'], 200)]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('unparseable 2xx response', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 路徑：業務碼拒絕（HTTP 200、code=0，實測 2184127 形狀）＝同步明確失敗 →
     * THIRD_PARTY_REJECTED(-2)、isIndeterminate=false（訂單轉 29 可重新發起，不得誤判為結果未知）
     */
    public function test_business_rejection_marks_third_party_rejected_not_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 0,
            'message' => 'Daily withdrawal limit reached',
        ], 200)]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertFalse($response->isIndeterminate);
        $this->assertStringContainsString('Daily withdrawal limit reached', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * HTTP 路徑：業務碼只要不是 200 一律算明確拒絕（KIRAPAY 書面確認不會出款，target/tickets#4393）→
     * THIRD_PARTY_REJECTED(-2)、isIndeterminate=false（訂單轉 29 可重新發起）。
     * 取正式環境實際出現過、原本被判結果未知而讓訂單停在 21 的三個碼。
     */
    public function test_any_non_200_business_code_marks_third_party_rejected(): void
    {
        $cases = [
            3001 => 'Payment Gateway Error',
            1200 => 'Payment Gateway Status OFF',
            4001 => 'Merchant Payout Pool Insufficent Balance',
        ];

        // 同一 '*' pattern 重複 Http::fake 會讓首輪 stub 常駐，改以 sequence 逐輪出貨
        $sequence = Http::sequence();
        foreach ($cases as $code => $message) {
            $sequence->push(['code' => $code, 'message' => $message], 200);
        }
        Http::fake(['*' => $sequence]);

        foreach ($cases as $code => $message) {
            $order = $this->createPayableOrder();

            $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

            $this->assertFalse($response->isSuccess, "code {$code}");
            $this->assertFalse($response->isIndeterminate, "code {$code}");
            $this->assertStringContainsString($message, $response->errorMessage, "code {$code}");
            $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
            $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout, "code {$code}");
        }
    }

    /**
     * HTTP 路徑：code=400（文檔寫它含「訂單號重複」）同樣算明確拒絕 → THIRD_PARTY_REJECTED(-2)。
     * 每次送出都用新交易號、HTTP 請求不自動重試，回「重複」也不代表這次請求已在 KIRA 建單。
     */
    public function test_code_400_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 400,
            'message' => 'order no duplicated',
        ], 200)]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertFalse($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * HTTP 路徑：非 2xx HTTP 錯誤一律結果未知 → REQUEST_FAILED(-1)、isIndeterminate=true。
     * 代付只依 KIRA 回應的業務碼判定明確拒絕，HTTP 狀態碼不判拒絕：Cloudflare 的 520、522 也一樣，
     * body 帶了非 200 業務碼的 HTTP 400 也一樣（業務碼只在 HTTP 2xx 時採信）。
     */
    public function test_http_error_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 502)
            ->push('<html>cloudflare</html>', 520)
            ->push('<html>cloudflare</html>', 522)
            ->push('', 524)
            ->push(['code' => 3001, 'message' => 'Payment Gateway Error'], 400)]);

        foreach ([502, 520, 522, 524, 400] as $httpStatus) {
            $order = $this->createPayableOrder();

            $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

            $this->assertFalse($response->isSuccess, "HTTP {$httpStatus}");
            $this->assertTrue($response->isIndeterminate, "HTTP {$httpStatus}");
            $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
            $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout, "HTTP {$httpStatus}");
        }
    }

    /**
     * 守衛：明確拒絕的判定是各金流商自己的政策。沿用 Kira 實作的金流商，實際用到的代付類別都必須自行實作
     * isRejectedBusinessCode()；默默沿用 Kira 的「業務碼非 200 全拒絕」，沒有書面依據的金流商就可能重複出款。
     *
     * 兩種沿用方式都要擋：config('payment.provider') 直接登記 Kira\Driver，
     * 或 Driver 繼承 Kira\Driver 卻沒有換掉代付類別。
     */
    public function test_every_kira_thin_shell_declares_own_reject_policy(): void
    {
        $drivers = collect(config('payment.provider'));

        $this->assertSame(
            ['KIRA'],
            $drivers->filter(fn (string $driver): bool => $driver === KiraDriver::class)->keys()->all(),
            '其他金流商要沿用 Kira 實作，必須建立自己的薄繼承殼，不得直接登記 Kira\Driver'
        );

        $thinShellDrivers = $drivers
            ->filter(fn (string $driver): bool => is_subclass_of($driver, KiraDriver::class))
            ->values();

        $this->assertNotEmpty($thinShellDrivers, '至少應找到 AntzPay 這個沿用 Kira 實作的金流商');

        foreach ($thinShellDrivers as $driver) {
            // 取 Driver 實際建構出來的代付類別：沒換掉的話就是 Kira\Withdrawal 本身
            $withdrawal = (new \ReflectionProperty($driver, 'withdrawal'))->getValue(new $driver($this->agentCashFlow))::class;

            $this->assertNotSame(Withdrawal::class, $withdrawal, "{$driver} 必須建構自己的代付類別，不得直接使用 Kira\\Withdrawal");
            $this->assertSame(
                $withdrawal,
                (new \ReflectionMethod($withdrawal, 'isRejectedBusinessCode'))->getDeclaringClass()->getName(),
                "{$withdrawal} 必須自行實作 isRejectedBusinessCode()（金流商政策不得沿用 Kira）"
            );
        }
    }

    /**
     * HTTP 路徑：連線例外（結果未知）→ REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_connection_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 前置路徑：access token 取得失敗＝請求未發出 → 直接失敗回應、
     * isIndeterminate=false、不建 payout 記錄（訂單維持原狀態可重送）
     */
    public function test_token_fetch_failure_returns_failure_without_record(): void
    {
        Cache::forget('kira_token_' . md5('test_client_id' . 'test_client_secret'));
        Http::fake([
            '*/gateway/v1/createToken' => Http::response([], 500),
            '*' => Http::response([], 500),
        ]);

        $order = $this->createPayableOrder();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

        $this->assertFalse($response->isSuccess);
        $this->assertFalse($response->isIndeterminate);
        $this->assertSame(0, WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->count());
    }
}
