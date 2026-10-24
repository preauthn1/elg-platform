<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AntzPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\AntzPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AntzPay 代付回調測試
 *
 * 狀態碼依據：AntzPay README §11 回調 status 表、§9.2 代付回調狀態判定
 * SUCCESS=成功終態、FAILED=失敗終態、PENDING=非終態（INDETERMINATE）
 * 回調 body 為 AES-256-CBC 加密的 encryptedData。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AntzPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private const SYMMETRIC_KEY = '12345678901234567890123456789012'; // 32 bytes

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => 'test_secret',
            'SERVER_PUBLIC_KEY' => '',
            'CLIENT_SYMMETRIC_KEY' => self::SYMMETRIC_KEY,
            'CLIENT_PRIVATE_KEY' => '',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 170,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 1,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 以 CLIENT_SYMMETRIC_KEY 加密 callback 資料，與 decryptCallbackData() 邏輯相同
     */
    private function encryptCallbackData(array $data): string
    {
        $key = self::SYMMETRIC_KEY;
        $iv = md5($key, true);
        $json = json_encode($data);
        $encrypted = openssl_encrypt($json, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        return bin2hex($encrypted);
    }

    private function createWithdrawalOrder(float $amount = 500.00): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'KASITHBK',
            'bank_name' => 'Kasikornbank',
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

    private function buildCallbackPayload(string $transactionNo, string $status, float $amount = 500.00): array
    {
        return [
            'message' => 'Success',
            'data' => [
                'status' => $status,
                'transaction' => [
                    'id' => 10025,
                    'amountWithdraw' => $amount,
                    'confirmedAt' => time(),
                    'commissionFee' => 5.00,
                    'remarks' => 'Success',
                ],
                'order' => [
                    'id' => $transactionNo,
                    'amount' => (string) $amount,
                    'currencyType' => 'THB',
                ],
                'recipient' => [
                    'name' => 'Test User',
                    'phone' => '0123456789',
                    'email' => 'user@example.invalid',
                    'methodType' => 'KASITHBK',
                    'methodValue' => '1234567890',
                    'methodRef' => '',
                ],
            ],
        ];
    }

    private function createRequest(string $transactionNo, string $status, float $amount = 500.00): Request
    {
        $payload = $this->buildCallbackPayload($transactionNo, $status, $amount);
        $encryptedData = $this->encryptCallbackData($payload);

        return Request::create('/callback', 'POST', ['encryptedData' => $encryptedData]);
    }

    /**
     * status=SUCCESS → 訂單更新為三方成功（24）
     * 依據：AntzPay README §11 / §9.2，SUCCESS=代付成功終態
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AntzPay/WithdrawalCallbackTest.php --filter test_success_updates_order_to_third_party_success
     */
    public function test_success_updates_order_to_third_party_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'ANTZ_PAYOUT_001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest($transactionNo, 'SUCCESS', 500.00);

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
     * status=FAILED → 訂單更新為三方失敗（29）
     * 依據：AntzPay README §11 / §9.2，FAILED=代付失敗終態
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AntzPay/WithdrawalCallbackTest.php --filter test_failed_updates_order_to_third_party_failed
     */
    public function test_failed_updates_order_to_third_party_failed(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'ANTZ_PAYOUT_002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest($transactionNo, 'FAILED', 500.00);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_FAILED->value, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
    }

    /**
     * status=PENDING（非終態）→ 維持 22、回 OK ack（INDETERMINATE）
     * 依據：AntzPay README §11，PENDING=待處理，不終結訂單
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AntzPay/WithdrawalCallbackTest.php --filter test_pending_keeps_22_and_acks
     */
    public function test_pending_keeps_22_and_acks(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(500.00);
        $transactionNo = 'ANTZ_PAYOUT_003';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $request = $this->createRequest($transactionNo, 'PENDING', 500.00);

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
     * php artisan test tests/Unit/Services/Payment/Providers/AntzPay/WithdrawalCallbackTest.php --filter test_payout_record_not_found_returns_fail_response
     */
    public function test_payout_record_not_found_returns_fail_response(): void
    {
        $request = $this->createRequest('ANTZ_PAYOUT_NOT_EXISTS', 'SUCCESS');

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }
}
