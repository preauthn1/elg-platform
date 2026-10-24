<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\A3Pay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\A3Pay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * A3Pay 代付回調測試：碼表 4 成功／5 失敗；其餘（如 6）是處理中，必須落 INDETERMINATE。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/A3Pay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    use A3PayCryptoHelpers;

    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
        $this->generateRsaKeyPair();

        $info = $this->buildInfo();

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 132,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create(['parent' => $this->agent->id, 'admin' => 0]);
    }

    private function createOrderWithRecord(string $transactionNo, int $orderStatus = 22): array
    {
        $order = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'DBSSG',
            'bank_name' => 'DBS Bank Limited',
            'account_no' => '0123456789',
            'account_name' => 'Tan Ah Kow',
            'amount' => 100.00,
            'status' => $orderStatus,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $record = WithdrawalPayoutRecord::create([
            'cash_flow_id' => 132,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $order->id,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'W2026092200000001',
            'result_amount' => 0,
            'result_code' => '',
            'is_success_payout' => 0,
            'transaction_time' => '',
            'sign' => '',
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'callback_request_body' => '',
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ]);

        return [$order, $record];
    }

    private function envelope(string $transactionNo, int $status): array
    {
        return $this->buildCallbackEnvelope([
            'MerchantCode' => 'SGD00001',
            'CurrencyCode' => 'SGD',
            'MerchantTransactionId' => $transactionNo,
            'Status' => $status,
            'Amount' => '100.00',
            'NetAmount' => '101.50',
            'Remark' => null,
            'PaymentMethodCode' => 'WD',
        ], ['TransactionId' => 'W2026092200000001']);
    }

    public function test_status_4_marks_order_24(): void
    {
        [$order, $record] = $this->createOrderWithRecord('A3PAY20260922000001');

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3PAY20260922000001', 4)));

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertSame(24, $order->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $record->refresh()->is_success_payout);
    }

    public function test_status_5_marks_order_29(): void
    {
        [$order, $record] = $this->createOrderWithRecord('A3PAY20260922000002');

        (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3PAY20260922000002', 5)));

        $this->assertSame(29, $order->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $record->refresh()->is_success_payout);
    }

    /** 4、5 以外的碼是處理中：必須維持 22、不寫 DB、仍 ack */
    public function test_processing_status_is_indeterminate(): void
    {
        [$order, $record] = $this->createOrderWithRecord('A3PAY20260922000003');

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3PAY20260922000003', 6)));

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertSame(22, $order->refresh()->status);
        $record->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('', $record->result_code);
    }

    public function test_repeated_callback_is_idempotent(): void
    {
        [$order, $record] = $this->createOrderWithRecord('A3PAY20260922000004');
        $callback = new WithdrawalCallback($this->agentCashFlow);

        $callback->handle(Request::create('/callback', 'POST', $this->envelope('A3PAY20260922000004', 4)));
        $second = $callback->handle(Request::create('/callback', 'POST', $this->envelope('A3PAY20260922000004', 5)));

        // 第二次（甚至帶相反狀態）不得改寫已結算的記錄與訂單
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $second);
        $this->assertSame(24, $order->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $record->refresh()->is_success_payout);
    }

    /** 驗簽在 beginTransaction 之前就拋，訂單不會被碰到 */
    public function test_invalid_signature_throws(): void
    {
        $this->createOrderWithRecord('A3PAY20260922000005');
        $payload = $this->envelope('A3PAY20260922000005', 4);
        $payload['Signature'] = base64_encode(random_bytes(64));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid signature');

        (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $payload));
    }

    /** 訂單停在 21（結果未知）時收到成功回調：統一入口放行 21，回調就是定案依據 → 24 */
    public function test_order_21_receiving_status_4_settles_to_24(): void
    {
        [$order, $record] = $this->createOrderWithRecord('A3PAY20260922000006', 21);

        (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->envelope('A3PAY20260922000006', 4)));

        $this->assertSame(24, $order->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $record->refresh()->is_success_payout);
    }

    /** 金額閘門在 beginTransaction 之前就拋，訂單不會被碰到 */
    public function test_non_numeric_amount_is_rejected(): void
    {
        $this->createOrderWithRecord('A3PAY20260922000007');
        $payload = $this->buildCallbackEnvelope([
            'MerchantTransactionId' => 'A3PAY20260922000007', 'Status' => 4, 'Amount' => 'abc', 'CurrencyCode' => 'SGD',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid callback amount');

        (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $payload));
    }

    /** 真實成功回調（dev 提款單 705，Status 4）→ 24 */
    public function test_real_success_callback_settles_to_24(): void
    {
        $tx = A3PayRealPayloads::PAYOUT_CALLBACK_SUCCESS['MerchantTransactionId'];
        [$order, $record] = $this->createOrderWithRecord($tx);
        $payload = $this->buildCallbackEnvelope(A3PayRealPayloads::PAYOUT_CALLBACK_SUCCESS, ['TransactionId' => 'W2026092400000014']);
        $payload['Amount'] = null;
        unset($payload['Amount']);

        (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $payload));

        $this->assertSame(24, $order->refresh()->status);
        $record->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $record->is_success_payout);
        $this->assertSame('4', (string) $record->result_code);
    }

    /** 真實取消回調（dev 提款單 700，Status 5，Remark test）→ 29 */
    public function test_real_cancelled_callback_settles_to_29(): void
    {
        $tx = A3PayRealPayloads::PAYOUT_CALLBACK_CANCELLED['MerchantTransactionId'];
        [$order, $record] = $this->createOrderWithRecord($tx);

        (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $this->buildCallbackEnvelope(A3PayRealPayloads::PAYOUT_CALLBACK_CANCELLED, ['TransactionId' => 'W2026092400000010'])));

        $this->assertSame(29, $order->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $record->refresh()->is_success_payout);
    }
}
