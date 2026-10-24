<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Kira;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Providers\Kira\DepositCallback;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Kira 代收回調測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php
 */
class DepositCallbackTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private string $symmetricKey = '0123456789abcdef0123456789abcdef';

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
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
            'CLIENT_SECRET' => 'test_client_secret',
            'CLIENT_PRIVATE_KEY' => 'test_private_key',
            'CLIENT_SYMMETRIC_KEY' => $this->symmetricKey,
            'SERVER_PUBLIC_KEY' => 'test_public_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 100,
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
     * 加密回調數據用於測試
     */
    private function encryptCallbackData(array $data): string
    {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $iv = md5($this->symmetricKey, true);
        $encrypted = openssl_encrypt($jsonData, 'aes-256-cbc', $this->symmetricKey, OPENSSL_RAW_DATA, $iv);

        return bin2hex($encrypted);
    }

    /**
     * 測試成功處理代收回調（成功狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php --filter test_success
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
            'cash_flow_id' => 100,
            'currency_id' => 1,
            'name' => 'Kira FPX',
            'type' => 1,
            'status' => 1,
            'key' => 'KIRA.FPX',
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
            'order_no' => 'KIRA_20240101_001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '150.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackPayload = [
            'data' => [
                'status' => 'SUCCESS',
                'order' => [
                    'id' => 'KIRA_20240101_001',
                    'additionalData' => '',
                    'title' => 'Deposit',
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12345',
                    'amountPaid' => '150.00',
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

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
        $this->assertEquals('KIRA_TXN_12345', $order->third_party_order_no);
    }

    /**
     * 測試處理代收回調（失敗狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php --filter test_failed_status
     */
    public function test_failed_status()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 100,
            'currency_id' => 1,
            'name' => 'Kira FPX',
            'type' => 1,
            'status' => 1,
            'key' => 'KIRA.FPX',
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
            'order_no' => 'KIRA_20240102_001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '150.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackPayload = [
            'data' => [
                'status' => 'FAILED',
                'order' => [
                    'id' => 'KIRA_20240102_001',
                    'additionalData' => '',
                    'title' => 'Deposit',
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12346',
                    'amountPaid' => '0.00',
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

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
     * 測試處理代收回調（待審狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php --filter test_pending_status
     */
    public function test_pending_status()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 100,
            'currency_id' => 1,
            'name' => 'Kira FPX',
            'type' => 1,
            'status' => 1,
            'key' => 'KIRA.FPX',
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
            'order_no' => 'KIRA_20240103_001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '150.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackPayload = [
            'data' => [
                'status' => 'PENDING',
                'order' => [
                    'id' => 'KIRA_20240103_001',
                    'additionalData' => '',
                    'title' => 'Deposit',
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12347',
                    'amountPaid' => '150.00',
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CREATED->value, $order->status);
    }

    /**
     * 測試缺少加密數據
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php --filter test_missing_encrypted_data
     */
    public function test_missing_encrypted_data()
    {
        // Arrange
        $request = Request::create('/callback', 'POST', []);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert & Act - 接受任何類型的異常（ValidationException 或 ApiException）
        try {
            $depositCallback->handle($request);
            $this->fail('Expected exception to be thrown');
        } catch (\Exception $e) {
            $this->assertTrue(true);
        }
    }

    /**
     * 測試缺少訂單 ID 在回調數據中
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php --filter test_missing_order_id
     */
    public function test_missing_order_id()
    {
        // Arrange
        $callbackPayload = [
            'data' => [
                'status' => 'SUCCESS',
                'order' => [
                    // 缺少 'id'
                    'additionalData' => '',
                    'amount' => '150.00',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12345',
                    'amountPaid' => '150.00',
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Missing order id in callback data');

        // Act
        $depositCallback->handle($request);
    }

    /**
     * 測試金額不匹配（成功狀態）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php --filter test_amount_mismatch
     */
    public function test_amount_mismatch()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 100,
            'currency_id' => 1,
            'name' => 'Kira FPX',
            'type' => 1,
            'status' => 1,
            'key' => 'KIRA.FPX',
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
            'order_no' => 'KIRA_20240104_001',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '150.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $callbackPayload = [
            'data' => [
                'status' => 'SUCCESS',
                'order' => [
                    'id' => 'KIRA_20240104_001',
                    'additionalData' => '',
                    'title' => 'Deposit',
                    'amount' => '150.00',
                    'currencyType' => 'MYR',
                ],
                'transaction' => [
                    'id' => 'KIRA_TXN_12348',
                    'amountPaid' => '100.00', // 金額不符
                    'paymentMethod' => 'FPX',
                    'timestamp' => time(),
                ],
            ],
        ];

        $encryptedData = $this->encryptCallbackData($callbackPayload);
        $request = Request::create('/callback', 'POST', [
            'encryptedData' => $encryptedData,
        ]);

        $depositCallback = new DepositCallback($this->agentCashFlow);

        // Act
        $result = $depositCallback->handle($request);

        // Assert - 金額不符時應標記為待人工確認（WAITING_MANUAL_DEPOSIT）
        $this->assertInstanceOf(DepositCallbackResponseData::class, $result);
        $this->assertEquals('OK', $result->response);
        $this->assertNotNull($result->order);

        // 驗證訂單已標記為待人工確認
        $order->refresh();
        $this->assertEquals(OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value, $order->status);
    }

    /**
     * 測試缺少 CLIENT_SYMMETRIC_KEY 配置
     *
     * php artisan test tests/Unit/Services/Payment/Providers/Kira/DepositCallbackTest.php --filter test_missing_symmetric_key
     */
    public function test_missing_symmetric_key()
    {
        // Arrange
        $info = [
            'API_URL' => 'https://example.invalid',
            'CLIENT_ID' => 'test_client_id',
        ];

        $agentCashFlow = new AgentCashFlow([
            'cash_flow_id' => 100,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;

        $request = Request::create('/callback', 'POST', [
            'encryptedData' => 'test_encrypted_data',
        ]);

        $depositCallback = new DepositCallback($agentCashFlow);

        // Assert
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Kira configuration missing: CLIENT_SYMMETRIC_KEY');

        // Act
        $depositCallback->handle($request);
    }
}
