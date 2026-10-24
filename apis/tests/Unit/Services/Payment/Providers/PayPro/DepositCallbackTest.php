<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayPro;

use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\PayPro\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PayPro 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php
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
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://sandbox-api.paypro.tech/indonesia-pay',
            'MERCHANT_NO' => '0000000001',
            'MERCHANT_KEY' => 'test_merchant_key',
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
     * 生成有效的簽名
     */
    private function generateValidSign(array $params, string $merchantKey): string
    {
        // 排除 sign 欄位
        unset($params['sign']);

        // 過濾空值
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 按首字母 a-z 排序
        ksort($filtered);

        // 拼接成 key1=value1key2=value2...
        $signString = '';
        foreach ($filtered as $key => $value) {
            $signString .= $key . '=' . $value;
        }

        // 使用 merchant key 作為 salt
        $signString .= $merchantKey;

        // MD5 加密並轉小寫
        return strtolower(md5($signString));
    }

    /**
     * 測試成功處理代收回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayPro Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'QRIS',
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
            'order_no' => 'TRN123456789',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100000',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'tradeNo' => 'TRN123456789',
            'message' => 'Mock success status',
            'wayCode' => 'QRIS',
            'status' => '0000', // 成功
            'channelCode' => 'QRIS',
            'amount' => 100000,
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000czqcfalkxyti0pz4_da',
            'successTime' => '2026-02-03 14:56:16',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('E000czqcfalkxyti0pz4_da', $order->third_party_order_no);
    }

    /**
     * 測試部分支付狀態（0001）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_partial_payment
     */
    public function test_partial_payment()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayPro Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'QRIS',
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
            'order_no' => 'TRN123456790',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100000',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'tradeNo' => 'TRN123456790',
            'message' => 'Partial payment',
            'wayCode' => 'QRIS',
            'status' => '0001', // 部分支付
            'channelCode' => 'QRIS',
            'amount' => 50000, // 部分金額
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000czqcfalkxyti0pz4_db',
            'successTime' => '2026-02-03 14:56:16',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 驗證訂單已更新（部分支付金額不匹配，會標記為人工確認）
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 測試處理中狀態（0015）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_processing_status
     */
    public function test_processing_status()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayPro Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'QRIS',
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
            'order_no' => 'TRN123456791',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100000',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'tradeNo' => 'TRN123456791',
            'message' => 'Processing',
            'wayCode' => 'QRIS',
            'status' => '0015', // 處理中
            'channelCode' => 'QRIS',
            'amount' => 100000,
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000czqcfalkxyti0pz4_dc',
            'successTime' => '2026-02-03 14:56:16',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertEquals('Order is processing', $result->errorMessage);

        // 驗證訂單狀態未改變（處理中不更新訂單）
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 測試失敗狀態
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_failure_status
     */
    public function test_failure_status()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayPro Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'QRIS',
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
            'order_no' => 'TRN123456792',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100000',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'tradeNo' => 'TRN123456792',
            'message' => 'Payment failed',
            'wayCode' => 'QRIS',
            'status' => '0002', // 失敗
            'channelCode' => 'QRIS',
            'amount' => 100000,
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000czqcfalkxyti0pz4_dd',
            'successTime' => '2026-02-03 14:56:16',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);

        // 驗證訂單已更新為失敗
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_invalid_signature
     */
    public function test_invalid_signature()
    {
        // Arrange
        $callbackData = [
            'tradeNo' => 'TRN123456793',
            'message' => 'Mock success status',
            'wayCode' => 'QRIS',
            'status' => '0000',
            'channelCode' => 'QRIS',
            'amount' => 100000,
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000czqcfalkxyti0pz4_de',
            'successTime' => '2026-02-03 14:56:16',
            'sign' => 'invalid_signature',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act & Assert
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid signature');
        $depositCallback->handle($request);
    }

    /**
     * 測試缺少必要欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_missing_required_fields
     */
    public function test_missing_required_fields()
    {
        // Arrange
        $callbackData = [
            'tradeNo' => 'TRN123456794',
            // 缺少 platFormTradeNo, amount, status, sign 等必要欄位
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act & Assert
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $depositCallback->handle($request);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $callbackData = [
            'tradeNo' => 'NONEXISTENT',
            'message' => 'Mock success status',
            'wayCode' => 'QRIS',
            'status' => '0000',
            'channelCode' => 'QRIS',
            'amount' => 100000,
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000czqcfalkxyti0pz4_df',
            'successTime' => '2026-02-03 14:56:16',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 訂單不存在時返回失敗響應
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('FAIL', $result->response);
    }

    /**
     * 測試訂單已處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/DepositCallbackTest.php --filter test_order_already_processed
     */
    public function test_order_already_processed()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayPro Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'QRIS',
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
            'order_no' => 'TRN123456795',
            'status' => OrderStatusEnum::SUCCESS->value, // 已成功
            'balance' => 0,
            'amount' => '100000',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'tradeNo' => 'TRN123456795',
            'message' => 'Mock success status',
            'wayCode' => 'QRIS',
            'status' => '0000',
            'channelCode' => 'QRIS',
            'amount' => 100000,
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000czqcfalkxyti0pz4_dg',
            'successTime' => '2026-02-03 14:56:16',
        ];

        // 生成簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, $this->agentCashFlow->info['MERCHANT_KEY']);

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        // 已處理的訂單應該返回成功響應但不更新訂單
        $this->assertEquals('OK', $result->response);
    }

    /**
     * 空憑證守衛：MERCHANT_KEY 為空時 generateSign() 的 salt 為空、簽名可離線計算，
     * 攻擊者能偽造成功回調直接上分。守衛須在驗簽前先擋下（拋 Invalid signature），且訂單維持 CREATED。
     */
    public function test_empty_credentials_reject_forged_callback(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'PayPro Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'QRIS',
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
            'order_no' => 'PAYPRO_DEP_EMPTY',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100000',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $emptyInfo = [
            'API_URL' => 'https://sandbox-api.paypro.tech/indonesia-pay',
            'MERCHANT_NO' => '0000000001',
            'MERCHANT_KEY' => '',
        ];
        $emptyAcf = new AgentCashFlow([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($emptyInfo),
            'status' => 1,
            'admin' => 0,
        ]);
        $emptyAcf->info = $emptyInfo;

        $callbackData = [
            'tradeNo' => 'PAYPRO_DEP_EMPTY',
            'message' => 'Mock success status',
            'wayCode' => 'QRIS',
            'status' => '0000',
            'channelCode' => 'QRIS',
            'amount' => 100000,
            'timestamp' => '1770101776756',
            'platFormTradeNo' => 'E000forged_dep',
            'successTime' => '2026-02-03 14:56:16',
        ];
        // 攻擊者以空 merchant key 自算簽名
        $callbackData['sign'] = $this->generateValidSign($callbackData, '');
        $request = Request::create('/callback', 'POST', $callbackData);

        $threw = false;
        try {
            (new DepositCallback($emptyAcf))->handle($request);
        } catch (\Exception $e) {
            $threw = true;
            $this->assertSame('Invalid signature', $e->getMessage());
        }
        $this->assertTrue($threw, '空憑證回調應被守衛拒絕');

        $order->refresh();
        $this->assertSame(OrderStatusEnum::CREATED->value, $order->status);
    }
}
