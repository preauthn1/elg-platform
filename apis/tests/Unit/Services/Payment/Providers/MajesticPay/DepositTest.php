<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\MajesticPay;

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
use App\Services\Payment\Providers\MajesticPay\Deposit;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * MajesticPay 代收發起 characterization 測試（send 端）。
 *
 * 在全面拆分（完全複製、零繼承）前，鎖定 MajesticPay/Deposit 現有 handle() 行為：
 * 建參數欄位、hash 拼接（ref_no + number_format(amount,2) + currency）、成功判定（status===true）、
 * 回應解析（payment_link / transaction_reference）與失敗拋 ApiException。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/MajesticPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $secretKey = 'majesticpay_test_secret';

    private array $info = [
        'API_URL' => 'https://api.majesticpay.test',
        'API_KEY' => 'maj_test_api_key',
        'SECRET_KEY' => 'majesticpay_test_secret',
        'CURRENCY' => 'MYR',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
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
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
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
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'MajesticPay Deposit Test ' . $key,
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
            'rate' => 1,
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
            'callbackUrl' => 'https://callback.test/majesticpay',
            'returnUrl' => 'https://return.test/majesticpay',
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
                'transaction_reference' => 'MAJ_TR_001',
                'ref_no' => 'MAJTEST_001',
                'currency' => 'MYR',
                'payment_method' => 'Online Banking',
                'amount' => '100.00',
                'payment_link' => 'https://pay.majesticpay.test/checkout',
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
        $orderType = $this->makeOrderType('3'); // Online Banking
        $order = $this->makeOrder($orderType, 'MAJTEST_001');

        Http::fake([
            'https://api.majesticpay.test/*' => Http::response($this->successResponse(), 200),
        ]);

        $result = (new Deposit($this->makeAgentCashFlow()))->handle($this->makeRequestData($order));

        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertSame(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertSame('https://pay.majesticpay.test/checkout', $result->url);
        $this->assertSame($order->order_no, $result->orderNo);
        $this->assertSame('MAJ_TR_001', $result->providerOrderNo);

        $order->refresh();
        $this->assertSame('MAJ_TR_001', $order->third_party_order_no);
    }

    /**
     * 建參數與 hash 基準：assertSent 驗證送往 /api/create_deposit 的欄位，
     * 且 hash = SHA256(ref_no + number_format(amount,2) + currency)。
     */
    public function test_deposit_sends_expected_params_and_hash(): void
    {
        $orderType = $this->makeOrderType('3');
        $order = $this->makeOrder($orderType, 'MAJTEST_002', amount: '100.00');

        Http::fake([
            'https://api.majesticpay.test/*' => Http::response($this->successResponse(['ref_no' => 'MAJTEST_002']), 200),
        ]);

        (new Deposit($this->makeAgentCashFlow()))->handle($this->makeRequestData($order));

        $expectedHash = hash_hmac(
            'sha256',
            'MAJTEST_002' . number_format(100.00, 2, '.', '') . 'MYR',
            $this->secretKey
        );

        Http::assertSent(function (HttpRequest $request) use ($order, $expectedHash) {
            $data = $request->data();

            return $request->url() === 'https://api.majesticpay.test/api/create_deposit'
                && $data['api_key'] === 'maj_test_api_key'
                && $data['ref_no'] === 'MAJTEST_002'
                && (string) $data['amount'] === '100.00'
                && (string) $data['payment_method_id'] === '3'
                && $data['currency'] === 'MYR'
                && $data['return_url'] === 'https://return.test/majesticpay'
                && $data['callback_url'] === 'https://callback.test/majesticpay'
                && (string) $data['player_id'] === (string) $order->member_id
                && $data['hash'] === $expectedHash;
        });
    }

    /**
     * 失敗：status !== true → 拋 ApiException，帶三方 message。
     */
    public function test_deposit_failure_throws_api_exception(): void
    {
        $orderType = $this->makeOrderType('3');
        $order = $this->makeOrder($orderType, 'MAJTEST_003');

        Http::fake([
            'https://api.majesticpay.test/*' => Http::response([
                'status' => false,
                'message' => 'Ref no already existed.',
            ], 200),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Ref no already existed.');

        (new Deposit($this->makeAgentCashFlow()))->handle($this->makeRequestData($order));
    }
}
