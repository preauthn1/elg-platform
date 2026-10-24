<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\MaxPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\CashFlow;
use App\Models\Member;
use App\Models\OrderRequestLog;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\MaxPay\WithdrawalCallback;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\TestDox;
use Tests\TestCase;

/**
 * MaxPay 代付回調測試
 *
 * 狀態碼依據：MaxPay README §6.2 代付回調狀態碼（工單 #3739）
 * 0=Pending（非終態）、1=Completed（成功終態）、2=Failed（失敗終態）
 * 回調不驗簽，僅靠 IP 白名單。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/WithdrawalCallbackTest.php
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
            'API_URL' => 'https://example.invalid',
            'API_KEY' => 'test_api_key',
            'SECRET_KEY' => 'test_secret_key',
            'CURRENCY' => 'SGD',
        ];

        // 回調來源 IP 白名單（target/tickets#4449 起 fail-closed：沒配＝一律拒絕）。

        // Request::create() 的來源是 0.0.0.0，不放行的話每個案例都會停在防線 1。

        $this->cashFlow = CashFlow::find(180) ?? CashFlow::forceCreate([

            'id' => 180,

            'third_party_code' => 'TEST',

            'name' => 'Test Cash Flow',

            'type' => 3,

            'status' => 1,

            'info' => '',

            'admin' => 0,

        ]);

        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 180,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 1,
        ]);
        $this->agentCashFlow->info = $info;

        $this->agentCashFlow->setRelation('cashFlow', $this->cashFlow);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    private function createWithdrawalOrder(float $amount = 500.00): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'DBS',
            'bank_name' => 'DBS Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => $amount,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
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
            'status_id' => 1,
            'amount' => '500.00',
            'ref_no' => 'MAX_PAYOUT_DEFAULT',
        ], $overrides);

        return Request::create('/callback', 'POST', $data);
    }

    /**
     * status_id=1（Completed）→ 訂單更新為三方成功（24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/WithdrawalCallbackTest.php --filter test_completed_updates_order_to_success
     */
    /**
     * 需求 4（target/tickets#4449）：代付回調只能處理自己渠道的代付請求。
     *
     * 代付的風險比代收高——偽造的失敗回調會讓處理中的提款單先退點給會員，三方之後若真的出款
     * 就是付兩次。MaxPay 原本沒走共用的 findAndLockPayoutRecord()，這道對它一度是空的。
     */
    #[TestDox('代付請求屬於別家金流商時拒絕，提款單狀態不動')]
    public function test_rejects_payout_record_from_another_provider(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'MAX_PAYOUT_XCHAN';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        // 把代付請求改掛到別家金流商的渠道底下，模擬「拿本渠道的回調網址去結算別人的單」
        $otherProvider = CashFlow::create([
            'third_party_code' => 'W_OTHER',
            'name' => 'Other - Withdrawal',
            'type' => 3,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        $otherChannel = AgentCashFlow::create([
            'cash_flow_id' => $otherProvider->id,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
            'info' => json_encode([]),
            'status' => 1,
            'admin' => 0,
        ]);
        $payoutRecord->update(['agent_cash_flow_id' => $otherChannel->id]);

        $statusBefore = $withdrawalOrder->fresh()->status;

        (new WithdrawalCallback($this->agentCashFlow))->handle($this->createRequest([
            'status_id' => 2,
            'amount' => '500.00',
            'ref_no' => $transactionNo,
        ]));

        $this->assertSame($statusBefore, $withdrawalOrder->fresh()->status, '跨渠道的回調不可改動提款單');
    }

    /**
     * 同一家金流商底下的另一筆 ACF 送來的回調要放行：回調網址常由金流商固定寫在商戶後台，
     * 我方重建 ACF 後後台沒跟著改（2026-10-12 TPower FPay：代付紀錄在 272、回調打 264）。
     */
    #[TestDox('代付請求屬於同金流商的另一筆 ACF 時放行')]
    public function test_allows_payout_record_from_another_merchant_of_the_same_provider(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'MAX_PAYOUT_SAME_CF';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $sameProviderChannel = AgentCashFlow::create([
            'cash_flow_id' => $this->cashFlow->id,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
            'rate' => 1.0,
            'info' => json_encode([]),
            'status' => 1,
            'admin' => 0,
        ]);
        $payoutRecord->update(['agent_cash_flow_id' => $sameProviderChannel->id]);

        (new WithdrawalCallback($this->agentCashFlow))->handle($this->createRequest([
            'status_id' => 1,
            'amount' => '500.00',
            'ref_no' => $transactionNo,
        ]));

        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->fresh()->is_success_payout);
    }

    #[TestDox('代付回調被白名單擋下時仍寫入往返紀錄')]
    public function test_blocked_payout_callback_is_recorded(): void
    {
        $this->cashFlow->update(['whitelist' => '0.0.0.0']);

        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'MAX_PAYOUT_BLOCKED';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        (new WithdrawalCallback($this->agentCashFlow))->handle($this->createRequest([
            'status_id' => 1,
            'amount' => '500.00',
            'ref_no' => $transactionNo,
        ]));

        $log = OrderRequestLog::where('order_no', $transactionNo)->latest('id')->first();
        $this->assertNotNull($log, '被擋的代付回調必須留下紀錄');
        $this->assertSame('withdrawal_callback', $log->action);
        $this->assertSame('failed:ip_not_whitelisted', $log->result);
        $this->assertSame('payout_record', $log->orderable_type);
    }

    #[TestDox('代付回調正常處理時寫入往返紀錄並掛到 payout record')]
    public function test_processed_payout_callback_is_recorded(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'MAX_PAYOUT_LOGGED';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        (new WithdrawalCallback($this->agentCashFlow))->handle($this->createRequest([
            'status_id' => 2,
            'amount' => '500.00',
            'ref_no' => $transactionNo,
        ]));

        $log = OrderRequestLog::where('order_no', $transactionNo)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('ok', $log->result);
        $this->assertSame('payout_record', $log->orderable_type);
        $this->assertSame($payoutRecord->id, $log->orderable_id);
        // 入站的 http_status 是我方回給三方的（controller 拿同一個值回應），過去一律留空
        $this->assertSame(200, $log->http_status);
    }

    public function test_completed_updates_order_to_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'MAX_PAYOUT_001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'status_id' => 1,
            'amount' => '500.00',
            'ref_no' => $transactionNo,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    /**
     * status_id=2（Failed）→ 訂單更新為三方失敗（29）
     * 依據：MaxPay README §6.2，2=Failed（失敗終態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/WithdrawalCallbackTest.php --filter test_failed_updates_order_to_failure
     */
    public function test_failed_updates_order_to_failure(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'MAX_PAYOUT_002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'status_id' => 2,
            'amount' => '500.00',
            'ref_no' => $transactionNo,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * status_id=0（Pending，非終態）→ 維持 22、回成功響應（INDETERMINATE）
     * 依據：MaxPay README §6.2，0=Pending（處理中，非終態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/WithdrawalCallbackTest.php --filter test_pending_keeps_22_and_acks
     */
    public function test_pending_keeps_22_and_acks(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'MAX_PAYOUT_003';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'status_id' => 0,
            'amount' => '500.00',
            'ref_no' => $transactionNo,
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertSame('', $payoutRecord->result_code);
    }

    /**
     * payout record 不存在 → 回失敗響應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/MaxPay/WithdrawalCallbackTest.php --filter test_payout_record_not_found_returns_fail_response
     */
    public function test_payout_record_not_found_returns_fail_response(): void
    {
        $request = $this->createRequest([
            'status_id' => 1,
            'amount' => '500.00',
            'ref_no' => 'MAX_PAYOUT_NOT_EXISTS',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }
}
