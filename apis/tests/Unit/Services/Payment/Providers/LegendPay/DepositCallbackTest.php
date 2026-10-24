<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\LegendPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Providers\LegendPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * LegendPay 代收回調測試（空憑證守衛）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/LegendPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private const API_SECRET = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(string $apiSecret): AgentCashFlow
    {
        $info = [
            'API_URL' => 'https://api.legendpay.test',
            'API_SECRET' => $apiSecret,
        ];
        $acf = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
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
            ['name' => 'LegendPay', 'status' => 1, 'key' => 'INR', 'info' => '{}', 'admin' => 0]
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
     * 空憑證守衛：API_SECRET 為空時 generateSign() 的 key 段為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（拋 ApiException），
     * 且訂單維持 CREATED（未上分）。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('LP_DEP_EMPTY');
        $emptyAcf = $this->makeAgentCashFlow('');

        // 攻擊者以空 key 自算簽名
        $data = [
            'amount' => '100.00',
            'commission' => '0.00',
            'orderAmount' => '100.00',
            'orderId' => 'LP_DEP_EMPTY',
            'param' => 'x',
            'status' => '1',
        ];
        $filtered = array_filter($data, fn ($v) => $v !== null && $v !== '');
        ksort($filtered);
        $data['sign'] = strtoupper(md5(urldecode(http_build_query($filtered)) . '&key='));
        $request = Request::create('/callback', 'POST', $data);

        try {
            (new DepositCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
            $this->assertSame('Signature verification failed', $e->getMessage());
        }

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
