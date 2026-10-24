<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TgPay;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Providers\TgPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TgPay 代收回調測試（空憑證守衛）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TgPay/DepositCallbackTest.php
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
            'name' => 'TgPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'DuitNow',
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(string $publicKey, string $secretKey): AgentCashFlow
    {
        $info = [
            'API_URL' => 'https://api.tgpay.test',
            'PUBLIC_KEY' => $publicKey,
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
     * 空憑證守衛：PUBLIC_KEY／SECRET_KEY 為空時 verifyCallbackHeaders() 以 hash_equals
     * 比對 config 與 header，config 為空字串則攻擊者送空 header 即 hash_equals('', '') 為 true
     * 而通過、偽造成功回調上分。守衛須在 header 驗證前先擋下（回 fail），
     * 且訂單維持 CREATED（未上分）。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('TGPAY_DEP_EMPTY');
        $emptyAcf = $this->makeAgentCashFlow('', '');

        // 攻擊者送空 header（若無守衛，空 config 會使 hash_equals 通過）
        $server = [
            'HTTP_X_PUBLIC_KEY' => '',
            'HTTP_X_SECRET_KEY' => '',
        ];
        $callbackData = [
            'transaction_id' => 'TGPAY_TXN_FORGED',
            'order_no' => 'TGPAY_DEP_EMPTY',
            'amount' => '100.00',
            'status' => '1',
        ];
        $request = Request::create('/callback', 'POST', $callbackData, [], [], $server);
        $result = (new DepositCallback($emptyAcf))->handle($request);

        $this->assertSame('fail', $result->response);

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
