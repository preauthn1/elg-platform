<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BigPayz;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\BigPayz\WithdrawalCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * BigPayz Payout callback unit tests.
 * RefId = our transaction_no (payout record).
 * Signature = HMAC-SHA256(InvoiceNo + Status + Currency + Amount + PaymentDate, API Key).
 * Status: 0 = In Process, 1 = Success, 2 = Rejected, 3 = Manual Approve.
 */
#[RequiresPhpExtension('redis')]
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'PAYIN_API_URL' => 'https://api.bigpayz.test',
            'PAYOUT_API_URL' => 'https://api.bigpayz.test',
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

    private function createWithdrawalOrder(string $amount, int $status): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'bank_id' => 1,
            'bank_name' => 'CIMB',
            'bank_code' => 'CIMB',
            'account_name' => 'Test User',
            'account_no' => '1234567890',
            'agent_group_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => $amount,
            'rate' => 1,
            'point' => 0,
            'tax' => 0,
            'third_party_order_no' => '',
            'status' => $status,
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '{}',
        ]);
    }

    private function createPayoutRecord(WithdrawalOrder $order, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 999,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
            'result_amount' => '0.00',
            'result_code' => '',
            'is_success_payout' => 0,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '{}',
            'payout_order_response' => '{}',
            'callback_request_body' => '{}',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ]);
    }

    private function generatePayoutCallbackSignature(array $payload, string $apiKey): string
    {
        $string = ($payload['InvoiceNo'] ?? '')
            . ($payload['Status'] ?? '')
            . ($payload['Currency'] ?? '')
            . ($payload['Amount'] ?? '')
            . ($payload['PaymentDate'] ?? '');

        return hash_hmac('sha256', $string, $apiKey);
    }

    public function test_success_status_updates_order_and_record(): void
    {
        $transactionNo = 'BPZ20240206001';
        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);
        $this->createPayoutRecord($withdrawalOrder, $transactionNo);

        $payload = [
            'RefId' => $transactionNo,
            'InvoiceNo' => 'INV-PAYOUT-001',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'Currency' => 'MYR',
            'Amount' => '5000.00',
            'PaymentDate' => '2026-02-06 12:10:00',
        ];
        $payload['Signature'] = $this->generatePayoutCallbackSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(0, $result->response['error_code']);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
        $this->assertEquals('INV-PAYOUT-001', $withdrawalOrder->third_party_order_no);

        $payoutRecord = WithdrawalPayoutRecord::where('transaction_no', $transactionNo)->first();
        $this->assertNotNull($payoutRecord);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    public function test_failed_status_updates_order_failed(): void
    {
        $transactionNo = 'BPZ20240206002';
        $withdrawalOrder = $this->createWithdrawalOrder('1000.00', 22);
        $this->createPayoutRecord($withdrawalOrder, $transactionNo);

        $payload = [
            'RefId' => $transactionNo,
            'InvoiceNo' => 'INV-PAYOUT-002',
            'Status' => 2,
            'StatusMessage' => 'Rejected',
            'Currency' => 'MYR',
            'Amount' => '1000.00',
            'PaymentDate' => '2026-02-06 12:11:00',
        ];
        $payload['Signature'] = $this->generatePayoutCallbackSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(0, $result->response['error_code']);

        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        $payoutRecord = WithdrawalPayoutRecord::where('transaction_no', $transactionNo)->first();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    public function test_invalid_signature_throws(): void
    {
        $payload = [
            'RefId' => 'BPZ20240206003',
            'InvoiceNo' => 'INV-PAYOUT-003',
            'Status' => 1,
            'Currency' => 'MYR',
            'Amount' => '500.00',
            'PaymentDate' => '2026-02-06 12:12:00',
            'Signature' => 'invalid_signature',
        ];

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(\App\Exceptions\ApiException::class);
        $this->expectExceptionMessage('BigPayz withdrawal callback signature verification failed');

        $handler->handle($request);
    }

    public function test_record_not_found_returns_fail_response(): void
    {
        $payload = [
            'RefId' => 'BPZ_NOT_FOUND',
            'InvoiceNo' => 'INV-PAYOUT-004',
            'Status' => 1,
            'Currency' => 'MYR',
            'Amount' => '500.00',
            'PaymentDate' => '2026-02-06 12:13:00',
        ];
        $payload['Signature'] = $this->generatePayoutCallbackSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(1, $result->response['error_code']);
        $this->assertNull($result->order);
    }

    public function test_indeterminate_keeps_22_and_acks(): void
    {
        $transactionNo = 'BPZ20240206010';
        $withdrawalOrder = $this->createWithdrawalOrder('2000.00', 22);
        $this->createPayoutRecord($withdrawalOrder, $transactionNo);

        $payload = [
            'RefId' => $transactionNo,
            'InvoiceNo' => 'INV-PAYOUT-010',
            'Status' => 0, // In Process → INDETERMINATE
            'StatusMessage' => 'In Process',
            'Currency' => 'MYR',
            'Amount' => '2000.00',
            'PaymentDate' => '2026-02-06 12:20:00',
        ];
        $payload['Signature'] = $this->generatePayoutCallbackSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        // 應回成功響應（ack 三方），訂單 status 保持 22
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(0, $result->response['error_code']);

        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // payout record 不應被結算
        $payoutRecord = WithdrawalPayoutRecord::where('transaction_no', $transactionNo)->first();
        $this->assertNotNull($payoutRecord);
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }

    public function test_missing_ref_id_or_signature_throws(): void
    {
        $request = Request::create('/callback', 'POST', [
            'InvoiceNo' => 'INV-005',
            'Status' => 1,
        ]);
        $handler = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(\App\Exceptions\ApiException::class);
        $this->expectExceptionMessage('BigPayz withdrawal callback missing RefId or Signature');

        $handler->handle($request);
    }
}
