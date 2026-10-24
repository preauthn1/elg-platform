<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\SurePay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\SurePay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * SurePay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/SurePay/WithdrawalCallbackTest.php
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
            'API_URL' => 'https://api.surepay.test',
            'MERCHANT_ID' => 'SPREE009Tm99s',
            'CONTRACT_NAME' => 'test_contract',
            'API_KEY' => 'test_api_key',
            'CALLBACK_API_KEY' => 'test_callback_api_key',
            'CURRENCY' => 'MYR',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->save();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 生成 SurePay callback token：md5(merchant + amount + status + CALLBACK_API_KEY + trxno)
     */
    private function generateCallbackToken(string $merchant, string $amount, string $status, string $callbackApiKey, string $trxno): string
    {
        return md5($merchant . $amount . $status . $callbackApiKey . $trxno);
    }

    /**
     * 創建測試用 WithdrawalPayoutRecord
     */
    private function createPayoutRecord(int $withdrawalOrderId, string $transactionNo, array $overrides = []): WithdrawalPayoutRecord
    {
        return WithdrawalPayoutRecord::create(array_merge([
            'cash_flow_id' => $this->agentCashFlow->cash_flow_id ?? 1,
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'withdrawal_order_id' => $withdrawalOrderId,
            'transaction_no' => $transactionNo,
            'third_party_transaction_no' => 'DEFAULT_TPO_' . $transactionNo,
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
        ], $overrides));
    }

    /**
     * 測試成功代付回調（status=1 → order status 24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/SurePay/WithdrawalCallbackTest.php --filter test_success
     */
    public function test_success(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $transactionNo = 'Sure2026010100001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $merchant = 'SPREE009Tm99s';
        $amount = '100.00';
        $status = '1';
        $trxno = '92256687';

        $callbackData = [
            'amount' => $amount,
            'trxno' => $trxno,
            'currency' => 'MYR',
            'customer' => 'testuser',
            'status' => $status,
            'status_message' => 'success',
            'token' => $this->generateCallbackToken($merchant, $amount, $status, 'test_callback_api_key', $trxno),
            'merchant' => $merchant,
            'refid' => $transactionNo,
            'clientip' => '0.0.0.0',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
    }

    /**
     * 測試失敗代付回調（status=-1 → order status 29）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/SurePay/WithdrawalCallbackTest.php --filter test_failure_callback
     */
    public function test_failure_callback(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $transactionNo = 'Sure2026010100002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $merchant = 'SPREE009Tm99s';
        $amount = '100.00';
        $status = '-1';
        $trxno = '92256688';

        $callbackData = [
            'amount' => $amount,
            'trxno' => $trxno,
            'currency' => 'MYR',
            'customer' => 'testuser',
            'status' => $status,
            'status_message' => 'test txn failed',
            'token' => $this->generateCallbackToken($merchant, $amount, $status, 'test_callback_api_key', $trxno),
            'merchant' => $merchant,
            'refid' => $transactionNo,
            'clientip' => '0.0.0.0',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * 測試未知狀態中間態（status=0 → 保持 22，回成功響應，payout 未結算）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/SurePay/WithdrawalCallbackTest.php --filter test_indeterminate_keeps_22
     */
    public function test_indeterminate_keeps_22(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $transactionNo = 'Sure2026010100003';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $merchant = 'SPREE009Tm99s';
        $amount = '100.00';
        $status = '0'; // 未知狀態 → INDETERMINATE
        $trxno = '92256689';

        $callbackData = [
            'amount' => $amount,
            'trxno' => $trxno,
            'currency' => 'MYR',
            'customer' => 'testuser',
            'status' => $status,
            'status_message' => 'pending',
            'token' => $this->generateCallbackToken($merchant, $amount, $status, 'test_callback_api_key', $trxno),
            'merchant' => $merchant,
            'refid' => $transactionNo,
            'clientip' => '0.0.0.0',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        // INDETERMINATE → 回成功響應 ack 三方
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // 訂單狀態保持 22
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // payout record 不應被結算
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }

    /**
     * 空憑證守衛：CALLBACK_API_KEY 為空時 generateCallbackToken() 的 key 段為空、
     * token 可離線計算，攻擊者能偽造回調洗訂單。守衛須在驗 token 前先擋下（回 fail），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
        $transactionNo = 'Sure_WD_EMPTY';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $emptyInfo = [
            'API_URL' => 'https://api.surepay.test',
            'MERCHANT_ID' => 'SPREE009Tm99s',
            'CONTRACT_NAME' => 'test_contract',
            'API_KEY' => 'test_api_key',
            'CALLBACK_API_KEY' => '',
            'CURRENCY' => 'MYR',
        ];
        $emptyAcf = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 CALLBACK_API_KEY 自算 token
        $merchant = 'SPREE009Tm99s';
        $amount = '100.00';
        $status = '1';
        $trxno = '92256687';
        $forgedToken = [REDACTED] . $amount . $status . '' . $trxno);
        $callbackData = [
            'amount' => $amount,
            'trxno' => $trxno,
            'currency' => 'MYR',
            'customer' => 'testuser',
            'status' => $status,
            'status_message' => 'success',
            'token' => $forgedToken,
            'merchant' => $merchant,
            'refid' => $transactionNo,
            'clientip' => '0.0.0.0',
        ];
        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new WithdrawalCallback($emptyAcf))->handle($request);

        $this->assertSame('fail', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);
    }
}
