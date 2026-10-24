<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\EpicPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\EpicPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * EpicPay 代付回調測試（純二分：'1'=成功24，其餘=失敗29）
 *
 * php artisan test tests/Unit/Services/Payment/Providers/EpicPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private const MERCHANT_CODE = 'EPICTEST';

    private const SECRET_KEY = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.epicpay.test',
            'MERCHANT_CODE' => self::MERCHANT_CODE,
            'SECRET_KEY' => self::SECRET_KEY,
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
     * Format: md5(<MERCHANT CODE>-<SECRET KEY>-<TRANSACTION ID>-<AMOUNT>-<TIMESTAMP TS>)
     */
    private function generateToken(string $id, string $amount, string $ts): string
    {
        return md5(self::MERCHANT_CODE . '-' . self::SECRET_KEY . '-' . $id . '-' . $amount . '-' . $ts);
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
        $id = $overrides['id'] ?? 'EPIC_TX_DEFAULT';
        $amount = $overrides['amount'] ?? '100.00';
        $ts = $overrides['ts'] ?? '1700000000';

        $data = array_merge([
            'id' => $id,
            'status' => '1',
            'amount' => $amount,
            'fee' => '0.00',
            'message' => 'Success',
            'ts' => $ts,
        ], $overrides);

        if (!array_key_exists('token', $overrides)) {
            $data['token'] = $this->generateToken($data['id'], $data['amount'], $data['ts']);
        }

        return Request::create('/callback', 'POST', $data);
    }

    /**
     * 測試 status='1' 時，代付訂單更新為三方成功（status 24）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EpicPay/WithdrawalCallbackTest.php --filter test_status_1_updates_order_to_success
     */
    public function test_status_1_updates_order_to_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'EPIC_PAYOUT_001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'id' => $transactionNo,
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
     * #4137：停留 21（代付請求中）的訂單收到成功回調，必須放行並自動定案 24
     *（provider 端到端驗證新放行集合，不只靠共用 helper 單測）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EpicPay/WithdrawalCallbackTest.php --filter test_submitted_order_status_1_resolves_to_success
     */
    public function test_submitted_order_status_1_resolves_to_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        WithdrawalOrder::query()->whereKey($withdrawalOrder->id)->update(['status' => 21]);
        $transactionNo = 'EPIC_PAYOUT_021';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'id' => $transactionNo,
            'status' => '1',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->refresh()->is_success_payout);
    }

    /**
     * 測試非 '1' 狀態（'2'）時，代付訂單更新為三方失敗（status 29）
     * doc-literal 二分：EpicPay 無任何非終態碼，非 '1' 一律為終態失敗。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/EpicPay/WithdrawalCallbackTest.php --filter test_non_1_status_marks_29_failure
     */
    public function test_non_1_status_marks_29_failure(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'EPIC_PAYOUT_002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest([
            'id' => $transactionNo,
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
     * 空憑證守衛：SECRET_KEY 為空時 generateCallbackToken() 的 secret 段為空、
     * 簽名可離線計算，攻擊者能自算「有效」token 偽造成功回調把在途訂單洗成 24。
     * 守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'EPIC_EMPTY_KEY';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $emptyInfo = [
            'API_URL' => 'https://api.epicpay.test',
            'MERCHANT_CODE' => self::MERCHANT_CODE,
            'SECRET_KEY' => '',
        ];
        $emptyAcf = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 secret 自算 token
        $forgedToken = [REDACTED] . '-' . '' . '-' . $transactionNo . '-100.00-1700000000');
        $request = Request::create('/callback', 'POST', [
            'id' => $transactionNo,
            'status' => '1',
            'amount' => '100.00',
            'fee' => '0.00',
            'message' => 'Success',
            'ts' => '1700000000',
            'token' => $forgedToken,
        ]);

        try {
            (new WithdrawalCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
            // 預期：守衛在驗簽前硬拒
        }

        $withdrawalOrder->refresh();
        $this->assertSame(22, $withdrawalOrder->status);
    }
}
