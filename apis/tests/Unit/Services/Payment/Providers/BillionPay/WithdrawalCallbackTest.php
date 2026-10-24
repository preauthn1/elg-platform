<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BillionPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\BillionPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * BillionPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php
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
            'SECRET_KEY' => 'test_secret_key',
            'USERNAME' => 'test_username',
            'PASSWORD' => 'test_password',
            'LOGIN_URL' => 'https://example.invalid/api/login',
            'DEPOSIT_URL' => 'https://example.invalid/api/deposit',
            'WITHDRAWAL_URL' => 'https://example.invalid/api/withdrawal',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'VIETCOMBANK'],
                ['BankCode_ELG' => '002', 'BankCode_Third' => 'TECHCOMBANK'],
            ],
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
     * 生成有效的 BillionPay 簽名（與 BillionPayTrait::generateSign 相同邏輯）
     *
     * 簽名規則：
     * 1. 排除 signature 欄位
     * 2. 排除值為 null、''、0、'0' 的參數
     * 3. 對參數進行 ASCII 升序排序
     * 4. json_encode 後末尾拼接 secret key
     * 5. MD5 加密（小寫）
     */
    private function generateValidSign(array $params, string $secret): string
    {
        unset($params['signature']);

        $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true));

        ksort($filtered);

        $signString = str_replace('//', '\\/\\/', json_encode($filtered) . $secret);

        return md5($signString);
    }

    /**
     * 創建測試用的 WithdrawalPayoutRecord
     */
    private function createPayoutRecord(
        int $withdrawalOrderId,
        string $transactionNo,
        array $overrides = []
    ): WithdrawalPayoutRecord {
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
     * 組裝標準 BillionPay 代付回調參數
     */
    private function buildCallbackData(string $transactionReferenceId, array $overrides = []): array
    {
        $data = array_merge([
            'transaction_reference_id' => $transactionReferenceId,
            'bank_reference_id' => 'BP_REF_001',
            'customer_id' => 'test_member',
            'currency' => 'SGD',
            'amount' => '100.00',
            'fee' => '1.00',
            'status' => 'completed',
            'bank' => 'VIETCOMBANK',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'receipt_image' => 'IMAGE_STRING',
            'receipt_mime_type' => 'image/jpeg',
            'receipt_url' => 'http://example.invalid',
        ], $overrides);

        $data['signature'] = $this->generateValidSign($data, $this->agentCashFlow->info['SECRET_KEY']);

        return $data;
    }

    /**
     * 測試成功處理代付回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22, // 代付處理中
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'Billion123456789');

        $callbackData = $this->buildCallbackData('Billion123456789');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新為三方成功
        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals('Billion123456789', $payoutRecord->transaction_no);
        // 金流商推送了圖片2維碼，會導致存入 db 欄位不足
        $this->assertArrayNotHasKey('receipt_image', json_decode($payoutRecord->callback_request_body, true));
    }

    /**
     * 測試代付 pending 狀態時維持 22 回 ack（INDETERMINATE，等下次推送）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_pending_status_keeps_22_indeterminate
     */
    public function test_pending_status_keeps_22_indeterminate()
    {
        // Arrange
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'Billion123456790');

        $callbackData = $this->buildCallbackData('Billion123456790', [
            'receipt_image' => null,
            'receipt_mime_type' => null,
            'receipt_url' => null,
            'status' => 'pending', // 處理中（非終態）→ INDETERMINATE，保持 22
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert — pending → INDETERMINATE，訂單維持 22（等下次推送）
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);

        // 訂單 status 仍為 22
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // 早退不寫 payout record
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertSame('', $payoutRecord->result_code);
    }

    /**
     * 測試代付 rejected 狀態時標記失敗 29（doc-literal：其他=失敗終態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_rejected_status_marks_29_failure
     */
    public function test_rejected_status_marks_29_failure()
    {
        // Arrange
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'Billion123456790B');

        $callbackData = $this->buildCallbackData('Billion123456790B', [
            'receipt_image' => null,
            'receipt_mime_type' => null,
            'receipt_url' => null,
            'status' => 'rejected', // 終態失敗（doc-literal：非 completed/pending → FAILURE）
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert — rejected → FAILURE，訂單標 29
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);

        // 訂單 status 應更新為 29（三方失敗終態）
        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status);

        // payout record 應被寫入（FAILURE 走正常流程）
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout);
        $this->assertSame('rejected', $payoutRecord->result_code);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_invalid_signature
     */
    public function test_invalid_signature()
    {
        // Arrange
        $callbackData = $this->buildCallbackData('Billion123456791');
        $callbackData['signature'] = 'invalid_signature'; // 覆蓋為無效簽名

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試代付記錄不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_payout_record_not_found
     */
    public function test_payout_record_not_found()
    {
        // Arrange
        $callbackData = $this->buildCallbackData('NONEXISTENT_TX_NO');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('fail', $result->response);
    }

    /**
     * 測試代付記錄已處理（重複回調）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_payout_record_already_processed
     */
    public function test_payout_record_already_processed()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 24, // 已成功
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $this->createPayoutRecord($withdrawalOrder->id, 'Billion123456792', [
            'result_amount' => 100.00,
            'is_success_payout' => 1,
        ]);

        $callbackData = $this->buildCallbackData('Billion123456792');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 已處理的記錄應返回成功（冪等）
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange - 創建代付記錄但訂單已被刪除（不存在的 withdrawal_order_id）
        $this->createPayoutRecord(999999, 'Billion123456793');

        $callbackData = $this->buildCallbackData('Billion123456793');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('fail', $result->response);
    }

    /**
     * 測試金額不符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
    {
        // Arrange
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

        $this->createPayoutRecord($withdrawalOrder->id, 'Billion123456794');

        $callbackData = $this->buildCallbackData('Billion123456794', [
            'amount' => '95.00', // 金額不符
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        $withdrawalCallback->handle($request);

        // 驗證訂單已更新為三方成功
        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
    }

    /**
     * 測試缺少必要參數
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_missing_required_parameters
     */
    public function test_missing_required_parameters()
    {
        // Arrange - 缺少 transaction_reference_id 欄位
        $callbackData = $this->buildCallbackData('Billion123456795');
        unset($callbackData['transaction_reference_id']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試大額金額回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_large_amount_callback
     */
    public function test_large_amount_callback()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 999999.99,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $this->createPayoutRecord($withdrawalOrder->id, 'Billion123456796');

        $callbackData = $this->buildCallbackData('Billion123456796', [
            'amount' => '999999.99',
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
    }

    /**
     * 測試缺少 signature 欄位（驗證必填）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_missing_signature
     */
    public function test_missing_signature()
    {
        // Arrange - 缺少 signature 欄位
        $callbackData = $this->buildCallbackData('Billion123456798');
        unset($callbackData['signature']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試小數點精度
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalCallbackTest.php --filter test_decimal_precision
     */
    public function test_decimal_precision()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.01,
            'status' => 22,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $this->createPayoutRecord($withdrawalOrder->id, 'Billion123456799');

        $callbackData = $this->buildCallbackData('Billion123456799', [
            'amount' => '100.01',
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
    }
}
