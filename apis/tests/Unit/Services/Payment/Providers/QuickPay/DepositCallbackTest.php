<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\QuickPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Providers\QuickPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * QuickPay 代收回調測試（空憑證守衛）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/QuickPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private OrderType $orderType;

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

        $this->orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'QuickPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'FPX',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(string $secretKey): AgentCashFlow
    {
        $info = [
            'API_URL' => 'https://api.quickpay.test',
            'SECRET_KEY' => $secretKey,
        ];
        $acf = new AgentCashFlow([
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
        return Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $this->orderType->id,
            'currency_id' => 1,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 空憑證守衛：SECRET_KEY 為空時 generateSignature() 的 key 段為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（拋 ApiException），
     * 且訂單維持 CREATED（未上分）。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('QPAY_DEP_EMPTY');
        $emptyAcf = $this->makeAgentCashFlow('');

        // 攻擊者以空 secret 自算 sign
        $params = ['status' => '1', 'result' => json_encode(['orderid' => 'QPAY_DEP_EMPTY', 'amount' => '100.00'])];
        ksort($params);
        $qs = '';
        foreach ($params as $k => $v) {
            $qs .= $k . '=' . trim((string) $v) . '&';
        }
        $forgedSign = strtoupper(md5($qs . 'key='));
        $request = Request::create('/callback', 'POST', array_merge($params, ['sign' => $forgedSign]));

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
