<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TakaPay;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\TakaPay\DepositCallback;
use App\Services\Payment\Providers\TakaPay\TakaPayStatusCodeEnum;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * TakaPay 代收回調集成測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php
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
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'test_merchant_code',
            'SECRET_KEY' => 'test_api_secret',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 89, // TakaPay 代收
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 19,
        ]);
        $this->agentCashFlow->info = $info;

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 生成有效的 TakaPay 簽名（使用真實簽名邏輯）
     */
    private function generateValidSign(array $params, string $secret, string $merchantCode = 'test_merchant_code'): string
    {
        $signFields = [
            $merchantCode,
            $params['status'] ?? '',
            $params['currencyCode'] ?? '',
            $params['amount'] ?? '',
            $params['reference'] ?? '',
            $params['transactionTime'] ?? '',
            $params['systemTransactionId'] ?? '',
        ];

        $signString = implode('', $signFields) . $secret;

        return md5($signString);
    }

    /**
     * 測試成功處理代收回調
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_success
     */
    public function test_success()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19, // BDT
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TAK20240123001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '1000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '1000.00',
            'reference' => 'TAK20240123001',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123001',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);
        $this->assertNotNull($result->order);

        // 驗證訂單已更新
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('TAK20240123001', $order->third_party_order_no);
    }

    /**
     * 測試簽名驗證失敗
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_invalid_signature
     */
    public function test_invalid_signature()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TAK20240123002',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '1000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '1000.00',
            'reference' => 'TAK20240123002',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123002',
            'securityToken' => 'INVALID_SIGNATURE',
        ];

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Signature verification failed');
        $depositCallback->handle($request);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '1000.00',
            'reference' => 'NON_EXISTENT_ORDER',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK99999999999',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertIsArray($result->response);
        $this->assertEquals(TakaPayStatusCodeEnum::FAILED_NOTIFY_CALLBACK->value, $result->response['status'] ?? null);
        $this->assertNull($result->order);
    }

    /**
     * 測試金額不匹配
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TAK20240123003',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '1000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '2000.00', // 金額不匹配
            'reference' => 'TAK20240123003',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123003',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertIsArray($result->response);
        $this->assertEquals(TakaPayStatusCodeEnum::SUCCESS->value, $result->response['status'] ?? null);

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
        $this->assertEquals('TAK20240123003', $order->third_party_order_no);
    }

    /**
     * 測試訂單已處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_order_already_processed
     */
    public function test_order_already_processed()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => 'TAK20240123004',
            'order_no' => 'TAK20240123004',
            'status' => OrderStatusEnum::SUCCESS->value, // 已處理
            'balance' => 0,
            'amount' => '1000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '1000.00',
            'reference' => 'TAK20240123004',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123004_DUPLICATE',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);
    }

    /**
     * 測試失敗狀態碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_failed_transaction
     */
    public function test_failed_transaction()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TAK20240123005',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '1000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::FAILED_TRANSACTION->value, // 失敗狀態
            'currencyCode' => 'BDT',
            'amount' => '1000.00',
            'reference' => 'TAK20240123005',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123005_FAILED',
        ];

        // 生成簽名
        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('00000', $result->response['status']);

        // 驗證訂單狀態已更新為失敗
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
    }

    /**
     * 測試成功回調所有資料更新
     * 驗證：status, third_party_order_no, remark (callback data as JSON)
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_success_all_fields_updated
     */
    public function test_success_all_fields_updated()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TAK20240123006',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '1000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '1000.00',
            'reference' => 'TAK20240123006',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123006',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $order->refresh();

        // 驗證所有資料都已更新
        $this->assertEquals(OrderStatusEnum::SUCCESS->value, $order->status);
        $this->assertEquals('TAK20240123006', $order->third_party_order_no);
        $this->assertNotEmpty($order->remark); // 回調數據應存儲在 remark

        // 驗證 remark 包含回調數據
        $remarkData = json_decode($order->remark, true);
        $this->assertIsArray($remarkData);
        $this->assertEquals('BDT', $remarkData['currencyCode'] ?? null);
        $this->assertEquals('1000.00', $remarkData['amount'] ?? null);
    }

    /**
     * 測試金額不匹配時更新為 WAITING_MANUAL_DEPOSIT
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_amount_mismatch_sets_manual_review
     */
    public function test_amount_mismatch_sets_manual_review()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TAK20240123007',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '1000.00', // 預期金額
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '1500.00', // 回調金額不同
            'reference' => 'TAK20240123007',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123007',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        // Assert - 金額不匹配應標記為人工確認
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
        $this->assertEquals('TAK20240123007', $order->third_party_order_no);
    }

    /**
     * 測試失敗回調所有資料更新
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/DepositCallbackTest.php --filter test_failed_all_fields_updated
     */
    public function test_failed_all_fields_updated()
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TAK20240123008',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '1000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::FAILED_TRANSACTION->value,
            'currencyCode' => 'BDT',
            'amount' => '1000.00',
            'reference' => 'TAK20240123008',
            'transactionTime' => '2024-01-23 10:00:00',
            'systemTransactionId' => 'TAK20240123008_FAILED',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $result = $depositCallback->handle($request);

        $order->refresh();

        // 驗證所有資料都已更新
        $this->assertEquals(OrderStatusEnum::FAILED->value, $order->status);
        $this->assertEquals('TAK20240123008_FAILED', $order->third_party_order_no);
        $this->assertNotEmpty($order->remark);

        // 驗證 remark 包含回調數據
        $remarkData = json_decode($order->remark, true);
        $this->assertIsArray($remarkData);
        $this->assertEquals(TakaPayStatusCodeEnum::FAILED_TRANSACTION->value, $remarkData['status'] ?? null);
    }

    /**
     * 測試代收回調保存交易時間到訂單的 remark
     *
     * 驗證支付商回傳的 transactionTime 被正確記錄在訂單備註中
     */
    public function test_deposit_callback_saves_transaction_time(): void
    {
        $orderType = OrderType::create([
            'cash_flow_id' => 89,
            'currency_id' => 19,
            'name' => 'TakaPay Deposit',
            'type' => 1,
            'status' => 1,
            'key' => 'TAKAPAY',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            // 代收回調只能處理自己渠道的訂單（target/tickets#4449 需求 4）
            'agent_cash_flow_id' => $this->agentCashFlow->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 19,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'TDEP20240123001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '5000.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $providerTransactionTime = '2024-01-23 14:25:30';

        $callbackData = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'currencyCode' => 'BDT',
            'amount' => '5000.00',
            'reference' => 'TDEP20240123001',
            'transactionTime' => $providerTransactionTime,
            'systemTransactionId' => 'TAKAPAY_DEPOSIT_20240123001',
        ];

        $callbackData['securityToken'] = $this->generateValidSign($callbackData, 'test_api_secret');

        $request = Request::create('/callback', 'POST', $callbackData);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        $depositCallback->handle($request);

        $order->refresh();

        // 驗證交易時間已保存在 remark 中
        $remarkData = json_decode($order->remark, true);
        $this->assertIsArray($remarkData);
        $this->assertEquals($providerTransactionTime, $remarkData['transactionTime'] ?? null);
        $this->assertEquals('TAKAPAY_DEPOSIT_20240123001', $remarkData['systemTransactionId'] ?? null);
    }
}
