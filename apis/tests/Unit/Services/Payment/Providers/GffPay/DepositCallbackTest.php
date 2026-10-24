<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GffPay;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\GffPay\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * GffPay 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php
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
     * 測試成功處理代收回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_success
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
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF123456789',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123456',
            'merchant_order_no' => 'GFF123456789',
            'status' => 2, // 成功
            'amount' => '100.00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('GFF_TP_123456', $order->third_party_order_no);
    }

    /**
     * 測試代收失敗回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_failure_callback
     */
    public function test_failure_callback()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF123456790',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123457',
            'merchant_order_no' => 'GFF123456790',
            'status' => 3, // 失敗
            'amount' => '100.00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        // 驗證訂單狀態更新為失敗
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_invalid_signature
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
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF123456791',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123458',
            'merchant_order_no' => 'GFF123456791',
            'status' => 2,
            'amount' => '100.00',
            'sign' => 'INVALID_SIGNATURE',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 400, 'message' => 'Invalid signature'], $result->response);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123459',
            'merchant_order_no' => 'NONEXISTENT',
            'status' => 2,
            'amount' => '100.00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 訂單不存在時應返回錯誤響應
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 400, 'message' => 'Order not found'], $result->response);
    }

    /**
     * 測試訂單已處理（重複回調）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_order_already_processed
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
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => 'GFF_TP_123460',
            'order_no' => 'GFF123456792',
            'status' => OrderStatusEnum::SUCCESS->value, // 已成功
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123460',
            'merchant_order_no' => 'GFF123456792',
            'status' => 2,
            'amount' => '100.00',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 已處理的訂單應返回成功
        $this->assertEquals(['code' => 200, 'message' => 'Order already processed'], $result->response);
    }

    /**
     * 測試缺少必要參數
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_missing_required_parameters
     */
    public function test_missing_required_parameters()
    {
        // Arrange - 缺少 sign 欄位
        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123461',
            'merchant_order_no' => 'GFF123456793',
            'status' => 2,
            'amount' => '100.00',
            // 'sign' => 'SOME_SIGN', // 故意缺少
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert & Act
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $depositCallback->handle($request);
    }

    /**
     * 測試大額金額回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_large_amount_callback
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
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF123456796',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '999999.99',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123462',
            'merchant_order_no' => 'GFF123456796',
            'status' => 2,
            'amount' => '999999.99',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 測試金額不符
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_amount_mismatch
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
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF123456794',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123463',
            'merchant_order_no' => 'GFF123456794',
            'status' => 2,
            'amount' => '95.00', // 金額不符
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 金額不符時應返回成功響應，但訂單狀態應為等待人工確認
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        // 驗證訂單狀態已更新為等待人工確認
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 測試小數點精度
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositCallbackTest.php --filter test_decimal_precision
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
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF123456799',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.01',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_123464',
            'merchant_order_no' => 'GFF123456799',
            'status' => 2,
            'amount' => '100.01',
        ];

        $callbackData['sign'] = $this->generateValidSign($callbackData, 'test_merchant_key');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(['code' => 200, 'message' => 'Success'], $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
    }

    /**
     * 空憑證守衛：MerchantKey 為空時 sign() 的 secretKey 段為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須先擋下（回 400 Invalid signature），且訂單維持 CREATED。
     */
    public function test_empty_credentials_reject_forged_callback()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank Transfer',
            'type' => 1,
            'status' => 1,
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF_EMPTY_KEY',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
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
            'currency_code' => 'USD',
            'merchant_code' => 'test_merchant_no',
            'order_no' => 'GFF_TP_EMPTY',
            'merchant_order_no' => 'GFF_EMPTY_KEY',
            'status' => 2,
            'amount' => '100.00',
        ];
        // 攻擊者以空 key 自算簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, '');

        $request = Request::create('/callback', 'POST', $callbackData);
        $result = (new DepositCallback($emptyAcf))->handle($request);

        $this->assertEquals(['code' => 400, 'message' => 'Invalid signature'], $result->response);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }
}
