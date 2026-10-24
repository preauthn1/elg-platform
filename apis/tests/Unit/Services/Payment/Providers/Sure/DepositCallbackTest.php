<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Sure;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Providers\Sure\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Sure 代收回調測試（空憑證守衛）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Sure/DepositCallbackTest.php
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
            'name' => 'Sure Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'DNDQR',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(string $callBackApiKey): AgentCashFlow
    {
        $info = [
            'MerchantID' => 'SURE_MID',
            'CallBackApiKey' => $callBackApiKey,
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
     * 空憑證守衛：CallBackApiKey 為空時 checkToken() 的 key 段為空、token 可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗 token 前先擋下（回 fail），
     * 且訂單維持 CREATED（未上分）。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('SURE_STD_EMPTY');
        $emptyAcf = $this->makeAgentCashFlow('');

        // 攻擊者以空 CallBackApiKey 自算 token：md5(MerchantID + amount + status + '' + trxno)
        $amount = '100.00';
        $status = '1';
        $trxno = '89072921';
        $forgedToken = [REDACTED]'SURE_MID' . $amount . $status . '' . $trxno));
        $callbackData = [
            'token' => $forgedToken,
            'merchant' => 'SURE_MID',
            'amount' => $amount,
            'refid' => 'SURE_STD_EMPTY',
            'trxno' => $trxno,
            'status' => $status,
            'status_message' => 'success',
        ];
        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new DepositCallback($emptyAcf))->handle($request);

        $this->assertSame('fail', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
