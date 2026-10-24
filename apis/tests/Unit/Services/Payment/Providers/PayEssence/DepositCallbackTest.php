<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayEssence;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\PayEssence\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PayEssence 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php
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

        // 建立 Currency
        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'SECRET_KEY' => 'test_secret_key',
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
     * 生成有效的 CheckString
     * CheckString = SHA256(MerchantCode + SecretKey + TransNum)
     */
    private function generateCheckString(string $merchantCode, string $secretKey, string $transNum): string
    {
        return hash('sha256', $merchantCode . $secretKey . $transNum);
    }

    /**
     * 生成有效的 CheckString2
     * CheckString2 = SHA256(Reversed(MerchantCode) + Reversed(TransNum) + SecretKey)
     */
    private function generateCheckString2(string $merchantCode, string $secretKey, string $transNum): string
    {
        $reversedMerchantCode = strrev($merchantCode);
        $reversedTransNum = strrev($transNum);

        return hash('sha256', $reversedMerchantCode . $reversedTransNum . $secretKey);
    }

    /**
     * 測試成功處理代收回調（成功狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
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
            'order_no' => 'PE123456789',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456789';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transNum,
            'DepositId' => 'DEP2024012300001',
            'Amount' => '100.00',
            'Result' => '10001', // 成功
            'CheckString' => $this->generateCheckString($merchantCode, $secretKey, $transNum),
            'CheckString2' => $this->generateCheckString2($merchantCode, $secretKey, $transNum),
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('{"Status":"OK"}', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('DEP2024012300001', $order->third_party_order_no);
    }

    /**
     * 測試處理代收回調（失敗狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_failed_status
     */
    public function test_failed_status()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
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
            'order_no' => 'PE123456790',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456790';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transNum,
            'DepositId' => 'DEP2024012300002',
            'Amount' => '100.00',
            'Result' => '10002', // 失敗
            'CheckString' => $this->generateCheckString($merchantCode, $secretKey, $transNum),
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新為失敗狀態
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
        $this->assertEquals('DEP2024012300002', $order->third_party_order_no);
    }

    /**
     * 測試處理代收回調（待處理狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_pending_status
     */
    public function test_pending_status()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
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
            'order_no' => 'PE123456791',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456791';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transNum,
            'DepositId' => 'DEP2024012300003',
            'Amount' => '100.00',
            'Result' => '10004', // 待處理
            'CheckString' => $this->generateCheckString($merchantCode, $secretKey, $transNum),
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertNotNull($result->order);

        // Result=10004 為待處理，訂單狀態應保持 CREATED(0) 不變，等待後續回調
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 測試無效的商戶代碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_invalid_merchant_code
     */
    public function test_invalid_merchant_code()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
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
            'order_no' => 'PE123456792',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'MerchantCode' => 'WRONG_MERCHANT',
            'TransNum' => 'PE123456792',
            'DepositId' => 'DEP2024012300004',
            'Amount' => '100.00',
            'Result' => '10001',
            'CheckString' => 'invalid_checkstring',
            'CheckString2' => 'invalid_checkstring2',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid merchant code in callback');

        // Act
        $depositCallback->handle($request);
    }

    /**
     * 測試 CheckString 驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_invalid_checkstring
     */
    public function test_invalid_checkstring()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
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
            'order_no' => 'PE123456793',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'MerchantCode' => 'TEST_MERCHANT',
            'TransNum' => 'PE123456793',
            'DepositId' => 'DEP2024012300005',
            'Amount' => '100.00',
            'Result' => '10001',
            'CheckString' => 'invalid_checkstring',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('CheckString verification failed');

        // Act
        $depositCallback->handle($request);
    }

    /**
     * 測試成功狀態時 CheckString2 驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_invalid_checkstring2
     */
    public function test_invalid_checkstring2()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
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
            'order_no' => 'PE123456794',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456794';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transNum,
            'DepositId' => 'DEP2024012300006',
            'Amount' => '100.00',
            'Result' => '10001', // 成功
            'CheckString' => $this->generateCheckString($merchantCode, $secretKey, $transNum),
            'CheckString2' => 'invalid_checkstring2',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('CheckString2 verification failed');

        // Act
        $depositCallback->handle($request);
    }

    /**
     * 測試金額不匹配
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayEssence',
            'type' => 1,
            'status' => 1,
            'key' => 'PAYESSENCE',
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
            'order_no' => 'PE123456795',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE123456795';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transNum,
            'DepositId' => 'DEP2024012300007',
            'Amount' => '150.00', // 金額不匹配
            'Result' => '10001',
            'CheckString' => $this->generateCheckString($merchantCode, $secretKey, $transNum),
            'CheckString2' => $this->generateCheckString2($merchantCode, $secretKey, $transNum),
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 金額不符時應標記為待人工確認（WAITING_MANUAL_DEPOSIT）
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals(json_encode(['Status' => 'OK']), $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已標記為待人工確認
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $merchantCode = 'TEST_MERCHANT';
        $secretKey = 'test_secret_key';
        $transNum = 'PE999999999';

        $callbackData = [
            'MerchantCode' => $merchantCode,
            'TransNum' => $transNum,
            'DepositId' => 'DEP2024012300008',
            'Amount' => '100.00',
            'Result' => '10001',
            'CheckString' => $this->generateCheckString($merchantCode, $secretKey, $transNum),
            'CheckString2' => $this->generateCheckString2($merchantCode, $secretKey, $transNum),
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert：訂單不存在應回傳 fail response（handleDepositOrderException 標準行為），而非拋出例外
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('Order not found', $result->errorMessage);
    }

    /**
     * 測試缺少配置
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/DepositCallbackTest.php --filter test_missing_configuration
     */
    public function test_missing_configuration()
    {
        // Arrange
        $info = [];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $callbackData = [
            'MerchantCode' => 'TEST_MERCHANT',
            'TransNum' => 'PE123456796',
            'DepositId' => 'DEP2024012300009',
            'Amount' => '100.00',
            'Result' => '10001',
            'CheckString' => 'some_checkstring',
            'CheckString2' => 'some_checkstring2',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('PayEssence configuration missing');

        // Act
        $depositCallback->handle($request);
    }
}
