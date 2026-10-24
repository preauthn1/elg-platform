<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GMPay;

use App\Enums\OrderStatusEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\GMPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * GMPay 代收回調測試（工單 #2435：V1.5 origin_amount 湊零頭調整）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GMPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private OrderType $orderType;

    private string $secretKey = 'gmpay_test_secret';

    /** GMPay SGD 代收匯率（TPower 實際配置 rate=3.146） */
    private string $rate = '3.146';

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

        $this->orderType = OrderType::create([
            'cash_flow_id' => 73,
            'currency_id' => 1,
            'name' => 'GMPay Callback Test',
            'type' => 1,
            'status' => 1,
            'key' => 'PayNow',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(): AgentCashFlow
    {
        $info = [
            'API_URL' => 'https://api.gmpaysg.test',
            'SECRET_KEY' => $this->secretKey,
        ];

        $cashFlow = new AgentCashFlow([
            'cash_flow_id' => 73,
            'cash_flow_info_id' => 1,
            'rate' => $this->rate,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $cashFlow->info = $info;

        return $cashFlow;
    }

    /**
     * 建立待回調的代收訂單（status = CREATED，point = amount × rate）
     */
    private function makeOrder(string $amount, string $thirdPartyNo): Order
    {
        return Order::create([
            // 回調檢查比對訂單 ACF 的 cash_flow_id，訂單須掛在同一家金流商的 ACF 下
            'agent_cash_flow_id' => $this->createAgentCashFlow($this->agent, ['cash_flow_id' => 73])->id,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'amount' => $amount,
            'rate' => $this->rate,
            'point' => Common::numMul($amount, $this->rate, 4),
            'balance' => 0,
            'remark' => '',
            'order_no' => 'GMPTEST' . uniqid(),
            'status' => OrderStatusEnum::CREATED->value,
            'third_party_order_no' => $thirdPartyNo,
            'admin' => 0,
        ]);
    }

    /**
     * GMPay 回調驗簽：SHA256(transaction_reference + amount + currency)
     */
    private function generateHash(string $transactionReference, string $amount, string $currency): string
    {
        return hash_hmac('sha256', $transactionReference . $amount . $currency, $this->secretKey);
    }

    /**
     * 呼叫 GMPay 代收回調
     *
     * @param  array<string, mixed>  $extra  額外/覆寫欄位（如 origin_amount、is_repush）
     */
    private function callCallback(
        Order $order,
        string $amount,
        ?string $originAmount,
        string $status = 'completed',
        string $currency = 'SGD',
        array $extra = [],
    ): DepositCallbackResponseData {
        $transactionReference = $order->third_party_order_no;

        $payload = array_merge([
            'status' => $status,
            'currency' => $currency,
            'amount' => $amount,
            'hash' => $this->generateHash($transactionReference, $amount, $currency),
            'ref_no' => $order->order_no,
            'platform_charge' => '0.00',
            'transaction_reference' => $transactionReference,
            'updated_at' => now()->toDateTimeString(),
            'is_sandbox' => 0,
        ], $extra);

        if ($originAmount !== null) {
            $payload['origin_amount'] = $originAmount;
        }

        $request = Request::create('/callback', 'POST', $payload);

        return (new DepositCallback($this->makeAgentCashFlow()))->handle($request);
    }

    /**
     * 湊零頭上調（amount 10.10 > origin 10.00，差 0.10 ≤ 1.00）：
     * 以實付 amount 重算 orders.amount / orders.point，狀態 SUCCESS。
     */
    public function test_paid_amount_within_cap_credits_callback_amount(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'GMP_TXN_001');

        $result = $this->callCallback($order, amount: '10.10', originAmount: '10.00');

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertSame('OK', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        // orders.amount 改寫為實付、orders.point = 10.10 × 3.146 = 31.7746
        $this->assertEqualsWithDelta(10.10, (float) $order->amount, 0.0001);
        $this->assertEqualsWithDelta(31.7746, (float) $order->point, 0.0001);
    }

    /**
     * 邊界：amount − origin == CAP(1.00)（10.00 → 11.00）仍在範圍內 → SUCCESS。
     */
    public function test_paid_amount_equal_cap_boundary_credits(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'GMP_TXN_002');

        $result = $this->callCallback($order, amount: '11.00', originAmount: '10.00');

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEqualsWithDelta(11.00, (float) $order->amount, 0.0001);
        $this->assertEqualsWithDelta(34.606, (float) $order->point, 0.0001); // 11.00 × 3.146
    }

    /**
     * 實付等於原始（無調整）：point 維持 amount × rate，狀態 SUCCESS。
     */
    public function test_paid_amount_equal_origin_keeps_point(): void
    {
        $order = $this->makeOrder(amount: '20.00', thirdPartyNo: 'GMP_TXN_003');

        $result = $this->callCallback($order, amount: '20.00', originAmount: '20.00');

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEqualsWithDelta(20.00, (float) $order->amount, 0.0001);
        $this->assertEqualsWithDelta(62.92, (float) $order->point, 0.0001); // 20.00 × 3.146
    }

    /**
     * 逾越 CAP（amount 11.50 − origin 10.00 = 1.50 > 1.00）：
     * 轉 WAITING_MANUAL_DEPOSIT，orders.amount / point 不變（不自動上分）。
     */
    public function test_paid_amount_over_cap_routes_to_manual(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'GMP_TXN_004');

        $result = $this->callCallback($order, amount: '11.50', originAmount: '10.00');

        $order->refresh();
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
        // 未通過防護：金額不改寫，維持原始 10.00 / 31.46
        $this->assertEqualsWithDelta(10.00, (float) $order->amount, 0.0001);
        $this->assertEqualsWithDelta(31.46, (float) $order->point, 0.0001);
    }

    /**
     * 實付小於原始（amount 9.90 < origin 10.00）：視為異常轉人工，金額不改寫。
     */
    public function test_paid_amount_below_origin_routes_to_manual(): void
    {
        $order = $this->makeOrder(amount: '10.00', thirdPartyNo: 'GMP_TXN_005');

        $result = $this->callCallback($order, amount: '9.90', originAmount: '10.00');

        $order->refresh();
        $this->assertSame(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
        $this->assertEqualsWithDelta(10.00, (float) $order->amount, 0.0001);
        $this->assertEqualsWithDelta(31.46, (float) $order->point, 0.0001);
    }

    /**
     * 回歸保護：回調未帶 origin_amount（MajesticPay 舊行為）→ 不觸發重算，
     * 狀態 SUCCESS，orders.amount / point 維持原始值。
     */
    public function test_callback_without_origin_amount_keeps_legacy_behavior(): void
    {
        $order = $this->makeOrder(amount: '15.00', thirdPartyNo: 'GMP_TXN_006');

        $result = $this->callCallback($order, amount: '15.00', originAmount: null);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEqualsWithDelta(15.00, (float) $order->amount, 0.0001);
        $this->assertEqualsWithDelta(47.19, (float) $order->point, 0.0001); // 15.00 × 3.146 原值
    }

    /**
     * 空憑證守衛：SECRET_KEY 為空時 checkHash() 的 HMAC key 段為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（回 Failed），且訂單維持 CREATED。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->makeOrder('100.00', 'GMPAY_TP_EMPTY');

        $emptyInfo = ['API_URL' => 'https://api.gmpaysg.test', 'SECRET_KEY' => ''];
        $emptyAcf = new AgentCashFlow([
            'cash_flow_id' => 73,
            'cash_flow_info_id' => 1,
            'rate' => $this->rate,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 secret 自算 hash
        $transactionReference = $order->third_party_order_no;
        $forgedHash = hash_hmac('sha256', $transactionReference . '100.00' . 'SGD', '');
        $request = Request::create('/callback', 'POST', [
            'status' => 'completed',
            'currency' => 'SGD',
            'amount' => '100.00',
            'hash' => $forgedHash,
            'ref_no' => $order->order_no,
            'platform_charge' => '0.00',
            'transaction_reference' => $transactionReference,
            'updated_at' => now()->toDateTimeString(),
            'is_sandbox' => 0,
        ]);

        $result = (new DepositCallback($emptyAcf))->handle($request);

        $this->assertSame('Failed', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
