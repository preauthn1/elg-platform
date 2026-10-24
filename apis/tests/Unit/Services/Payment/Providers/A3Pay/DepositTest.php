<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\A3Pay\Deposit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A3Pay 代收請求測試：解開送出的 Data 逐欄驗 Submit Payin 欄位（MID／PayinAmount／PaymentMethodId…），
 * 並驗回應鍵（paymentOrderId／paymentURL／paymentQRCode）的解讀。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/A3Pay/DepositTest.php
 */
class DepositTest extends TestCase
{
    use A3PayCryptoHelpers;

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->generateRsaKeyPair();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1, 'code' => 'SGD', 'name' => 'Singapore Dollar', 'rate' => 1, 'status' => 1, 'admin' => 0,
        ]));

        $info = $this->buildInfo();

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => 1,
            'cash_flow_id' => 131,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
            'first_name' => 'Tan',
            'last_name' => 'Ah Kow',
        ]);

        $orderType = OrderType::create([
            'cash_flow_id' => 131, 'parent' => 0, 'name' => 'Online banking', 'key' => 'OB', 'type' => 1,
            'currency_id' => 1, 'info' => '{}', 'choose_bank' => 0, 'status' => 1, 'admin' => 0,
        ]);

        $this->order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'A3D' . time(),
            'status' => 0,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    private function request(): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $this->order,
            'callbackUrl' => 'https://callback.test/notify',
            'returnUrl' => 'https://return.test/back',
            'ip' => '0.0.0.0',
        ]);
    }

    public function test_success_redirects_to_payment_url_and_sends_new_field_names(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true, 'successCode' => 200, 'errorMessage' => '', 'errorList' => null,
            'data' => [
                'paymentOrderId' => 'D2026092200000001',
                'paymentURL' => 'https://cashier.a3pay.test/Deposit/DepositCashier/?a=abc',
                'paymentQRCode' => null,
                'payinActualAmount' => 100.00,
            ],
        ], 200)]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->request());

        $this->assertSame(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertSame('https://cashier.a3pay.test/Deposit/DepositCashier/?a=abc', $result->url);
        $this->assertSame('D2026092200000001', $result->providerOrderNo);
        $this->assertSame('D2026092200000001', $this->order->refresh()->third_party_order_no);

        Http::assertSent(function (Request $request): bool {
            $body = $request->data();
            $this->assertSame('SGD00001', $body['merchantCode']);
            $this->assertSame(0, $body['version']);

            $data = json_decode($this->aesDecrypt($body['data']), true);
            $this->assertSame([
                'MID' => 'SGD00001',
                'MTransactionId' => $this->order->order_no,
                'MCustId' => $this->member->username,
                'PayinAmount' => '100.00',
                'CurrCode' => 'SGD',
                'RequestIPAddress' => '0.0.0.0',
                'CallbackURL' => 'https://callback.test/notify',
                'ReturnClientURL' => 'https://return.test/back',
                'PaymentMethodId' => 'OB',
                'FromCustomerAccountHolderName' => 'Tan Ah Kow',
            ], $data);

            // 簽名對 JSON 明文簽、以商戶公鑰可驗
            $this->assertSame(1, openssl_verify(json_encode($data), base64_decode($body['signature']), $this->publicKeyPem, OPENSSL_ALGO_SHA1));

            return str_ends_with($request->url(), '/api/Payin/CreatePayin');
        });
    }

    /** BQR 同時給收銀台網址與 QR 圖時導收銀台（前台不處理 QR 型別，2026-09-24 dev 實測畫面沒反應） */
    public function test_payment_url_is_preferred_over_qr_code(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true,
            'data' => [
                'paymentOrderId' => 'D2026092200000002',
                'paymentURL' => 'https://cashier.a3pay.test/Deposit/SGQRDeposit/?a=x',
                'paymentQRCode' => 'data:image/png;base64,QUJD',
            ],
        ], 200)]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->request());

        $this->assertSame(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertSame('https://cashier.a3pay.test/Deposit/SGQRDeposit/?a=x', $result->url);
    }

    public function test_qr_only_response_falls_back_to_qr_image_type(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true,
            'data' => ['paymentOrderId' => 'D2026092200000004', 'paymentURL' => '', 'paymentQRCode' => 'data:image/png;base64,QUJD'],
        ], 200)]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->request());

        $this->assertSame(DepositResponseType::QR_CODE_IMAGE_PATH, $result->type);
        $this->assertSame('data:image/png;base64,QUJD', $result->url);
    }

    public function test_rejected_response_throws_with_error_list_description(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => false, 'successCode' => 400, 'errorMessage' => '',
            'errorList' => [['ErrorCode' => 'E1001', 'ErrorDescription' => 'FromCustomerAccountHolderName is required']],
            'data' => null,
        ], 400)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('E1001: FromCustomerAccountHolderName is required');

        (new Deposit($this->agentCashFlow))->handle($this->request());
    }

    /**
     * 閘道層擋掉（出口 IP 不在白名單）時回的是 IIS ProblemDetails，沒有業務欄位，
     * 錯誤訊息要看得出是 403 而不是 'Unknown error'（2026-09-30 VWSG 實例）。
     */
    public function test_gateway_problem_details_throws_with_title_and_status(): void
    {
        Http::fake(['*' => Http::response([
            'type' => 'https://example.invalid/html/rfc7231#section-6.5.3',
            'title' => 'Forbidden',
            'status' => 403,
            'traceId' => '00-2452aaebe152d212c018222d90692b89-c1d04759137d5f4d-00',
        ], 403)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('A3Pay deposit error: Forbidden (403)');

        (new Deposit($this->agentCashFlow))->handle($this->request());
    }

    /**
     * 非 JSON body（如 `Not found`）不轉成假信封交給 describeError（會被代付判成明確拒絕），
     * 在 sendRequest 就拋掉，但訊息一樣帶得出狀態碼。
     */
    public function test_non_json_body_throws_unparseable_with_status(): void
    {
        Http::fake(['*' => Http::response('Not found', 404)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('A3Pay unparseable response from /api/Payin/CreatePayin (404)');

        (new Deposit($this->agentCashFlow))->handle($this->request());
    }

    public function test_success_without_payment_url_or_qr_throws(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true,
            'data' => ['paymentOrderId' => 'D2026092200000003', 'paymentURL' => '', 'paymentQRCode' => null],
        ], 200)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('missing paymentURL and paymentQRCode');

        (new Deposit($this->agentCashFlow))->handle($this->request());
    }

    public function test_connection_timeout_throws_api_exception(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28: timed out')]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('request failed');

        (new Deposit($this->agentCashFlow))->handle($this->request());
    }

    /** 2026-09-24 實測：三方回應用舊鍵名 transactionId／url／qrCode，不是文件的 paymentOrderId／paymentURL／paymentQRCode */
    public function test_legacy_response_keys_are_accepted(): void
    {
        Http::fake(['*' => Http::response([
            'isSuccess' => true, 'successCode' => 200, 'errorCode' => null, 'errorMessage' => '', 'errorList' => null,
            'data' => [
                'transactionId' => 'D2026092400000009',
                'url' => 'https://cashier.a3pay.test/Deposit/DepositCashier/?a=legacy',
                'qrCode' => null, 'depositActualAmount' => 100.01, 'referenceNo' => null,
            ],
        ], 200)]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->request());

        $this->assertSame(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertSame('https://cashier.a3pay.test/Deposit/DepositCashier/?a=legacy', $result->url);
        $this->assertSame('D2026092400000009', $result->providerOrderNo);
        $this->assertSame('D2026092400000009', $this->order->refresh()->third_party_order_no);
    }

    /** 真實成功回應（dev 訂單 13913，BQR，payinActualAmount 10.01）：導收銀台、記三方單號 */
    public function test_real_success_response_redirects_to_cashier(): void
    {
        Http::fake(['*' => Http::response(A3PayRealPayloads::PAYIN_SUCCESS_BQR_ADJUSTED, 200)]);

        $result = (new Deposit($this->agentCashFlow))->handle($this->request());

        $this->assertSame(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertSame(A3PayRealPayloads::PAYIN_SUCCESS_BQR_ADJUSTED['data']['paymentURL'], $result->url);
        $this->assertSame('D2026092400000355', $result->providerOrderNo);
        $this->assertSame('D2026092400000355', $this->order->refresh()->third_party_order_no);
    }

    /** 真實失敗回應（dev 訂單 13904，HTTP 400、舊鍵名 data）：拋出帶廠商訊息的 ApiException */
    public function test_real_pmt10005_failure_throws_with_vendor_message(): void
    {
        Http::fake(['*' => Http::response(A3PayRealPayloads::PAYIN_FAIL_PMT10005_LEGACY_KEYS, 400)]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid Transaction Amount.');

        (new Deposit($this->agentCashFlow))->handle($this->request());
    }
}
