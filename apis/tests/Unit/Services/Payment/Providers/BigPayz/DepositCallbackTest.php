<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BigPayz;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\BigPayz\BigPayzPayinCallbackStatusCodeEnum;
use App\Services\Payment\Providers\BigPayz\DepositCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * BigPayz Payin V2 callback unit tests.
 * API doc: https://example.invalid/apidocv1.asp#payin-callback-post
 * Params: ItemID, transaction, status, Amount, Currency, signature2, created_at.
 * signature2 = hash_hmac(sha256, 'transaction_id + status code + Currency + Amount + created_at', API Key).
 * status: 1=Success/Accepted, 2=Rejected, 3=Approved.
 */
#[RequiresPhpExtension('redis')]
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->agent = $this->createAgentAndDomain();

        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR', 'name' => 'Malaysian Ringgit', 'rate' => 1, 'status' => 1, 'admin' => 0]));

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

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /** API doc: signature2 = hash_hmac(sha256, 'transaction_id + status code + Currency + Amount + created_at', API Key). */
    private function generatePayinCallbackSignature(array $payload, string $apiKey): string
    {
        $string = (string) $payload['transaction']
            . (string) $payload['status']
            . (string) $payload['Currency']
            . (string) $payload['Amount']
            . (string) $payload['created_at'];

        return hash_hmac('sha256', $string, $apiKey);
    }

    /** Build Payin V2 callback payload (doc format: ItemID, transaction, signature2, created_at). */
    private function buildPayinPayload(string $orderNo, string $transactionId, int $status, string $amount, string $createdAt): array
    {
        $payload = [
            'ItemID' => $orderNo,
            'transaction' => $transactionId,
            'status' => $status,
            'Amount' => $amount,
            'Currency' => 'MYR',
            'created_at' => $createdAt,
        ];
        $payload['signature2'] = $this->generatePayinCallbackSignature($payload, 'test_api_key');

        return $payload;
    }

    /** Real API format: Signature = hash_hmac(sha256, InvoiceNo + ReceiverBank + ReceiverAccount + Currency + Amount + PaymentDate, API Key). */
    private function generatePayinCallbackSignatureReal(array $payload, string $apiKey): string
    {
        $string = (string) $payload['InvoiceNo']
            . (string) $payload['ReceiverBank']
            . (string) $payload['ReceiverAccount']
            . (string) $payload['Currency']
            . (string) $payload['Amount']
            . (string) $payload['PaymentDate'];

        return hash_hmac('sha256', $string, $apiKey);
    }

    /** Build real API callback payload (RefId, InvoiceNo, Signature, ReceiverBank, ReceiverAccount, PaymentDate). */
    private function buildPayinPayloadReal(string $orderNo, string $invoiceNo, int $status, string $amount, string $paymentDate): array
    {
        $payload = [
            'RefId' => $orderNo,
            'InvoiceNo' => $invoiceNo,
            'ReceiverBank' => 'RHBR',
            'ReceiverAccount' => '25401300042069',
            'Currency' => 'MYR',
            'Amount' => $amount,
            'PaymentDate' => $paymentDate,
            'Status' => $status,
            'StatusMessage' => $status === 1 ? 'Success' : 'Reject',
            'DeclineReason' => '',
            'MerchantCode' => 'test_merchant',
        ];
        $payload['Signature'] = $this->generatePayinCallbackSignatureReal($payload, 'test_api_key');

        return $payload;
    }

    public function test_success_status_one_updates_order(): void
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
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
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

        $payload = $this->buildPayinPayload(
            $order->order_no,
            '009-216-20-798',
            BigPayzPayinCallbackStatusCodeEnum::SUCCESS->value,
            '100.00',
            '03/31/2019 4:38:28 PM'
        );

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(0, $result->response['error_code']);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('009-216-20-798', $order->third_party_order_no);
    }

    public function test_success_status_three_approved_updates_order(): void
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
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
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

        $payload = $this->buildPayinPayload(
            $order->order_no,
            '009-216-20-799',
            BigPayzPayinCallbackStatusCodeEnum::APPROVED->value,
            '50.00',
            '03/31/2019 4:38:29 PM'
        );

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(0, $result->response['error_code']);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    public function test_failed_status_rejected_updates_order_failed(): void
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
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
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

        $payload = $this->buildPayinPayload(
            $order->order_no,
            '009-216-20-800',
            BigPayzPayinCallbackStatusCodeEnum::REJECTED->value,
            '100.00',
            '03/31/2019 4:38:30 PM'
        );

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(0, $result->response['error_code']);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
    }

    public function test_invalid_signature_throws(): void
    {
        $payload = $this->buildPayinPayload('BPZ20240206004', '009-216-20-801', 1, '100.00', '03/31/2019 4:38:31 PM');
        $payload['signature2'] = 'invalid_signature';

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('signature verification failed');

        $handler->handle($request);
    }

    public function test_missing_required_params_throws(): void
    {
        $request = Request::create('/callback', 'POST', [
            'ItemID' => 'BPZ20240206005',
            'status' => 1,
        ]);
        $handler = new DepositCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('missing required parameters');

        $handler->handle($request);
    }

    public function test_order_not_found_returns_fail_response(): void
    {
        $payload = $this->buildPayinPayload('BPZ_NOT_FOUND', '009-216-20-802', 1, '100.00', '03/31/2019 4:38:32 PM');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(1, $result->response['error_code']);
        $this->assertNull($result->order);
    }

    public function test_success_real_format_updates_order(): void
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

        $orderNo = 'BPZ20240206007';
        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => $orderNo,
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '43.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $payload = $this->buildPayinPayloadReal(
            $orderNo,
            '502-MY05-071-26-217',
            BigPayzPayinCallbackStatusCodeEnum::SUCCESS->value,
            '43.00',
            '3/12/2026 4:48:36 PM'
        );

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new DepositCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(0, $result->response['error_code']);
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('502-MY05-071-26-217', $order->third_party_order_no);
    }

    public function test_unknown_format_throws(): void
    {
        $request = Request::create('/callback', 'POST', [
            'Amount' => '100.00',
            'Currency' => 'MYR',
            'status' => 1,
        ]);
        $handler = new DepositCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('unknown format');

        $handler->handle($request);
    }
}
