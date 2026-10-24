<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\EpicPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Providers\EpicPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * EpicPay 代收回調測試（空憑證守衛）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/EpicPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private const MERCHANT_CODE = 'EPICTEST';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function makeAgentCashFlow(string $secretKey): AgentCashFlow
    {
        $info = [
            'API_URL' => 'https://api.epicpay.test',
            'MERCHANT_CODE' => self::MERCHANT_CODE,
            'SECRET_KEY' => $secretKey,
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
            ['name' => 'EpicPay', 'status' => 1, 'key' => 'MYR', 'info' => '{}', 'admin' => 0]
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
     * 空憑證守衛：SECRET_KEY 為空時 generateCallbackToken() 的 secret 段為空、
     * 簽名可離線計算，攻擊者能偽造成功回調直接上分。
     * 守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 CREATED（未上分）。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $order = $this->createOrder('EPIC_DEP_EMPTY');
        $emptyAcf = $this->makeAgentCashFlow('');

        // 攻擊者以空 secret 自算 token
        $forgedToken = [REDACTED] . '-' . '' . '-EPIC_DEP_EMPTY-100.00-1700000000');
        $request = Request::create('/callback', 'POST', [
            'id' => 'EPIC_DEP_EMPTY',
            'status' => '1',
            'amount' => '100.00',
            'fee' => '0.00',
            'message' => 'Success',
            'token' => $forgedToken,
            'ts' => '1700000000',
        ]);

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
