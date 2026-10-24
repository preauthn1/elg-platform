<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FlashPay;

use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\FlashPay\Deposit;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/Payment/Providers/FlashPay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'SGD',
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://example.invalid/v3',
            'CLIENT_ID' => '8e363c65-6dec-11f1-8a6d-0200009ee8a2',
            'SECRET_KEY' => '5c378d3e9e1fcacf83e4ad94df944a538102905a116af5ae6ed6f1bdff13ca97',
            'CURRENCY' => 'SGD',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    public function test_success(): void
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            ['name' => 'FlashPay PayNow', 'status' => 1, 'key' => 'SGD_PAYNOW', 'info' => '{}', 'admin' => 0]
        );

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'FP' . time(),
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        Http::fake([
            '*' => Http::response([
                'status_code' => 0,
                'status_msg' => 'NO_ERROR',
                'tran_token' => '7c711a99-8b42-11ee-b53e-020000557396',
                'tran_customer' => $this->member->username,
                'tran_amount' => 100,
                'tran_unique' => $order->order_no,
                'tran_fee' => 1.11,
                'redirect_to' => 'https://example.invalid/v3/?token=[REDACTED]',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);
        $result = $deposit->handle(DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]));

        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertNotEmpty($result->url);
        $this->assertEquals($order->order_no, $result->orderNo);
        $this->assertEquals('7c711a99-8b42-11ee-b53e-020000557396', $result->providerOrderNo);
    }

    public function test_api_error_response(): void
    {
        $orderType = OrderType::firstOrCreate(
            ['cash_flow_id' => 1, 'currency_id' => 1],
            ['name' => 'FlashPay PayNow', 'status' => 1, 'key' => 'SGD_PAYNOW', 'info' => '{}', 'admin' => 0]
        );

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $this->currency->id,
            'amount' => 100,
            'point' => 0,
            'balance' => 0,
            'remark' => '',
            'order_no' => 'FP' . time(),
            'status' => 1,
            'admin' => 0,
            'third_party_order_no' => '',
        ]);

        Http::fake([
            '*' => Http::response([
                'status_code' => -1,
                'status_msg' => 'INVALID_PARAMETER',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);

        $this->expectException(\Exception::class);
        $deposit->handle(DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://callback.test',
            'returnUrl' => 'https://return.test',
            'ip' => '0.0.0.0',
        ]));
    }
}
