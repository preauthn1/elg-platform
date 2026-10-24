<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AntzPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\AntzPay\Withdrawal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AntzPay 代付請求測試
 *
 * AntzPay 請求側 AES-256-CBC 加密 + RSA-SHA256 簽名 + 預取 access token；回應側 encryptedData
 * 需以 symmetric key 解密。測試以：①setUp 動態 RSA key 滿足簽名 ②預先 seed token 快取跳過
 * fetchAccessToken 的 HTTP/RSA ③encryptResponse() 以與 KiraTrait::aesEncrypt 相同邏輯
 * （iv=md5(key)、回 hex；AntzPay 薄繼承 Kira 後由 KiraTrait 提供）構造回應 encryptedData。
 *
 * 例外分類原則：只有「結構完整且業務碼在白名單（0／401）」才 -2、訂單轉 29 可重新發起；
 * 其他業務碼／結構缺失／code=200 後的解密失敗或缺欄位／HTTP 錯誤／連線例外一律 -1（結果未知，訂單停留 21）。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AntzPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private const SYMMETRIC_KEY = '12345678901234567890123456789012'; // 32 bytes（AES-256）

    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $currency = Currency::findOr(1, fn () => Currency::forceCreate([
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
            'CLIENT_SECRET' => 'test_secret',
            'CLIENT_PRIVATE_KEY' => $privateKeyPem,
            'CLIENT_SYMMETRIC_KEY' => self::SYMMETRIC_KEY,
            // 僅需非空通過 getAccessToken 前置檢查；token 由下方 cache 提供，fetchAccessToken 不被呼叫
            'SERVER_PUBLIC_KEY' => 'unused_in_test',
            'available_banks' => [
                ['BankCode_ELG' => 'MBB', 'BankCode_Third' => 'KASITHBK'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => $currency->id,
        ]);
        $this->agentCashFlow->info = $info;

        // 預先 seed access token 快取，跳過 fetchAccessToken 的 RSA/HTTP 流程
        Cache::put('antzpay_token_' . md5('test_client_id' . 'test_secret'), 'fake_token', 3600);

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
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
     * 建立通過 pre-HTTP 檢核（有效銀行、狀態 21）的提款訂單，供同一測試內多輪請求各自建單
     */
    private function createPayableOrder(): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $this->order->agent_id,
            'member_id' => $this->order->member_id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'encryptedData' => $this->encryptResponse(['data' => ['transactionId' => 'ANTZ-TX-1']]),
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('ANTZ-TX-1', $record->third_party_transaction_no);
        // 前綴釘住 orderPrefix 覆寫：AntzPay 產生的交易號不得與 Kira（KIRA*）串線
        $this->assertStringStartsWith('ANTZ', $record->transaction_no);
    }

    /**
     * code=200（三方已受理）但解密內層缺 transactionId＝結構異常，
     * 不能排除已進單 → REQUEST_FAILED(-1)、isIndeterminate=true（結果未知，訂單停留 21 待查證）
     */
    public function test_accepted_but_missing_transaction_id_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'encryptedData' => $this->encryptResponse(['data' => []]),
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * code=200（三方已受理）但 encryptedData 解密失敗＝結果未知
     * → REQUEST_FAILED(-1)、isIndeterminate=true（不得判 -2 還原重送）
     */
    public function test_accepted_but_undecryptable_payload_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 200,
            'encryptedData' => 'zzzz-not-valid-hex',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 200 但 body 非 JSON（WAF / CDN 挑戰頁）＝結構缺失，
     * 請求已送達、無依據斷定未受理 → REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_unparseable_2xx_response_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response('<html>challenge</html>', 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('unparseable 2xx response', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * HTTP 200 但 code 為 null（非數值業務碼）＝結構異常，
     * 無依據斷定未建單 → REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_non_numeric_code_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response(['code' => null, 'message' => 'malformed'], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('unparseable 2xx response', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 白名單業務碼拒絕（HTTP 200、code=0，比照 Kira 實測形狀）＝同步明確失敗 →
     * THIRD_PARTY_REJECTED(-2)、isIndeterminate=false（訂單轉 29 可重新發起，不得誤判為結果未知）
     */
    public function test_business_rejection_marks_third_party_rejected_not_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 0,
            'message' => 'Daily withdrawal limit reached',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertFalse($response->isIndeterminate);
        $this->assertStringContainsString('Daily withdrawal limit reached', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * code=400 不在 AntzPay 白名單（沒有依據證明不會出款）＝結果未知 →
     * REQUEST_FAILED(-1)、isIndeterminate=true（結果未知，訂單停留 21，不得開放重送）
     */
    public function test_code_400_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 400,
            'message' => 'order no duplicated',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('ambiguous business code 400', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 白名單外未知業務碼（不能排除已建單）＝結果未知 →
     * REQUEST_FAILED(-1)、isIndeterminate=true（結果未知，訂單停留 21）
     */
    public function test_unknown_business_code_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response([
            'code' => 4001,
            'message' => 'Insufficient balance',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $this->assertStringContainsString('ambiguous business code 4001', $response->errorMessage);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * Kira 的「業務碼非 200 一律拒絕」只有 KIRAPAY 書面確認（target/tickets#4393），AntzPay 未取得同等依據 →
     * 白名單外的碼必須維持 REQUEST_FAILED(-1)、isIndeterminate=true（結果未知，訂單停留 21），
     * 不得比照 Kira 判 -2 開放重新發起（若三方實際已建單，重送即重複出款）。
     * 與 Kira 的 test_any_non_200_business_code_marks_third_party_rejected 互為反向對照。
     */
    public function test_kira_only_business_code_rejections_stay_indeterminate_for_antzpay(): void
    {
        // 同一 '*' pattern 重複 Http::fake 會讓首輪 stub 常駐，改以 sequence 逐輪出貨
        Http::fake(['*' => Http::sequence()
            ->push(['code' => 3001, 'message' => 'Payment Gateway Error'], 200)
            ->push(['code' => 520, 'message' => 'Withdrawal failed'], 200)]);

        foreach ([3001, 520] as $code) {
            $order = $this->createPayableOrder();

            $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $order));

            $this->assertFalse($response->isSuccess, "code {$code}");
            $this->assertTrue($response->isIndeterminate, "code {$code}");
            $this->assertStringContainsString("ambiguous business code {$code}", $response->errorMessage);
            $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $order->id)->firstOrFail();
            $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout, "code {$code}");
        }
    }

    /**
     * 守衛：明確拒絕的判定屬各金流商政策（需該金流商書面／實測依據），同源薄繼承 provider
     * 必須自行實作業務碼判定，不得靜默沿用 Kira 的政策（業務碼非 200 全拒絕）——沿用即資損風險
     */
    public function test_thin_shell_must_declare_own_reject_policy(): void
    {
        $methodOwner = (new \ReflectionMethod(Withdrawal::class, 'isRejectedBusinessCode'))
            ->getDeclaringClass()
            ->getName();

        $this->assertSame(
            Withdrawal::class,
            $methodOwner,
            'AntzPay\Withdrawal 必須自行實作 isRejectedBusinessCode()（金流商政策不得沿用 Kira）'
        );
    }

    /**
     * 非 2xx HTTP 錯誤（結果未知）→ REQUEST_FAILED(-1)、isIndeterminate=true
     */
    public function test_http_error_marks_request_failed_indeterminate(): void
    {
        Http::fake(['*' => Http::response('', 502)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    public function test_connection_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertTrue($response->isIndeterminate);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 前置路徑：access token 取得失敗＝請求未發出 → 直接失敗回應、
     * isIndeterminate=false、不建 payout 記錄（訂單維持原狀態可重送）
     */
    public function test_token_fetch_failure_returns_failure_without_record(): void
    {
        Cache::forget('antzpay_token_' . md5('test_client_id' . 'test_secret'));
        Http::fake([
            '*/gateway/v1/createToken' => Http::response([], 500),
            '*' => Http::response([], 500),
        ]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertFalse($response->isIndeterminate);
        $this->assertSame(0, WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->count());
    }
}
