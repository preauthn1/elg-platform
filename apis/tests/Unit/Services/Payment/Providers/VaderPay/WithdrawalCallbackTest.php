<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VaderPay;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\VaderPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * VaderPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VaderPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'merchant01',
            'API_KEY' => 'test_api_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 202,
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
            'account_no' => '123456789',
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
            'change_logs' => '',
        ]);
    }

    private function createPayoutRecord(WithdrawalOrder $order, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 202,
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

    private function generateSignature(array $payload, string $apiKey): string
    {
        $stringToSign = $payload['InvoiceNo']
            . $payload['Status']
            . $payload['Currency']
            . $payload['Amount']
            . $payload['PaymentDate'];

        return hash_hmac('sha256', $stringToSign, $apiKey);
    }

    public function test_success(): void
    {
        $transactionNo = 'VDW20240206001';
        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);
        $this->createPayoutRecord($withdrawalOrder, $transactionNo);

        $payload = [
            'InvoiceNo' => 'INV-WD-0001',
            'BeneficiaryAccount' => '123456789',
            'BeneficiaryBankCode' => 'CIMB',
            'BeneficiaryName' => 'Test User',
            'SubmissionDate' => '2026-02-06 12:10:00',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'MerchantCode' => 'merchant01',
            'RefId' => $transactionNo,
            'Currency' => 'MYR',
            'Amount' => '5000.00',
            'PaymentDate' => '2026-02-06 12:11:00',
        ];

        $payload['Signature'] = $this->generateSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(0, $result->response['error_code']);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
        $this->assertEquals('INV-WD-0001', $withdrawalOrder->third_party_order_no);

        $payoutRecord = WithdrawalPayoutRecord::where('transaction_no', $transactionNo)->first();
        $this->assertEquals($payload['PaymentDate'], $payoutRecord->info_2);
    }

    public function test_pending_status_returns_success_response(): void
    {
        $transactionNo = 'VDW20240206002';
        $withdrawalOrder = $this->createWithdrawalOrder('5000.00', 22);
        $this->createPayoutRecord($withdrawalOrder, $transactionNo);

        $payload = [
            'InvoiceNo' => 'INV-WD-0002',
            'BeneficiaryAccount' => '123456789',
            'BeneficiaryBankCode' => 'CIMB',
            'BeneficiaryName' => 'Test User',
            'SubmissionDate' => '2026-02-06 12:12:00',
            'Status' => 0,
            'StatusMessage' => 'Pending',
            'MerchantCode' => 'merchant01',
            'RefId' => $transactionNo,
            'Currency' => 'MYR',
            'Amount' => '5000.00',
            'PaymentDate' => '2026-02-06 12:13:00',
        ];

        $payload['Signature'] = $this->generateSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(0, $result->response['error_code']);

        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);
    }

    public function test_invalid_signature(): void
    {
        $payload = [
            'InvoiceNo' => 'INV-WD-0003',
            'BeneficiaryAccount' => '123456789',
            'BeneficiaryBankCode' => 'CIMB',
            'BeneficiaryName' => 'Test User',
            'SubmissionDate' => '2026-02-06 12:14:00',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'MerchantCode' => 'merchant01',
            'RefId' => 'VDW20240206003',
            'Currency' => 'MYR',
            'Amount' => '5000.00',
            'PaymentDate' => '2026-02-06 12:15:00',
            'Signature' => 'INVALID',
        ];

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('VaderPay withdrawal callback signature verification failed');

        $handler->handle($request);
    }

    public function test_record_not_found_returns_fail_response(): void
    {
        $payload = [
            'InvoiceNo' => 'INV-WD-0004',
            'BeneficiaryAccount' => '123456789',
            'BeneficiaryBankCode' => 'CIMB',
            'BeneficiaryName' => 'Test User',
            'SubmissionDate' => '2026-02-06 12:16:00',
            'Status' => 1,
            'StatusMessage' => 'Success',
            'MerchantCode' => 'merchant01',
            'RefId' => 'VDW_NOT_FOUND',
            'Currency' => 'MYR',
            'Amount' => '5000.00',
            'PaymentDate' => '2026-02-06 12:17:00',
        ];

        $payload['Signature'] = $this->generateSignature($payload, 'test_api_key');

        $request = Request::create('/callback', 'POST', $payload);
        $handler = new WithdrawalCallback($this->agentCashFlow);
        $result = $handler->handle($request);

        $this->assertEquals(-1, $result->response['error_code']);
        $this->assertNull($result->order);
    }
}
