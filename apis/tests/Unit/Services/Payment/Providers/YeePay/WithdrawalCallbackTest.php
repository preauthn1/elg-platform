<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\YeePay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalCallbackResponseData;
use App\Services\Payment\Providers\YeePay\WithdrawalCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * YeePay 代付回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php
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
            'MERCHANT_UID' => 'test_merchant_uid',
            'MERCHANT_NUM' => 'test_merchant_num',
            'API_SECRET' => 'test_api_secret',
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
     * 生成有效的 YeePay 簽名
     */
    private function generateValidSign(array $params, string $secret): string
    {
        unset($params['sign']);
        $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true));
        ksort($filtered);
        $queryString = urldecode(http_build_query($filtered));
        $signString = $queryString . '&key=' . $secret;

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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_success
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456789');

        $callbackData = [
            'code' => 1, // 成功
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456789',
            'serial_number' => 'YP2024012300001',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);
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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_failure_callback
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456790');

        $callbackData = [
            'code' => 0, // 失敗
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456790',
            'serial_number' => 'YP2024012300002',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Insufficient balance',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);

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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_invalid_signature
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456791');

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456791',
            'serial_number' => 'YP2024012300003',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
            'sign' => 'INVALID_SIGNATURE',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Token verification failed');
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試代付記錄不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_payout_record_not_found
     */
    public function test_payout_record_not_found()
    {
        // Arrange
        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'NONEXISTENT',
            'serial_number' => 'YP2024012300004',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }

    /**
     * 測試代付記錄已處理（重複回調）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_payout_record_already_processed
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456792', [
            'result_amount' => 100.00,
            'is_success_payout' => 1, // 已處理
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456792',
            'serial_number' => 'YP2024012300005',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 已處理的記錄應返回成功
        $this->assertEquals('SUCCESS', $result->response);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange - 創建代付記錄但訂單已被刪除
        $payoutRecord = $this->createPayoutRecord(999999, 'YEE123456793'); // 不存在的訂單

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456793',
            'serial_number' => 'YP2024012300006',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }

    /**
     * 測試金額不符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_amount_mismatch
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456794');

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '95.00', // 金額不符
            'merchant_order' => 'YEE123456794',
            'serial_number' => 'YP2024012300007',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert - 金額不符應該記錄但仍返回成功
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
    }

    /**
     * 測試缺少必要參數
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_missing_required_parameters
     */
    public function test_missing_required_parameters()
    {
        // Arrange - 缺少 message 欄位
        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456795',
            'serial_number' => 'YP2024012300008',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            // 'message' => 'Success', // 故意缺少
            'sign' => 'SOME_SIGN',
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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_large_amount_callback
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456796');

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '999999.99',
            'merchant_order' => 'YEE123456796',
            'serial_number' => 'YP2024012300009',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
    }

    /**
     * 測試時間格式
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_time_format
     */
    public function test_time_format()
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456797');

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456797',
            'serial_number' => 'YP2024012300010',
            'order_time' => '2024-01-23 23:59:59', // 邊界時間
            'success_time' => '2024-01-24 00:00:00', // 跨日期
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);
    }

    /**
     * 測試 code 參數無效
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_invalid_code_value
     */
    public function test_invalid_code_value()
    {
        // Arrange
        $callbackData = [
            'code' => 2, // 無效值（只允許 0 或 1）
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'merchant_order' => 'YEE123456798',
            'serial_number' => 'YP2024012300011',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
            'sign' => 'SOME_SIGN',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $withdrawalCallback->handle($request);
    }

    /**
     * 測試小數點精度
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalCallbackTest.php --filter test_decimal_precision
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

        $payoutRecord = $this->createPayoutRecord($withdrawalOrder->id, 'YEE123456799');

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.01',
            'merchant_order' => 'YEE123456799',
            'serial_number' => 'YP2024012300012',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'message' => 'Success',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $withdrawalCallback = new WithdrawalCallback($this->agentCashFlow);

        // Act
        $result = $withdrawalCallback->handle($request);

        // Assert
        $this->assertInstanceOf(WithdrawalCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);

        $withdrawalOrder->refresh();
        $this->assertEquals(24, $withdrawalOrder->status);
    }
}
