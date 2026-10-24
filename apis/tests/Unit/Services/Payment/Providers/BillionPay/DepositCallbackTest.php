<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BillionPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\BillionPay\DepositCallback;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * BillionPay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php
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
            'SECRET_KEY' => 'test_secret_key',
            'USERNAME' => 'test_username',
            'PASSWORD' => 'test_password',
            'LOGIN_URL' => 'https://example.invalid/api/login',
            'DEPOSIT_URL' => 'https://example.invalid/api/deposit',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
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
     * 生成有效的 BillionPay 簽名（與 BillionPayTrait::generateSign 演算法一致）
     */
    private function generateValidSign(array $params, string $secret): string
    {
        unset($params['signature']);

        $filtered = array_filter($params, fn ($value) => !in_array($value, [null, '', 0, '0'], true));

        ksort($filtered);

        return md5(json_encode($filtered) . $secret);
    }

    /**
     * 測試成功處理代收回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_success
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
            'order_no' => 'BP123456789',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '10000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '10000.00000',
            'actual_amount' => '10000.00000',
            'fee' => '20.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Ckw',
            'bank_account_number' => '7053710936',
            'full_name' => 'test user',
            'signature' => 'f26ad95c80cae6134efa80f0d9c08ecc',
        ];

        // 生成有效簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = App::make(DepositCallback::class, ['agentCashFlow' => $this->agentCashFlow]);
        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('', $order->third_party_order_no);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_invalid_signature
     */
    public function test_invalid_signature()
    {
        // Arrange
        $callbackData = [
            'transaction_reference_id' => 'BP123456790',
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            'actual_amount' => '100.00000',
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
            'signature' => 'INVALID_SIGNATURE_HERE',
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
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $callbackData = [
            'transaction_reference_id' => 'NONEXISTENT_ORDER',
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            'actual_amount' => '100.00000',
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 訂單不存在應返回失敗
        $this->assertEquals('fail', $result->response);
    }

    /**
     * 測試訂單已處理（重複回調）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_order_already_processed
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
            'key' => 'card',
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
            'order_no' => 'BP123456791',
            'status' => OrderStatusEnum::SUCCESS->value, // 已經成功
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            'actual_amount' => '100.00000',
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 已處理的訂單應返回成功（冪等處理）
        $this->assertEquals('true', $result->response);
    }

    /**
     * 測試金額不符（actual_amount 與訂單金額不符）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_amount_mismatch
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
            'key' => 'card',
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
            'order_no' => 'BP123456792',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00', // 訂單金額
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            'actual_amount' => '95.00000', // 實收金額不符
            'fee' => '5.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        // 驗證訂單狀態 - actual_amount 不符應標記為待人工審核
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 測試缺少必要參數（缺少 customer_id）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_missing_required_parameters
     */
    public function test_missing_required_parameters()
    {
        // Arrange - 缺少 customer_id
        $callbackData = [
            'transaction_reference_id' => 'BP123456793',
            'bank_reference_id' => null,
            // 'customer_id' => 'USERID', // 故意缺少
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            'actual_amount' => '100.00000',
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'signature' => 'SOME_SIGN',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert & Act - 應該拋出驗證異常
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $depositCallback->handle($request);
    }

    /**
     * 測試缺少 actual_amount 欄位（BillionPay 必填）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_missing_actual_amount
     */
    public function test_missing_actual_amount()
    {
        // Arrange - 缺少 actual_amount
        $callbackData = [
            'transaction_reference_id' => 'BP123456795',
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            // 'actual_amount' => '100.00000', // 故意缺少
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'signature' => 'SOME_SIGN',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert & Act - 應該拋出驗證異常
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $depositCallback->handle($request);
    }

    /**
     * 測試 bank_reference_id 可為 null（nullable 欄位）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_bank_reference_id_nullable
     */
    public function test_bank_reference_id_nullable()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
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
            'order_no' => 'BP123456796',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null, // nullable，應正常通過
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            'actual_amount' => '100.00000',
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_large_amount_callback
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
            'key' => 'card',
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
            'order_no' => 'BP123456797',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '999999.99', // 大額金額
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '999999.99000',
            'actual_amount' => '999999.99000',
            'fee' => '1999.99000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 測試超額付款（actual_amount 大於訂單金額）應標記為待人工審核
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_overpayment
     */
    public function test_overpayment()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'card',
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
            'order_no' => 'BP123456798',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '110.00000',
            'actual_amount' => '110.00000', // 實收金額超過訂單金額
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 超額付款應標記為待人工審核
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 測試空白字符處理（bank_account_name 含空白應正常通過）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_whitespace_handling
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
            'key' => 'card',
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
            'order_no' => 'BP123456799',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.00000',
            'actual_amount' => '100.00000',
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'John  Doe', // 含多餘空白
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        // 注意：簽名生成時空白字符會影響結果，需要簽名與請求資料一致
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 含空白的字串欄位應正常通過驗證並成功處理
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 測試小數點精度（BillionPay 以 5 位小數回傳金額）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/DepositCallbackTest.php --filter test_decimal_precision
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
            'key' => 'card',
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
            'order_no' => 'BP123456800',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.01', // 精確到分
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'transaction_reference_id' => $order->order_no,
            'bank_reference_id' => null,
            'customer_id' => 'USERID',
            'method' => 'card',
            'currency' => 'SGD',
            'amount' => '100.01000',      // BillionPay 以 5 位小數回傳
            'actual_amount' => '100.01000',
            'fee' => '2.00000',
            'status' => 'completed',
            'bank' => 'CIMB',
            'bank_account_name' => 'Test User',
            'bank_account_number' => '1234567890',
            'full_name' => 'test user',
        ];
        $callbackData['signature'] = $this->generateValidSign($callbackData, 'test_secret_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 5 位小數的金額應與 2 位小數的訂單金額正確比對
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('true', $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }
}
