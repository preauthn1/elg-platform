<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\B3Wallet;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use App\Services\Payment\Providers\B3Wallet\Deposit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B3 Wallet 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/B3Wallet/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::firstOrCreate(['code' => 'SGD'], [
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $info = [
            'API_URL' => 'https://www.judy88.xin',
            'PAYMENT_KEY' => 'test_payment_key',
            'PAYMENT_PASS' => 'test_payment_pass',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'name' => 'B3 Wallet',
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * @param  string  $channelKey  支付通道 key，即送三方的 DepositInstruction（鏈上收款帶 `.鏈別` 後綴）
     */
    private function createOrder(string $channelKey = 'PAYNOW'): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => $this->currency->id, 'key' => $channelKey],
            [
                'name' => 'B3Wallet ' . $channelKey,
                'status' => 1,
                'info' => '{}',
                'admin' => 0,
            ]
        );

        return Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 120.5,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'B3W' . uniqid(),
            'status' => 0,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);
    }

    /**
     * 測試正常下單，回傳跳轉支付頁 URL
     *
     * 響應取自 TPower 2026-10-22 QA 實測（訂單 202610223976208113，PayNow 通道）：
     * - HTTP 201 而非 200
     * - 送 `DepositInstruction=PAYNOW`，回的是 `QR`，不可拿響應的值回推通道
     * - BillUrl 的 token 放在 `#` 之後，整串原樣轉給前端
     */
    public function test_success_returns_redirect_url(): void
    {
        $order = $this->createOrder();

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        $billUrl = 'https://example.invalid/bills?id=b300713d-e1e5-4b79-9db0-41dc47c373c3'
            . '#token=[REDACTED]';

        Http::fake([
            '*' => Http::response([
                'Amount' => 10,
                'BillAccessToken' => '5f5aa6784f660b54fb0979756040e3028771674985faa3d43364a8e282b8f08c',
                'BillId' => 'b300713d-e1e5-4b79-9db0-41dc47c373c3',
                'BillUrl' => $billUrl,
                'BuyerId' => null,
                'Currency' => 'SGD',
                'Date' => '2026-10-22T02:02:27.840Z',
                'DepositInstruction' => 'QR',
                'ExpiresAt' => '2026-10-22T02:07:27.829Z',
                'FeeAmount' => 0.26,
                'FeeRate' => 0.026,
                'NetAmount' => 9.74,
                'Paid' => false,
                'Param' => $order->order_no,
                'PayableAmount' => 10,
                'ProductName' => 'Deposit',
                'RequestedAmount' => 10,
                'ShopName' => '(SGD) B3 Wallet - Paynow',
            ], 201),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $result = $deposit->handle($depositRequestData);

        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertSame($billUrl, $result->url);
        $this->assertSame($order->order_no, $result->orderNo);
        $this->assertSame('b300713d-e1e5-4b79-9db0-41dc47c373c3', $result->providerOrderNo);

        $order->refresh();
        $this->assertSame('b300713d-e1e5-4b79-9db0-41dc47c373c3', $order->third_party_order_no);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Authorization', 'test_payment_key:test_payment_pass')
                && $request['DepositInstruction'] === 'PAYNOW';
        });
    }

    /**
     * 測試鏈上收款通道：key 的 `.` 後綴拆成 Chain，其餘方式不帶該參數
     */
    public function test_crypto_channel_sends_instruction_with_chain(): void
    {
        $order = $this->createOrder('CRYPTO.TRON');

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            '*' => Http::response([
                'BillId' => '550e8400-e29b-41d4-a716-446655440001',
                'BillUrl' => 'https://www.judy88.xin/pay/550e8401#token=[REDACTED]',
                'Paid' => false,
                'Param' => $order->order_no,
                'ProductName' => 'Deposit',
                'ShopName' => 'B3 Wallet',
                'Amount' => 120.5,
                'Currency' => 'USDT',
                'ExpiresAt' => now()->addHour()->toIso8601String(),
                'BillAccessToken' => 'abc124',
                'DepositInstruction' => 'CRYPTO',
                'CryptoChain' => 'TRON',
                'CryptoAddress' => 'TXYZ...',
            ], 200),
        ]);

        (new Deposit($this->agentCashFlow))->handle($depositRequestData);

        Http::assertSent(function ($request) {
            return $request['DepositInstruction'] === 'CRYPTO'
                && $request['Chain'] === 'TRON';
        });
    }

    /**
     * 測試非鏈上通道不帶 Chain（三方對非 CRYPTO 方式傳 Chain 會以 400 擋下）
     *
     * 響應取自 TPower 2026-10-22 QA 實測（訂單 202610228100137983，Online Banking 通道）：
     * 銀行轉帳的 BillUrl 是 example.invalid 短網址、多帶 ProviderResponse（下游 PAYLOH），
     * 三方費率 0.031，與 PayNow 的 0.026 不同。
     */
    public function test_non_crypto_channel_omits_chain(): void
    {
        $order = $this->createOrder('BANK_TRANSFER');

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            '*' => Http::response([
                'Amount' => 10,
                'BillAccessToken' => '5b60fbcab3738bb12bb2eabe4f9de0754eaaeff12340ea74d98c1bd6e1349698',
                'BillId' => '9127f2ea-a1b2-4f09-856c-e799997c380f',
                'BillUrl' => 'https://example.invalid/7475CE82BAA7ACFC4CBB34DDDC9A77A6',
                'BuyerId' => null,
                'Currency' => 'SGD',
                'Date' => '2026-10-22T02:15:37.445Z',
                'DepositInstruction' => 'BANK_TRANSFER',
                'ExpiresAt' => '2026-10-22T02:20:36.229Z',
                'FeeAmount' => 0.31,
                'FeeRate' => 0.031,
                'NetAmount' => 9.69,
                'Paid' => false,
                'Param' => $order->order_no,
                'PayableAmount' => 10,
                'ProductName' => 'Deposit',
                'ProviderResponse' => [
                    'attemptId' => '112d2b06-7ffd-4320-ae1d-3f8c63f0d384',
                    'diagnostic' => 'HTTP 200; JSON; 1186ms',
                    'httpStatus' => 200,
                    'localReference' => '9127f2ea-a1b2-4f09-856c-e799997c380f',
                    'name' => 'PAYLOH',
                    'operation' => 'create_deposit',
                    'outcome' => 'SUCCEEDED',
                    'retryable' => false,
                    'status' => true,
                ],
                'RequestedAmount' => 10,
                'ShopName' => '(SGD) B3 Wallet - Online Banking',
            ], 201),
        ]);

        $result = (new Deposit($this->agentCashFlow))->handle($depositRequestData);

        $this->assertSame('https://example.invalid/7475CE82BAA7ACFC4CBB34DDDC9A77A6', $result->url);

        Http::assertSent(function ($request) {
            return $request['DepositInstruction'] === 'BANK_TRANSFER'
                && !isset($request['Chain']);
        });
    }

    /**
     * 測試第三方回傳錯誤（非 2xx），拋出 ApiException
     */
    public function test_third_party_error_throws_api_exception(): void
    {
        $order = $this->createOrder();

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            '*' => Http::response(['error' => 'AMOUNT_BELOW_MIN'], 422),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試網路逾時，拋出 ApiException
     */
    public function test_network_timeout_throws_api_exception(): void
    {
        $order = $this->createOrder();

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);

        Http::fake([
            '*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $deposit->handle($depositRequestData);
    }

    /**
     * 建單成功寫一筆往返紀錄；不記 request header（Authorization 是商戶帳密原文，這張表不遮罩）。
     */
    public function test_success_writes_order_request_log_without_headers(): void
    {
        $order = $this->createOrder();

        Http::fake(['*' => Http::response(['BillId' => 'bill-log-1', 'BillUrl' => 'https://example.invalid/bills?id=bill-log-1'], 201)]);

        (new Deposit($this->agentCashFlow))->handle($this->depositRequest($order));

        $log = OrderRequestLog::where('order_no', $order->order_no)->sole();
        $this->assertSame(OrderRequestActionEnum::DEPOSIT_CREATE->value, $log->action);
        $this->assertSame(OrderRequestResultEnum::OK->value, $log->result);
        $this->assertSame('bill-log-1', $log->third_party_order_no);
        $this->assertNull($log->request_headers);
        $this->assertStringNotContainsString('test_payment_pass', (string) $log->request_body);
    }

    /**
     * 三方 4xx 拒絕時，result 記三方的錯誤碼而不是訊息。
     *
     * 響應取自 TPower 2026-10-22 實測（代付建單同一套錯誤格式）：error.code + error.message。
     */
    public function test_rejection_logs_provider_error_code(): void
    {
        $order = $this->createOrder();

        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 'NO_AVAILABLE_PAYMENT_ACCOUNT',
                    'message' => 'Payout group does not offer this currency/recipient type',
                ],
            ], 422),
        ]);

        try {
            (new Deposit($this->agentCashFlow))->handle($this->depositRequest($order));
            $this->fail('expected ApiException');
        } catch (ApiException) {
        }

        $log = OrderRequestLog::where('order_no', $order->order_no)->sole();
        $this->assertSame(OrderRequestResultEnum::providerFailure('NO_AVAILABLE_PAYMENT_ACCOUNT'), $log->result);
        $this->assertSame(422, $log->http_status);
    }

    private function depositRequest(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]);
    }
}
