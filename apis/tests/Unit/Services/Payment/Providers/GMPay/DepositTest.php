<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GMPay;

use App\Enums\DepositResponseType;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\GMPay\Deposit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GMPay 代收發起測試（standalone Deposit，對照 GMPay V1.5 api.md）。
 *
 * 發起簽名格式：SHA256(ref_no + number_format(amount,2) + currency)。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GMPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $secretKey = 'gmpay_test_secret';

    private array $info = [
        'API_URL' => 'https://api.gmpaysg.test',
        'API_KEY' => 'gmpay_test_api_key',
        'SECRET_KEY' => 'gmpay_test_secret',
        'CURRENCY' => 'SGD',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(): AgentCashFlow
    {
        $cashFlow = new AgentCashFlow([
            'cash_flow_id' => 73,
            'cash_flow_info_id' => 1,
            'rate' => 3.146,
            'info' => json_encode($this->info),
            'status' => 1,
            'admin' => 0,
        ]);
        $cashFlow->info = $this->info;

        return $cashFlow;
    }

    private function makeOrderType(string $key): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => 73,
            'currency_id' => 1,
            'name' => 'GMPay Deposit Test ' . $key,
            'type' => 1,
            'status' => 1,
            'key' => $key,
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeOrder(OrderType $orderType, string $orderNo, string $amount = '100.00'): Order
    {
        return Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'amount' => $amount,
            'rate' => 3.146,
            'point' => $amount,
            'balance' => 0,
            'remark' => '',
            'order_no' => $orderNo,
            'status' => 1,
            'third_party_order_no' => '',
            'admin' => 0,
        ]);
    }

    private function makeRequestData(Order $order): DepositRequestData
    {
        return DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test/gmpay',
            'returnUrl' => 'https://return.test/gmpay',
            'ip' => '0.0.0.0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function successResponse(array $data = []): array
    {
        return [
            'status' => true,
            'data' => array_merge([
                'transaction_reference' => 'GMP_TR_001',
                'ref_no' => 'GMPTEST_001',
                'currency' => 'SGD',
                'payment_method' => 'PayNow',
                'amount' => '100.00',
                'payment_link' => 'https://pay.gmpaysg.test/checkout',
                'is_sandbox' => true,
            ], $data),
        ];
    }

    /**
     * 成功：status===true → URL_REDIRECT（url=payment_link、providerOrderNo=transaction_reference），
     * 並把 transaction_reference 寫入 orders.third_party_order_no。
     */
    public function test_successful_deposit_returns_url_redirect_and_stores_third_party_no(): void
    {
        $orderType = $this->makeOrderType('PayNow');
        $order = $this->makeOrder($orderType, 'GMPTEST_001');

        Http::fake([
            'https://api.gmpaysg.test/*' => Http::response($this->successResponse(), 200),
        ]);

        $result = (new Deposit($this->makeAgentCashFlow()))->handle($this->makeRequestData($order));

        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertSame(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertSame('https://pay.gmpaysg.test/checkout', $result->url);
        $this->assertSame($order->order_no, $result->orderNo);
        $this->assertSame('GMP_TR_001', $result->providerOrderNo);

        $order->refresh();
        $this->assertSame('GMP_TR_001', $order->third_party_order_no);
    }

    /**
     * 建參數與 hash 基準：assertSent 驗證送往 /api/create_deposit 的欄位，
     * 且 hash = SHA256(ref_no + number_format(amount,2) + currency)。
     */
    public function test_deposit_sends_expected_params_and_hash(): void
    {
        $orderType = $this->makeOrderType('PayNow');
        $order = $this->makeOrder($orderType, 'GMPTEST_002', amount: '100.00');

        Http::fake([
            'https://api.gmpaysg.test/*' => Http::response($this->successResponse(['ref_no' => 'GMPTEST_002']), 200),
        ]);

        (new Deposit($this->makeAgentCashFlow()))->handle($this->makeRequestData($order));

        $expectedHash = hash_hmac(
            'sha256',
            'GMPTEST_002' . number_format(100.00, 2, '.', '') . 'SGD',
            $this->secretKey
        );

        Http::assertSent(function (HttpRequest $request) use ($order, $expectedHash) {
            $data = $request->data();

            return $request->url() === 'https://api.gmpaysg.test/api/create_deposit'
                && $data['api_key'] === 'gmpay_test_api_key'
                && $data['ref_no'] === 'GMPTEST_002'
                && (string) $data['amount'] === '100.00'
                && (string) $data['payment_method_id'] === 'PayNow'
                && $data['currency'] === 'SGD'
                && $data['return_url'] === 'https://return.test/gmpay'
                && $data['callback_url'] === 'https://callback.test/gmpay'
                && (string) $data['player_id'] === (string) $order->member_id
                && $data['hash'] === $expectedHash;
        });
    }

    /**
     * 失敗：status !== true → 拋 ApiException，帶三方 message。
     */
    public function test_deposit_failure_throws_api_exception(): void
    {
        $orderType = $this->makeOrderType('PayNow');
        $order = $this->makeOrder($orderType, 'GMPTEST_003');

        Http::fake([
            'https://api.gmpaysg.test/*' => Http::response([
                'status' => false,
                'message' => 'Ref no already existed.',
            ], 200),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Ref no already existed.');

        (new Deposit($this->makeAgentCashFlow()))->handle($this->makeRequestData($order));
    }
}
