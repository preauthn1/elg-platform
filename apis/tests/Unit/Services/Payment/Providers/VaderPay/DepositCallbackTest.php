<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VaderPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\VaderPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * VaderPay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VaderPay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR', 'name' => 'Malaysian Ringgit', 'rate' => 1, 'status' => 1, 'admin' => 0]));

        $info = [
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'merchant01',
            'API_KEY' => 'test_api_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_id' => 201,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
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

    private function generateSignature(array $payload, string $apiKey): string
    {
        $stringToSign = $payload['InvoiceNo']
            . $payload['ReceiverBank']
            . $payload['ReceiverAccount']
            . $payload['Currency']
            . $payload['Amount']
            . $payload['PaymentDate'];

        return hash_hmac('sha256', $stringToSign, $apiKey);
    }

    public function test_success(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 201,
            'currency_id' => 1,
            'name' => 'VaderPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'VDP20240206002',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $payload = [
            'InvoiceNo' => 'INV-0001',
            'ReceiverBank' => 'CIMB',
            'ReceiverAccount' => '123456789',
            'Currency' => 'MYR',
            'Amount' => '100.00',
            'OriginalAmount' => '100.00',
            'PaymentDate' => '2026-02-06 12:00:00',
            'MerchantCode' => 'merchant01',
            'RefId' => 'VDP20240206002',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'DeclineReason' => '',
        ];

        $payload['Signature'] = $this->generateSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(0, $result->response['error_code']);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('INV-0001', $order->third_party_order_no);
    }

    public function test_failed_status(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 201,
            'currency_id' => 1,
            'name' => 'VaderPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'VDP20240206003',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $payload = [
            'InvoiceNo' => 'INV-0002',
            'ReceiverBank' => 'CIMB',
            'ReceiverAccount' => '123456789',
            'Currency' => 'MYR',
            'Amount' => '100.00',
            'OriginalAmount' => '100.00',
            'PaymentDate' => '2026-02-06 12:01:00',
            'MerchantCode' => 'merchant01',
            'RefId' => 'VDP20240206003',
            'Status' => 2,
            'StatusMessage' => 'Rejected',
            'DeclineReason' => 'Rejected',
        ];

        $payload['Signature'] = $this->generateSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(0, $result->response['error_code']);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
        $this->assertEquals('INV-0002', $order->third_party_order_no);
    }

    public function test_invalid_signature(): void
    {
        $payload = [
            'InvoiceNo' => 'INV-0003',
            'ReceiverBank' => 'CIMB',
            'ReceiverAccount' => '123456789',
            'Currency' => 'MYR',
            'Amount' => '100.00',
            'OriginalAmount' => '100.00',
            'PaymentDate' => '2026-02-06 12:02:00',
            'MerchantCode' => 'merchant01',
            'RefId' => 'VDP20240206004',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'DeclineReason' => '',
            'Signature' => 'INVALID',
        ];

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('VaderPay deposit callback signature verification failed');

        $handler->handle($request);
    }

    public function test_order_not_found(): void
    {
        $payload = [
            'InvoiceNo' => 'INV-0004',
            'ReceiverBank' => 'CIMB',
            'ReceiverAccount' => '123456789',
            'Currency' => 'MYR',
            'Amount' => '100.00',
            'OriginalAmount' => '100.00',
            'PaymentDate' => '2026-02-06 12:03:00',
            'MerchantCode' => 'merchant01',
            'RefId' => 'VDP_NOT_FOUND',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'DeclineReason' => '',
        ];

        $payload['Signature'] = $this->generateSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(-1, $result->response['error_code']);
        $this->assertNull($result->order);
    }

    public function test_amount_mismatch_sets_manual_status(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 201,
            'currency_id' => 1,
            'name' => 'VaderPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'CIMB',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'VDP20240206005',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $payload = [
            'InvoiceNo' => 'INV-0005',
            'ReceiverBank' => 'CIMB',
            'ReceiverAccount' => '123456789',
            'Currency' => 'MYR',
            'Amount' => '90.00',
            'OriginalAmount' => '100.00',
            'PaymentDate' => '2026-02-06 12:04:00',
            'MerchantCode' => 'merchant01',
            'RefId' => 'VDP20240206005',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'DeclineReason' => '',
        ];

        $payload['Signature'] = $this->generateSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $handler->handle($request);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
        $this->assertEquals('INV-0005', $order->third_party_order_no);
    }
}
