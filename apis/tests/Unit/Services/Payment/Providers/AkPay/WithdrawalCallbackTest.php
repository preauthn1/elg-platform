<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AkPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\AkPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * AkPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalCallbackTest.php
 */
class WithdrawalCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private const MERCHANT_CODE = 'AKTEST';

    private const MERCHANT_TOKEN = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.akpay.test',
            'MERCHANT_TOKEN' => self::MERCHANT_TOKEN,
            'MERCHANT_CODE' => self::MERCHANT_CODE,
            'CURRENCY' => 'MYR',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 150,
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
     * 根據 source_id、tx_id、amount、currency、token 產生合法的 secret_key
     * 與 AkPayTrait::generateSecretKey() 邏輯一致
     * 注意：代付回調的 tx_id 為 string（與代收的 integer 不同）
     */
    private function generateSecretKey(
        string $sourceId,
        string $txId,
        string $amount,
        string $currency,
        string $token
    ): string {
        $encrypt = [
            'source_id' => $sourceId,
            'tx_id' => $txId,
            'amount' => $amount,
            'currency' => $currency,
            'token' => $token,
        ];

        return md5(urldecode(http_build_query($encrypt)));
    }

    /**
     * 建立代付訂單
     */
    private function createWithdrawalOrder(float $amount = 100.00): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => $amount,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);
    }

    /**
     * 建立代付請求記錄
     */
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

    /**
     * 建立回調 Request（自動計算合法 secret_key，傳入 secret_key 時強制覆寫）
     */
    private function createRequest(array $overrides = []): Request
    {
        $sourceId = $overrides['source_id'] ?? (self::MERCHANT_CODE . '_AK_PAYOUT_DEFAULT');
        $txId = $overrides['tx_id'] ?? 'AK_TX_DEFAULT';
        $amount = $overrides['amount'] ?? '100.00';
        $currency = $overrides['currency'] ?? 'MYR';

        $data = array_merge([
            'source_id' => $sourceId,
            'tx_id' => $txId,
            'status' => 'Approved',
            'bank_code' => 'MBB',
            'amount' => $amount,
            'currency' => $currency,
        ], $overrides);

        // 若外部未明確帶入 secret_key，則依最終 data 重新計算
        if (!array_key_exists('secret_key', $overrides)) {
            $data['secret_key'] = $this->generateSecretKey(
                $data['source_id'],
                $data['tx_id'],
                $data['amount'],
                $data['currency'],
                self::MERCHANT_TOKEN,
            );
        }

        return Request::create('/callback', 'POST', $data);
    }

    /**
     * 測試 status=Approved 時，代付訂單更新為三方成功
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalCallbackTest.php --filter test_approved_status_updates_order_to_third_party_success
     */
    public function test_approved_status_updates_order_to_third_party_success(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'AK_PAYOUT_001';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $sourceId = $transactionNo;
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => 'AK_TX_001',
            'status' => 'Approved',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['success' => true], $result->response);
        $this->assertNotNull($result->order);

        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);

        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertSame('Approved', $payoutRecord->result_code);
    }

    /**
     * 測試非 Approved 狀態（Rejected）時，標記為失敗 29（doc-literal）
     * AkPay 文檔無任何非終態碼，非 Approved 一律為終態失敗。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalCallbackTest.php --filter test_rejected_status_marks_29_failure
     */
    public function test_rejected_status_marks_29_failure(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'AK_PAYOUT_002';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $sourceId = $transactionNo;
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => 'AK_TX_002',
            'status' => 'Rejected',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['success' => true], $result->response);

        // doc-literal：非 Approved → FAILURE，訂單標 29
        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        // payout record 應被寫入（FAILURE 走正常流程）
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertSame('Rejected', $payoutRecord->result_code);
    }

    /**
     * 測試非 Approved 狀態（Failed）時，標記為失敗 29（doc-literal）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalCallbackTest.php --filter test_failed_status_marks_29_failure
     */
    public function test_failed_status_marks_29_failure(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'AK_PAYOUT_003';
        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $sourceId = $transactionNo;
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => 'AK_TX_003',
            'status' => 'Failed',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertEquals(['success' => true], $result->response);

        // doc-literal：非 Approved → FAILURE，訂單標 29
        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        // payout record 應被寫入（FAILURE 走正常流程）
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertSame('Failed', $payoutRecord->result_code);
    }

    /**
     * 測試簽名無效時，應拋出 ApiException
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalCallbackTest.php --filter test_invalid_signature_throws_exception
     */
    public function test_invalid_signature_throws_exception(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'AK_PAYOUT_004';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $sourceId = $transactionNo;

        // 強制傳入錯誤的 secret_key，使驗證失敗
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => 'AK_TX_004',
            'status' => 'Approved',
            'amount' => '100.00',
            'secret_key' => 'INVALID_SECRET_KEY',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');

        (new WithdrawalCallback($this->agentCashFlow))->handle($request);
    }

    /**
     * 測試代付請求記錄不存在時，返回失敗響應（不拋出例外）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalCallbackTest.php --filter test_payout_record_not_found_returns_fail_response
     */
    public function test_payout_record_not_found_returns_fail_response(): void
    {
        // 不建立任何 payout record，模擬查無記錄的情境
        $sourceId = self::MERCHANT_CODE . '_AK_PAYOUT_NOT_EXISTS';
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => 'AK_TX_NONE',
            'status' => 'Approved',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['success' => false], $result->response);
    }

    /**
     * 測試訂單已三方成功時重複回調（冪等性），應返回成功響應且狀態不變
     *
     * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalCallbackTest.php --filter test_already_processed_order_returns_success_idempotent
     */
    public function test_already_processed_order_returns_success_idempotent(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);

        // 模擬訂單已三方成功
        $withdrawalOrder->update(['status' => WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value]);

        // 真實重放場景：把訂單推到 24 的 record 自身必然已 SUCCESS
        // （冪等主體 = record 自身；record 仍 PENDING 而訂單 24 屬多 record 吞單缺陷，另有 trait 測試鎖定）
        $transactionNo = 'AK_PAYOUT_005';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo)
            ->update(['is_success_payout' => WithdrawalPayoutStatusEnum::SUCCESS->value]);

        $sourceId = $transactionNo;
        $request = $this->createRequest([
            'source_id' => $sourceId,
            'tx_id' => 'AK_TX_005',
            'status' => 'Approved',
            'amount' => '100.00',
        ]);

        $result = (new WithdrawalCallback($this->agentCashFlow))->handle($request);

        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['success' => true], $result->response);

        // 訂單狀態不應再次變動
        $withdrawalOrder->refresh();
        $this->assertEquals(WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, $withdrawalOrder->status);
    }

    /**
     * 空憑證守衛：MERCHANT_TOKEN 為空時 generateSecretKey() 的 token 段為空、簽名可離線計算，
     * 攻擊者能偽造回調洗訂單。守衛須在驗簽前先擋下（拋 ApiException），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $withdrawalOrder = $this->createWithdrawalOrder(100.00);
        $transactionNo = 'AK_PAYOUT_EMPTY';
        $this->createPayoutRecord($withdrawalOrder->id, $transactionNo);

        $emptyInfo = [
            'API_URL' => 'https://api.akpay.test',
            'MERCHANT_TOKEN' => '',
            'MERCHANT_CODE' => self::MERCHANT_CODE,
            'CURRENCY' => 'MYR',
        ];
        $emptyAcf = AgentCashFlow::create([
            'cash_flow_id' => 150,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 1,
        ]);
        $emptyAcf->info = $emptyInfo;

        // 攻擊者以空 token 自算 secret_key
        $forgedSecret = [REDACTED], 'AK_TX_EMPTY', '100.00', 'MYR', '');
        $request = Request::create('/callback', 'POST', [
            'source_id' => $transactionNo,
            'tx_id' => 'AK_TX_EMPTY',
            'status' => 'Approved',
            'bank_code' => 'MBB',
            'amount' => '100.00',
            'currency' => 'MYR',
            'secret_key' => $forgedSecret,
        ]);

        try {
            (new WithdrawalCallback($emptyAcf))->handle($request);
            $this->fail('空憑證回調應被守衛拒絕');
        } catch (ApiException $e) {
            $this->assertSame('Signature verification failed', $e->getMessage());
        }

        $withdrawalOrder->refresh();
        $this->assertSame(WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value, $withdrawalOrder->status);
    }
}
