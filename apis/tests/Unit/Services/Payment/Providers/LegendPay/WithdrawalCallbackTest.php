<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\LegendPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\LegendPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * LegendPay 代付回調測試（純二分：'1'=成功24，其餘=失敗29）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/LegendPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private const API_SECRET = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.legendpay.test',
            'API_SECRET' => self::API_SECRET,
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
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

    /**
     * 生成有效的 LegendPay 簽名（與 LegendPayTrait::generateSign 邏輯一致）
     */
    private function generateSign(array $params): string
    {
        $filtered = array_filter($params, fn ($value) => $value !== null && $value !== '');
        unset($filtered['sign']);
        ksort($filtered);

        $sign = urldecode(http_build_query($filtered));
        $sign = $sign . '&key=' . self::API_SECRET;

        return strtoupper(md5($sign));
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
            'amount' => '100.00',
            'commission' => '0.00',
            'message' => 'Success',
            'orderId' => 'LP_ORDER_DEFAULT',
            'utr' => 'LP_UTR_DEFAULT',
            'status' => '1',
        ], $overrides);

        if (!array_key_exists('sign', $overrides)) {
            $data['sign'] = $this->generateSign($data);
        }

        return Request::create('/callback', 'POST', $data);
    }

    /**
     * 測試 status='1' 時，代付訂單更新為三方成功（status 24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/LegendPay/WithdrawalCallbackTest.php --filter test_status_1_updates_order_to_success
     */
    public function test_status_1_updates_order_to_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'LP_PAYOUT_001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'orderId' => $transactionNo,
            'status' => '1',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertNotNull($result->order);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertSame('1', $payoutRecord->result_code);
    }

    /**
     * 測試非 '1' 狀態（'2'）時，代付訂單更新為三方失敗（status 29）
     * doc-literal 二分：LegendPay 無任何非終態碼，非 '1' 一律為終態失敗。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/LegendPay/WithdrawalCallbackTest.php --filter test_non_1_status_marks_29_failure
     */
    public function test_non_1_status_marks_29_failure(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'LP_PAYOUT_002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'orderId' => $transactionNo,
            'status' => '2',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        // doc-literal：非 '1' → FAILURE，訂單標 29
        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertSame('2', $payoutRecord->result_code);
    }

    /**
     * 空憑證守衛：API_SECRET 為空時 generateSign() 的 key 段為空、簽名可離線計算，
     * 攻擊者能偽造回調洗訂單。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'LP_WD_EMPTY';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $emptyInfo = ['API_URL' => 'https://api.legendpay.test', 'API_SECRET' => ''];
        $emptyAcf = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 key 自算簽名
        $data = [
            'amount' => '100.00',
            'commission' => '0.00',
            'message' => 'Success',
            'orderId' => $transactionNo,
            'utr' => 'LP_UTR_EMPTY',
            'status' => '1',
        ];
        $filtered = array_filter($data, fn ($v) => $v !== null && $v !== '');
        ksort($filtered);
        $data['sign'] = strtoupper(md5(urldecode(http_build_query($filtered)) . '&key='));
        $request = Request::create('/callback', 'POST', $data);

        try {
            (new WithdrawalCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
            $this->assertSame('Signature verification failed', $e->getMessage());
        }

        $withdrawalOrder->refresh();
        $this->assertSame(22, $withdrawalOrder->status);
    }
}
