<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BigPayz;

use App\Enums\DepositResponseType;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Providers\BigPayz\BigPayzStatusCodeEnum;
use App\Services\Payment\Providers\BigPayz\Deposit;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * BigPayz Deposit (Payin V2) unit tests.
 */
#[RequiresPhpExtension('redis')]
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'PAYIN_API_URL' => 'https://api.bigpayz.test',
            'PAYOUT_API_URL' => 'https://payout.bigpayz.test',
            'DUITNOW_API_URL' => 'https://upi.bigpayz.test',
            'MERCHANT_CODE' => 'test_merchant',
            'API_KEY' => 'test_api_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 999,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->setRelation('currency', $this->currency);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    public function test_deposit_success_initiated_returns_redirect(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 999,
            'currency_id' => 1,
            'name' => 'BigPayz Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BPZ20240206001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake([
            '*Payin/DepositV2' => Http::response([
                'transaction' => 'tx-001',
                'status' => BigPayzStatusCodeEnum::INITIATED->value,
                'redirect_to' => 'https://api.bigpayz.test/redirect?token=[REDACTED]',
                'token' => 'mock',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
        ]);

        $response = $deposit->handle($request);

        $this->assertSame(DepositResponseType::URL_REDIRECT, $response->type);
        $this->assertEquals('https://api.bigpayz.test/redirect?token=[REDACTED]', $response->url);
        $this->assertEquals($order->order_no, $response->orderNo);
        $this->assertEquals('tx-001', $response->providerOrderNo);

        $order->refresh();
        $this->assertEquals('mock', $order->third_party_order_no);
    }

    public function test_deposit_success_accepted_returns_redirect(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 999,
            'currency_id' => 1,
            'name' => 'BigPayz Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BPZ20240206002',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '50.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake([
            '*Payin/DepositV2' => Http::response([
                'transaction' => 'tx-002',
                'status' => BigPayzStatusCodeEnum::ACCEPTED->value,
                'redirect_to' => 'https://api.bigpayz.test/pay',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
        ]);

        $response = $deposit->handle($request);

        $this->assertSame(DepositResponseType::URL_REDIRECT, $response->type);
        $this->assertEquals('https://api.bigpayz.test/pay', $response->url);
        $this->assertEquals('tx-002', $response->providerOrderNo);
    }

    public function test_deposit_failed_status_throws_exception(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 999,
            'currency_id' => 1,
            'name' => 'BigPayz Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BPZ20240206003',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake([
            '*Payin/DepositV2' => Http::response([
                'transaction' => 'tx-003',
                'status' => BigPayzStatusCodeEnum::REJECTED->value,
                'message' => 'Order rejected',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('BigPayz deposit error');

        $deposit->handle($request);
    }

    public function test_deposit_includes_bank_code_when_order_type_has_key(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 999,
            'currency_id' => 1,
            'name' => 'BigPayz Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'MB2U',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'BPZ20240206004',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        Http::fake([
            '*Payin/DepositV2' => Http::response([
                'transaction' => 'tx-004',
                'status' => BigPayzStatusCodeEnum::INITIATED->value,
                'redirect_to' => 'https://api.bigpayz.test/redirect',
            ], 200),
        ]);

        $deposit = new Deposit($this->agentCashFlow);
        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://merchant.test/callback',
            'returnUrl' => 'https://merchant.test/return',
        ]);

        $deposit->handle($request);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return isset($body['BankCode']) && $body['BankCode'] === 'MB2U';
        });
    }
}
