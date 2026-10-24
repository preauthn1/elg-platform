<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Providers\FPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * FPay 代收回調測試（空憑證守衛）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/FPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(string $appSecret): AgentCashFlow
    {
        $info = [
            'API_URL' => 'https://api.fpay.test',
            'MERCHANT_NO' => 'test_merchant_no',
            'APP_KEY' => 'test_app_key',
            'APP_SECRET' => $appSecret,
        ];
        $acf = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $acf->info = $info;

        return $acf;
    }

    private function createOrder(string $orderNo): Order
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            ['name' => 'FPay', 'status' => 1, 'key' => 'MYR', 'info' => '{}', 'admin' => 0]
        );

        return Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => 100,
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 空憑證守衛：APP_SECRET 為空時 generateToken() 退化為 md5(order_id)、
     * 可離線計算，攻擊者能偽造成功回調直接上分。
     * 守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 CREATED（未上分）。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('FP_DEP_EMPTY');
        $emptyAcf = $this->makeAgentCashFlow('');

        // 攻擊者以空 secret 自算 token = [REDACTED]
        $callbackData = [
            'order_id' => 'FP_DEP_EMPTY',
            'amount' => '100.00',
            'currency' => 'MYR',
            'order_status' => 'completed',
            'status' => true,
            'charge' => '0.00',
            'token' => md5('' . 'FP_DEP_EMPTY'),
            'type' => 'deposit',
        ];
        $request = Request::create('/callback', 'POST', $callbackData);

        try {
            (new DepositCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
            // 預期：守衛在驗簽前硬拒
        }

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }
}
