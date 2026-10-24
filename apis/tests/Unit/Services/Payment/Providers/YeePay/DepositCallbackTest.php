<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\YeePay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\YeePay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * YeePay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
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
        // 排除 sign 欄位
        unset($params['sign']);

        // 排除參數值為 0、空或 null 的
        $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true));

        // 對請求參數進行 ASCII 升序排序
        ksort($filtered);

        // 對參數進行拼接 key1=value1&key2=value2&key=secret
        $queryString = urldecode(http_build_query($filtered));
        $signString = $queryString . '&key=' . $secret;

        // MD5 加密後轉大寫
        return strtoupper(md5($signString));
    }

    /**
     * 測試成功處理代收回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456789',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456789',
            'serial_number' => 'YP2024012300001',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        // 生成有效簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('YP2024012300001', $order->third_party_order_no);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_invalid_signature
     */
    public function test_invalid_signature()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456790',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456790',
            'serial_number' => 'YP2024012300002',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'sign' => 'INVALID_SIGNATURE_HERE',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $depositCallback->handle($request);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'NONEXISTENT_ORDER',
            'serial_number' => 'YP2024012300003',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 訂單不存在應返回失敗
        $this->assertEquals('FAIL', $result->response);
    }

    /**
     * 測試訂單已處理（重複回調）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_order_already_processed
     */
    public function test_order_already_processed()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => 'YP2024012300004',
            'order_no' => 'YEE123456791',
            'status' => OrderStatusEnum::SUCCESS->value, // 已經成功
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456791',
            'serial_number' => 'YP2024012300004',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 已處理的訂單應返回成功（避免重複回調）
        $this->assertEquals('SUCCESS', $result->response);
    }

    /**
     * 測試金額不符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456792',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00', // 訂單金額
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '95.00', // 回調金額不符
            'pay_coin' => '95.00',
            'merchant_order' => 'YEE123456792',
            'serial_number' => 'YP2024012300005',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        // 驗證訂單狀態 - 金額不符應標記為待人工審核
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 測試缺少必要參數
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_missing_required_parameters
     */
    public function test_missing_required_parameters()
    {
        // Arrange - 缺少 serial_number
        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456793',
            // 'serial_number' => 'YP2024012300006', // 故意缺少
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'sign' => 'SOME_SIGN',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert & Act - 應該拋出驗證異常
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $depositCallback->handle($request);
    }

    /**
     * 測試 code 參數為 0（失敗狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_code_zero
     */
    public function test_code_zero()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456794',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 0, // 失敗狀態
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456794',
            'serial_number' => 'YP2024012300007',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act - YeePay 只有成功才回調，所以這個測試主要驗證驗證邏輯
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
    }

    /**
     * 測試 code 參數不在允許範圍內
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_invalid_code_value
     */
    public function test_invalid_code_value()
    {
        // Arrange - code 為 2（不在 0,1 範圍內）
        $callbackData = [
            'code' => 2, // 無效值
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456795',
            'serial_number' => 'YP2024012300008',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
            'sign' => 'SOME_SIGN',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert & Act - 應該拋出驗證異常
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $depositCallback->handle($request);
    }

    /**
     * 測試時間格式正確性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_time_format
     */
    public function test_time_format()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456796',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456796',
            'serial_number' => 'YP2024012300009',
            'order_time' => '2024-01-23 23:59:59', // 邊界時間
            'success_time' => '2024-01-24 00:00:00', // 跨日期
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_large_amount_callback
     */
    public function test_large_amount_callback()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456797',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '999999.99', // 大額金額
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '999999.99',
            'pay_coin' => '999999.99',
            'merchant_order' => 'YEE123456797',
            'serial_number' => 'YP2024012300010',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 測試 coin 和 pay_coin 不一致
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_coin_pay_coin_mismatch
     */
    public function test_coin_pay_coin_mismatch()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456798',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.00',
            'pay_coin' => '98.50', // 實付金額不同（可能扣手續費）
            'merchant_order' => 'YEE123456798',
            'serial_number' => 'YP2024012300011',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 應該能正常處理（系統使用 coin 作為驗證金額）
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);
    }

    /**
     * 測試空白字符處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_whitespace_handling
     */
    public function test_whitespace_handling()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456799',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => ' test_merchant_num ', // 含空白
            'coin' => '100.00', // 金額不應含空白（會導致數值比較錯誤）
            'pay_coin' => '100.00',
            'merchant_order' => 'YEE123456799',
            'serial_number' => 'YP2024012300012',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        // 注意：簽名生成時空白字符會影響結果
        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 應該能正常處理（Laravel 驗證會自動 trim 字串）
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
    }

    /**
     * 測試小數點精度
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositCallbackTest.php --filter test_decimal_precision
     */
    public function test_decimal_precision()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456800',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.01', // 精確到分
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'code' => 1,
            'merchant_num' => 'test_merchant_num',
            'coin' => '100.01',
            'pay_coin' => '100.01',
            'merchant_order' => 'YEE123456800',
            'serial_number' => 'YP2024012300013',
            'order_time' => '2024-01-23 10:00:00',
            'success_time' => '2024-01-23 10:05:00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('SUCCESS', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }
}
