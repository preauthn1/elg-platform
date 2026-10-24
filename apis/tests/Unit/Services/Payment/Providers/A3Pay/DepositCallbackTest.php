<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Providers\A3Pay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A3Pay 代收回調測試：Status 語意 6 成功／7 失敗／其餘非終態不動單，簽名對解密後明文驗。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/A3Pay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    use A3PayCryptoHelpers;

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

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

        $this->member = Member::factory()->create(['parent' => $this->agent->id, 'admin' => 0]);
    }

    private function createOrder(string $orderNo, int $amount = 100, int $status = OrderStatusEnum::CREATED->value): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 131, 'currency_id' => 1, 'key' => 'OB'],
            ['parent' => 0, 'name' => 'Online banking', 'type' => 1, 'info' => '{}', 'choose_bank' => 0, 'status' => 1, 'admin' => 0]
        );

        return Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => $status,
            'balance' => 0,
            'amount' => $amount,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    private function envelope(string $orderNo, int $status, string $amount = '100.00'): array
    {
        return $this->buildCallbackEnvelope([
            'MerchantCode' => 'SGD00001',
            'CurrencyCode' => 'SGD',
            'MerchantTransactionId' => $orderNo,
            'Status' => $status,
            'Amount' => $amount,
            'NetAmount' => '98.50',
            'Remark' => null,
            'PaymentMethodCode' => 'OB',
        ], ['TransactionId' => 'D2026092200000001']);
    }

    public function test_status_6_marks_order_success_with_envelope_transaction_id(): void
    {
        $order = $this->createOrder('A3CB001');

        $result = (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB001', 6)));

        $this->assertSame('success', $result->response);
        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame('D2026092200000001', $order->third_party_order_no);
    }

    public function test_status_7_marks_order_failed(): void
    {
        $order = $this->createOrder('A3CB002');

        $result = (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB002', 7)));

        $this->assertSame('success', $result->response);
        $this->assertSame(OrderStatusEnum::FAILED->value, $order->refresh()->status);
    }

    /** Status 4（Verify Fail）是非終態：不能當失敗判死，訂單維持 CREATED、仍 ack */
    public function test_status_4_is_non_final_and_keeps_order_created(): void
    {
        $order = $this->createOrder('A3CB003');

        $result = (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB003', 4)));

        $this->assertSame('success', $result->response);
        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
        $this->assertSame('', $order->third_party_order_no);
    }

    /** 真實回調（dev 訂單 13913）：下單 10、三方唯一化上調、會員實付 10.01 → 訂單改以實付金額入帳 */
    public function test_real_adjusted_callback_adopts_actual_amount(): void
    {
        $order = $this->createOrder(A3PayRealPayloads::PAYIN_CALLBACK_SUCCESS_ADJUSTED['MerchantTransactionId'], 10);

        $result = (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->buildCallbackEnvelope(A3PayRealPayloads::PAYIN_CALLBACK_SUCCESS_ADJUSTED, ['TransactionId' => 'D2026092400000355'])));

        $this->assertSame('success', $result->response);
        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame('10.0100', (string) $order->amount);
        // rate 1、商戶 tax 0：point = 10.01 × 1 − 0
        $this->assertSame('10.0100', (string) $order->point);
        $this->assertSame('D2026092400000355', $order->third_party_order_no);
    }

    /** 真實回調（dev 訂單 13912）：金額未上調，訂單金額不動 */
    public function test_real_exact_callback_keeps_amount(): void
    {
        $order = $this->createOrder(A3PayRealPayloads::PAYIN_CALLBACK_SUCCESS_EXACT['MerchantTransactionId'], 10);

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->buildCallbackEnvelope(A3PayRealPayloads::PAYIN_CALLBACK_SUCCESS_EXACT, ['TransactionId' => 'D2026092400000354'])));

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame('10.0000', (string) $order->amount);
        // 金額未上調就不重算，point 維持建單值（fixture 為 0）
        $this->assertSame('0.0000', (string) $order->point);
    }

    /** 真實回調（dev 訂單 13916）：Status 7 失敗且金額被上調 → 只標失敗，不動金額 */
    public function test_real_failed_callback_with_adjusted_amount_marks_failed_without_touching_amount(): void
    {
        $order = $this->createOrder(A3PayRealPayloads::PAYIN_CALLBACK_FAIL_ADJUSTED['MerchantTransactionId'], 20);

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->buildCallbackEnvelope(A3PayRealPayloads::PAYIN_CALLBACK_FAIL_ADJUSTED, ['TransactionId' => 'D2026092400000358'])));

        $order->refresh();
        $this->assertSame(OrderStatusEnum::FAILED->value, $order->status);
        $this->assertSame('20.0000', (string) $order->amount);
    }

    /** 上調金額時 point 依建單公式重算：含商戶固定與浮動手續費 */
    public function test_adopted_amount_recalculates_point_with_merchant_fees(): void
    {
        $this->agentCashFlow->tax = 1;
        $this->agentCashFlow->tax_percentage = 0.1;
        $order = $this->createOrder('A3CB011', 100);

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB011', 6, '100.05')));

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame('100.0500', (string) $order->amount);
        // gross 100.05 × rate 1 = 100.05；tax = 1 + 100.05 × 0.1 = 11.005；point = 89.045
        $this->assertSame(0, Common::numCompare('11.005', (string) $order->tax, 4));
        $this->assertSame(0, Common::numCompare('89.045', (string) $order->point, 4));
    }

    public function test_upward_adjustment_at_tolerance_boundary_adopts_actual_amount(): void
    {
        $order = $this->createOrder('A3CB008', 100);

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB008', 6, '100.10')));

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertSame('100.1000', (string) $order->amount);
    }

    public function test_overpayment_beyond_tolerance_goes_to_manual_confirmation(): void
    {
        $order = $this->createOrder('A3CB009', 100);

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB009', 6, '100.11')));

        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->refresh()->status);
    }

    /** 少付不放行，即使只差 0.01 */
    public function test_underpayment_goes_to_manual_confirmation(): void
    {
        $order = $this->createOrder('A3CB010', 100);

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB010', 6, '99.99')));

        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->refresh()->status);
    }

    public function test_invalid_signature_throws(): void
    {
        $this->createOrder('A3CB005');
        $payload = $this->envelope('A3CB005', 6);
        $payload['Signature'] = base64_encode(random_bytes(64));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid signature');

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $payload));
    }

    public function test_repeated_callback_on_processed_order_is_idempotent(): void
    {
        $order = $this->createOrder('A3CB006', 100, OrderStatusEnum::SUCCESS->value);

        $result = (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3CB006', 7)));

        // 已處理訂單：回成功停止重送，狀態不被後到的失敗回調改寫
        $this->assertSame('success', $result->response);
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->refresh()->status);
    }

    /** 金額閘門在 beginTransaction 之前就拋，訂單不會被碰到 */
    public function test_non_numeric_amount_is_rejected(): void
    {
        $this->createOrder('A3CB007');
        $payload = $this->buildCallbackEnvelope([
            'MerchantTransactionId' => 'A3CB007', 'Status' => 6, 'Amount' => 'abc', 'CurrencyCode' => 'SGD',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid callback amount');

        (new DepositCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $payload));
    }
}
