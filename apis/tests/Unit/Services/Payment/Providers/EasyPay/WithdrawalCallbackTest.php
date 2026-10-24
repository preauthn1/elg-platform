<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\EasyPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\EasyPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * EasyPay 代付回調測試（三態：COMPLETE=成功24、REJECT=失敗29、PENDING/未知=維持處理中22）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private CashFlow $cashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.easypay.test',
        ];

        // 回調來源 IP 白名單（target/tickets#4449 起 fail-closed：沒配＝一律拒絕）。

        // Request::create() 的來源是 0.0.0.0，不放行的話每個案例都會停在防線 1。

        $this->cashFlow = CashFlow::find(1) ?? CashFlow::forceCreate([

            'id' => 1,

            'third_party_code' => 'TEST',

            'name' => 'Test Cash Flow',

            'type' => 3,

            'status' => 1,

            'info' => '',

            'admin' => 0,

        ]);

        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $this->agentCashFlow->setRelation('cashFlow', $this->cashFlow);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createWithdrawalOrder(float $amount = 100.00): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => $amount,
            'status' => 22, // 代付處理中
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => '',
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
    }

    private function createRequest(array $overrides = []): Request
    {
        $data = array_merge([
            'tx_id' => 'EP_TX_DEFAULT',
            'status' => 'COMPLETE',
            'order_id' => 'EP_ORDER_DEFAULT',
            'final_amount' => '100.00',
            'order_amount' => '100.00',
            'service_change' => '0.00',
        ], $overrides);

        return Request::create('/callback', 'POST', $data);
    }

    /**
     * 測試 status=COMPLETE 時，代付訂單更新為三方成功（status 24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/WithdrawalCallbackTest.php --filter test_complete_status_updates_order_to_success
     */
    public function test_complete_status_updates_order_to_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'EP_PAYOUT_001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'tx_id' => $transactionNo,
            'status' => 'COMPLETE',
            'order_amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertNotNull($result->order);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertSame('COMPLETE', $payoutRecord->result_code);
    }

    /**
     * 測試 status=REJECT（官方失敗終態碼）時，代付訂單更新為三方失敗（status 29）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/WithdrawalCallbackTest.php --filter test_reject_status_marks_29_failure
     */
    public function test_reject_status_marks_29_failure(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'EP_PAYOUT_002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'tx_id' => $transactionNo,
            'status' => 'REJECT',
            'order_amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // REJECT → FAILURE，訂單標 29
        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertSame('REJECT', $payoutRecord->result_code);
    }

    /**
     * 測試 status=PENDING（非終態）時，訂單維持處理中（status 22），不寫入 payoutRecord，
     * 避免把「處理中」誤判為失敗而退款、與三方事後成功打款造成雙重出款。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/WithdrawalCallbackTest.php --filter test_pending_status_keeps_processing_22
     */
    public function test_pending_status_keeps_processing_22(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'EP_PAYOUT_003';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'tx_id' => $transactionNo,
            'status' => 'PENDING',
            'order_amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // PENDING → INDETERMINATE，訂單維持 22、payoutRecord 不被寫入（保留接收後續終態回調的能力）
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertSame('', $payoutRecord->result_code);
    }

    /**
     * 測試未知 status（非官方枚舉碼）時，保守落入 INDETERMINATE，維持 22 等待人工/後續回調。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EasyPay/WithdrawalCallbackTest.php --filter test_unknown_status_keeps_processing_22
     */
    public function test_unknown_status_keeps_processing_22(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'EP_PAYOUT_004';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'tx_id' => $transactionNo,
            'status' => 'FAILED',
            'order_amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // 未知碼 → INDETERMINATE，維持 22（不武斷判失敗）
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);
    }
}
