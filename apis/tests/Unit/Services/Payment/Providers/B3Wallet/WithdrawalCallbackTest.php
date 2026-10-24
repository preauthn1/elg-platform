<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\B3Wallet;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\OrderRequestLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Enums\OrderRequestActionEnum;
use App\Services\Payment\Enums\OrderRequestResultEnum;
use App\Services\Payment\Providers\B3Wallet\WithdrawalCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * B3 Wallet 代付回調測試
 *
 * B3 Wallet 回調本身無簽名欄位，正確性一律以 GET /payouts/{payoutId} 查詢接口二次確認為準。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/B3Wallet/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = Agent::factory()->create();

        $this->currency = Currency::firstOrCreate(['code' => 'SGD'], [
            'name' => 'Singapore Dollar',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $info = [
            'API_URL' => 'https://www.judy88.xin',
            'PAYMENT_KEY' => 'test_payment_key',
            'PAYMENT_PASS' => 'test_payment_pass',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
            'name' => 'B3 Wallet',
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

    private function createWithdrawalOrder(float $amount = 88.8, int $status = 22): WithdrawalOrder
    {
        $bank = Bank::firstOrCreate(['code' => 'DBS'], [
            'name' => 'DBS Bank',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'DBS',
            'bank_name' => 'DBS Bank',
            'account_no' => '12345678',
            'account_name' => 'Alice',
            'amount' => $amount,
            'status' => $status,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }

    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create([
            'cash_flow_id' => 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'payout-' . $transactionNo,
            'payout_order_request_body' => '',
            'payout_order_response' => '',
            'result_amount' => 0,
            'result_code' => '',
            'transaction_time' => now()->toDateTimeString(),
            'sign' => '',
            'callback_request_body' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::PENDING->value,
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'admin' => 0,
        ]);
    }

    private function callbackRequest(string $transactionNo, float $amount): array
    {
        return [
            'PayoutId' => 'payout-' . $transactionNo,
            'Amount' => $amount,
            'Paid' => false,
            'PayoutMethod' => 'BANK_TRANSFER',
            'RecipientAccount' => '12345678',
            'RecipientBank' => 'DBS',
            'Param' => $transactionNo,
            'Error' => null,
        ];
    }

    /**
     * 正常成功回調（查詢接口確認 PAID），訂單更新為三方成功
     */
    public function test_success_callback_updates_order(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake([
            '*' => Http::response([
                'PayoutId' => 'payout-' . $transactionNo,
                'Status' => 'PAID',
                'Amount' => 88.8,
            ], 200),
        ]);

        $request = Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8));

        $callback = new WithdrawalCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertSame(24, $withdrawalOrder->status); // THIRD_PARTY_SUCCESS

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    /**
     * 支付商成本一律依 agent_cash_flows.cost 計算，三方回傳的 FeeAmount 不採用。
     *
     * 三方帶的費率是它自己的帳，我方成本以合約費率（渠道 cost）為準，與代收同一套口徑；
     * 兩邊算法不一致會讓同一家金流商的利潤分成對不起來。
     */
    public function test_success_callback_ignores_third_party_fee_and_uses_channel_cost(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake([
            '*' => Http::response([
                'PayoutId' => 'payout-' . $transactionNo,
                'Status' => 'PAID',
                'Amount' => 88.8,
                'FeeAmount' => 1.23,
                'TotalDeductAmount' => 90.03,
            ], 200),
        ]);

        $payload = $this->callbackRequest($transactionNo, 88.8) + ['FeeAmount' => 1.23];
        $request = Request::create('/callback', 'POST', $payload);

        (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $withdrawalOrder->refresh();
        $this->assertSame($withdrawalOrder->paymentCostVia(), (string) $withdrawalOrder->payment_cost);
    }

    /**
     * 代付失敗（查詢接口 FAILED）時，紀錄標記回調失敗、訂單轉三方失敗。
     *
     * 取自 Dev 2026-10-05 QA 實測（紀錄 4955）與 TPower 2026-10-22 QA 實測（紀錄 1884243），兩次一致：
     * 回調帶 Paid=false、Error="1"、`PayNowProxyType=null`，查詢接口回 FAILED 且多帶 PaidAt／Sandbox。
     * ⚠️ Error 實際回的是字串 "1" 而非文件所述的失敗原因描述，故不可拿它當訊息直接顯示給客服。
     */
    public function test_failed_callback_marks_payout_failed(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake([
            '*' => Http::response([
                'Amount' => 50,
                'Currency' => 'SGD',
                'Date' => '2026-10-22T03:17:10.718Z',
                'Error' => '1',
                'FeeAmount' => 0.8,
                'FeeRate' => 0.016,
                'Paid' => false,
                'PaidAt' => null,
                'PayNowProxy' => null,
                'PayNowProxyType' => null,
                'PaymentMethod' => null,
                'PayoutId' => 'payout-' . $transactionNo,
                'PayoutMethod' => 'BANK_TRANSFER',
                'RecipientAccount' => '12345678',
                'RecipientBank' => 'DBS',
                'Sandbox' => false,
                'Status' => 'FAILED',
                'TotalDeductAmount' => 50.8,
            ], 200),
        ]);

        // `+` 左邊優先，Error 要用 array_merge 才蓋得掉 callbackRequest() 的 null
        $payload = array_merge($this->callbackRequest($transactionNo, 50), [
            'Paid' => false,
            'Error' => '1',
            'PayNowProxyType' => null,
            'FeeAmount' => 0.8,
            'FeeRate' => 0.016,
            'TotalDeductAmount' => 50.8,
        ]);
        $request = Request::create('/callback', 'POST', $payload);

        (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);

        $withdrawalOrder->refresh();
        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->status);

        // 失敗不寫支付商成本：錢沒出去就沒有手續費
        $this->assertSame('0.0000', (string) $withdrawalOrder->payment_cost);
    }

    /**
     * 查詢接口回傳仍在處理中（無簽名，不可只信回調），維持 PAYMENT_PROCESSING(22) 不 finalize
     */
    public function test_query_indeterminate_keeps_processing_status(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake([
            '*' => Http::response([
                'PayoutId' => 'payout-' . $transactionNo,
                'Status' => 'PROCESSING',
                'Amount' => 88.8,
            ], 200),
        ]);

        $request = Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8));

        $callback = new WithdrawalCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertSame(22, $withdrawalOrder->status); // 維持 PAYMENT_PROCESSING
    }

    /**
     * 重複回調（冪等性防護），已是三方成功時不重複處理
     */
    public function test_duplicate_callback_is_idempotent(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(status: 24); // 已是 THIRD_PARTY_SUCCESS
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake(); // 防呆：若冪等短路失效誤呼叫查詢接口，也不致真的打網路

        $request = Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8));

        $callback = new WithdrawalCallback($this->agentCashFlow);
        $result = $callback->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // 冪等分支應直接回應，不應再呼叫查詢接口
        Http::assertNothingSent();
    }

    /**
     * 查單在交易外：打查單那一刻的交易層數必須等於進 handle() 之前（測試本身那一層）。
     */
    public function test_query_runs_outside_transaction(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);
        $baseline = DB::transactionLevel();
        $levelDuringQuery = null;

        Http::fake(function () use ($transactionNo, &$levelDuringQuery) {
            $levelDuringQuery = DB::transactionLevel();

            return Http::response(['PayoutId' => 'payout-' . $transactionNo, 'Status' => 'PAID', 'Amount' => 88.8], 200);
        });

        (new WithdrawalCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8)));

        $this->assertSame($baseline, $levelDuringQuery);
        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->refresh()->status);
    }

    /**
     * 回調帶的 PayoutId 不是建單時存下的那筆：拒絕，不查單、不動訂單。
     *
     * 回調無簽名，不擋的話可以拿同商戶另一筆已付款的代付，把這筆沒出款的提款單標成三方成功。
     */
    public function test_callback_payout_id_not_matching_stored_is_rejected(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake();

        $payload = array_merge($this->callbackRequest($transactionNo, 88.8), ['PayoutId' => 'someone-elses-paid-payout']);
        (new WithdrawalCallback($this->agentCashFlow))->handle(Request::create('/callback', 'POST', $payload));

        Http::assertNothingSent();
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->refresh()->is_success_payout);
        $this->assertSame(
            OrderRequestResultEnum::QUERY_ANOTHER_ORDER->value,
            OrderRequestLog::where('order_no', $transactionNo)->value('result')
        );
    }

    /**
     * 建單沒存到 PayoutId（建單響應遺失）時退回用回調的值，但它不可已屬於別筆代付請求。
     */
    public function test_without_stored_payout_id_rejects_id_claimed_by_another_record(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);
        $payoutRecord->update(['third_party_transaction_no' => '']);

        $otherOrder = $this->createWithdrawalOrder();
        $this->createPayoutRecord($otherOrder->id, 'OTHER' . $otherOrder->id)
            ->update(['third_party_transaction_no' => 'payout-' . $transactionNo]);

        Http::fake();

        (new WithdrawalCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8)));

        Http::assertNothingSent();
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->refresh()->status);
    }

    /**
     * 每次回調都寫一筆往返紀錄，掛在那筆代付請求上。
     */
    public function test_callback_writes_order_request_log(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake(['*' => Http::response(['PayoutId' => 'payout-' . $transactionNo, 'Status' => 'FAILED', 'Amount' => 88.8], 200)]);

        (new WithdrawalCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8)));

        $log = OrderRequestLog::where('order_no', $transactionNo)->sole();
        $this->assertSame(OrderRequestActionEnum::WITHDRAWAL_CALLBACK->value, $log->action);
        $this->assertSame(OrderRequestResultEnum::OK->value, $log->result);
        $this->assertSame($payoutRecord->id, $log->orderable_id);
        $this->assertSame('payout-' . $transactionNo, $log->third_party_order_no);
    }

    /**
     * 查單打不通：回 HTTP 500 讓三方重送，提款單維持 22。
     */
    public function test_query_failure_returns_500_for_retry(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder();
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        Http::fake(['*' => Http::response('Bad Gateway', 502)]);

        $result = (new WithdrawalCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8)));

        $this->assertSame(500, $result->httpStatusCode);
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->refresh()->status);
    }

    /**
     * 建單響應遺失（沒存到 PayoutId、停在代付提交中 21）時，回調仍是定案依據（target/tickets#4137）：
     * 回調的 PayoutId 沒被別筆佔用，就用它查單定案。
     */
    public function test_without_stored_payout_id_still_finalizes_submitted_order(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(status: WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value);
        $transactionNo = 'TRN' . $withdrawalOrder->id;
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);
        $payoutRecord->update(['third_party_transaction_no' => '']);

        Http::fake(['*' => Http::response(['PayoutId' => 'payout-' . $transactionNo, 'Status' => 'PAID', 'Amount' => 88.8], 200)]);

        (new WithdrawalCallback($this->agentCashFlow))
            ->handle(Request::create('/callback', 'POST', $this->callbackRequest($transactionNo, 88.8)));

        $this->assertSame(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->refresh()->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->refresh()->is_success_payout);
    }
}
