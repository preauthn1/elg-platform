<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GffPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\GffPay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * GffPay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php
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
            'MerchantNo' => 'test_merchant_no',
            'MerchantKey' => 'test_merchant_key',
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
     * 生成有效的 GffPay 簽名
     */
    private function generateValidSign(array $params, string $secret): string
    {
        unset($params['sign']);
        ksort($params);
        $signString = '';
        foreach ($params as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secret;

        return strtoupper(md5($signString));
    }

    /**
     * 創建測試用的 WithdrawalPayoutRecord
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
     * 測試成功處理代付回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_success
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

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123456',
            'merchant_order_no' => 'GFF123456789',
            'status' => 2, // 成功
            'amount' => '100.00',
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456789', [
            'third_party_transaction_no' => 'GFF_TP_123456',
        ]);

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::SUCCESS, $payoutRecord->is_success_payout);
        $this->assertEquals('100.0000', $payoutRecord->result_amount);
    }

    /**
     * 測試代付失敗回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_failure_callback
     */
    public function test_failure_callback()
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

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123457',
            'merchant_order_no' => 'GFF123456790',
            'status' => 3, // 失敗
            'amount' => '100.00',
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456790', [
            'third_party_transaction_no' => 'GFF_TP_123457',
        ]);

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        // 驗證訂單狀態更新為失敗
        $withdrawalOrder->refresh();
        $this->assertEquals(29, $withdrawalOrder->status); // 三方失敗

        // 驗證代付記錄已更新
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::CALLBACK_FAILED, $payoutRecord->is_success_payout); // 回調失敗 → -3
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_invalid_signature
     */
    public function test_invalid_signature()
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

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123458',
            'merchant_order_no' => 'GFF123456791',
            'status' => 2,
            'amount' => '100.00',
            'sign' => 'INVALID_SIGNATURE',
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456791', [
            'third_party_transaction_no' => 'GFF_TP_123458',
        ]);

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 400, 'message' => 'Invalid signature'], $result->response);
    }

    /**
     * 測試代付記錄不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_payout_record_not_found
     */
    public function test_payout_record_not_found()
    {
        // Arrange
        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_NONEXISTENT',
            'merchant_order_no' => 'NONEXISTENT',
            'status' => 2,
            'amount' => '100.00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 400, 'message' => 'Order not exist'], $result->response);
    }

    /**
     * 測試代付記錄已處理（重複回調）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_payout_record_already_processed
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

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123459',
            'merchant_order_no' => 'GFF123456792',
            'status' => 2,
            'amount' => '100.00',
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456792', [
            'third_party_transaction_no' => 'GFF_TP_123459',
            'result_amount' => 100.00,
            'is_success_payout' => 1, // 已處理
        ]);

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 已處理的記錄應返回成功
        $this->assertEquals(['code' => 200, 'message' => 'Order already processed'], $result->response);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange - 創建代付記錄但訂單已被刪除
        $payoutRecord = $this->createPayoutRecord(999999, 'GFF123456793'); // 不存在的訂單

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123460',
            'merchant_order_no' => 'GFF123456793',
            'status' => 2,
            'amount' => '100.00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 400, 'message' => 'Order not exist'], $result->response);
    }

    /**
     * 測試金額不符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_amount_mismatch
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

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123461',
            'merchant_order_no' => 'GFF123456794',
            'status' => 2, // 成功時才驗證金額
            'amount' => '95.00', // 金額不符
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456794', [
            'third_party_transaction_no' => 'GFF_TP_123461',
        ]);

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 金額不符時應返回成功響應（只記錄警告，不拋出異常）
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        // 驗證訂單狀態已更新為成功（金額不符只記錄警告，不影響訂單狀態）
        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status); // 三方成功
    }

    /**
     * 測試缺少必要參數
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_missing_required_parameters
     */
    public function test_missing_required_parameters()
    {
        // Arrange - 缺少 sign 欄位
        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123462',
            'merchant_order_no' => 'GFF123456795',
            'status' => 2,
            'amount' => '100.00',
            // 'sign' => 'SOME_SIGN', // 故意缺少
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試大額金額回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_large_amount_callback
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

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123463',
            'merchant_order_no' => 'GFF123456796',
            'status' => 2,
            'amount' => '999999.99',
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456796', [
            'third_party_transaction_no' => 'GFF_TP_123463',
        ]);

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
    }

    /**
     * 測試小數點精度
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_decimal_precision
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

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123464',
            'merchant_order_no' => 'GFF123456799',
            'status' => 2,
            'amount' => '100.01',
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456799', [
            'third_party_transaction_no' => 'GFF_TP_123464',
        ]);

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
    }

    /**
     * 測試 status = 1 (等待付款) → INDETERMINATE，保持 22 並回成功響應
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalCallbackTest.php --filter test_status_waiting_indeterminate
     */
    public function test_status_waiting_indeterminate(): void
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
            'status' => 22, // PAYMENT_PROCESSING
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123465',
            'merchant_order_no' => 'GFF123456800',
            'status' => 1, // 等待付款 → INDETERMINATE
            'amount' => '100.00',
        ];

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'GFF123456800', [
            'third_party_transaction_no' => 'GFF_TP_123465',
        ]);

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - status = 1 (等待付款) 落入 INDETERMINATE，回 200 Success ack 三方
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        // 訂單狀態應保持 22，不推進
        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);

        // payout record 不應被結算
        $payoutRecord->refresh();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $payoutRecord->is_success_payout);
        $this->assertEquals('', $payoutRecord->result_code);
    }

    /**
     * 空憑證守衛：MerchantKey 為空時 sign() 的 secretKey 段為空、簽名可離線計算，
     * 攻擊者能偽造回調洗訂單。守衛須先擋下（回 400 Invalid signature），且訂單維持 22。
     */
    public function test_empty_credentials_reject_forged_callback()
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
        $this->createPayoutRecord($withdrawalOrder->id, 'GFF_EMPTY_KEY', [
            'third_party_transaction_no' => 'GFF_TP_EMPTY',
        ]);

        $emptyInfo = ['MerchantNo' => 'test_merchant_no', 'MerchantKey' => ''];
        $emptyAcf = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        $callbackData = [
            'currency_code' => 'VIETCOMBANK',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_EMPTY',
            'merchant_order_no' => 'GFF_EMPTY_KEY',
            'status' => 2,
            'amount' => '100.00',
        ];
        $callbackData['sign'] = $this->generateValidSign($callbackData, '');

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new WithdrawalCallback($emptyAcf))->handle($request);

        $this->assertEquals(['code' => 400, 'message' => 'Invalid signature'], $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(22, $withdrawalOrder->status);
    }
}
