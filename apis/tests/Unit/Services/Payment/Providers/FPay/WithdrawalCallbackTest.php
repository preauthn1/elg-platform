<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\FPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * FPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/FPay/WithdrawalCallbackTest.php
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
            'API_URL' => 'https://api.fpay.test',
            'MERCHANT_NO' => 'test_merchant_no',
            'APP_KEY' => 'test_app_key',
            'APP_SECRET' => 'test_app_secret',
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
     * 生成有效 token：md5(APP_SECRET + order_id)
     */
    private function generateToken(string $secret, string $orderId): string
    {
        return md5($secret . $orderId);
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
     * 測試成功代付回調（order_status=completed → status 24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/FPay/WithdrawalCallbackTest.php --filter test_success
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

        $transactionNo = 'FP123456789';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'order_id' => $transactionNo,
            'amount' => '100.00',
            'currency' => 'MYR',
            'order_status' => 'completed',
            'status' => true,
            'charge' => '0.00',
            'token' => $this->generateToken('test_app_secret', $transactionNo),
            'type' => 'withdrawal',
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
     * 測試失敗代付回調（order_status=fail → status 29）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/FPay/WithdrawalCallbackTest.php --filter test_failure_callback
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

        $transactionNo = 'FP123456790';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $callbackData = [
            'order_id' => $transactionNo,
            'amount' => '100.00',
            'currency' => 'MYR',
            'order_status' => 'fail',
            'status' => false,
            'charge' => '0.00',
            'token' => $this->generateToken('test_app_secret', $transactionNo),
            'type' => 'withdrawal',
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
     * 測試 pending 中間態（order_status=proccess → 保持 22，回成功響應，payout 未結算）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/FPay/WithdrawalCallbackTest.php --filter test_indeterminate_keeps_22
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

        $transactionNo = 'FP123456791';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        // 'proccess' 為文件原本拼字（處理中）
        $callbackData = [
            'order_id' => $transactionNo,
            'amount' => '100.00',
            'currency' => 'MYR',
            'order_status' => 'proccess',
            'status' => false,
            'charge' => '0.00',
            'token' => $this->generateToken('test_app_secret', $transactionNo),
            'type' => 'withdrawal',
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
     * 空憑證守衛：APP_SECRET 為空時 generateToken() 退化為 md5(order_id)、可離線計算，
     * 攻擊者能偽造成功回調把在途訂單洗成 24。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 22。
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
        $transactionNo = 'FP_EMPTY_KEY';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $emptyInfo = [
            'API_URL' => 'https://api.fpay.test',
            'MERCHANT_NO' => 'test_merchant_no',
            'APP_KEY' => 'test_app_key',
            'APP_SECRET' => '',
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

        // 攻擊者以空 secret 自算 token = [REDACTED]
        $callbackData = [
            'order_id' => $transactionNo,
            'amount' => '100.00',
            'currency' => 'MYR',
            'order_status' => 'completed',
            'status' => true,
            'charge' => '0.00',
            'token' => md5('' . $transactionNo),
            'type' => 'withdrawal',
        ];
        $request = Request::create('/callback', 'POST', $callbackData);

        try {
            (new WithdrawalCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
        }

        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);
    }
}
