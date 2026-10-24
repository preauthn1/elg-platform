<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\Ap8Pay;

use App\Enums\DepositResponseType;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderRequestLog;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\Ap8Pay\Deposit;
use Tests\TestCase;

/**
 * Ap8Pay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/Ap8Pay/DepositTest.php
 */
class DepositTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $info = [
            'HeaderApiKey' => 'test_header_api_key',
            'MerchantApiKey' => 'test_merchant_api_key',
            'APIUrl' => 'https://example.invalid',
        ];

        $this->agentCashFlow = new AgentCashFlow([
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
     * 创建带有自定义 sendRequest 实现的 Deposit 实例
     *
     * @param  callable|array  $sendRequestHandler  如果是 callable，会调用它并传入 (url, params, headers)；如果是 array，直接返回该数组
     */
    private function mockDeposit(callable|array $sendRequestHandler): Deposit
    {
        return new class($this->agentCashFlow, $sendRequestHandler) extends Deposit
        {
            private $sendRequestHandler;

            public function __construct($agentCashFlow, $sendRequestHandler)
            {
                parent::__construct($agentCashFlow);
                $this->sendRequestHandler = $sendRequestHandler;
            }

            protected function sendRequest(string $url, array $params, array $headers): array
            {
                if (is_callable($this->sendRequestHandler)) {
                    return ($this->sendRequestHandler)($url, $params, $headers);
                }

                return $this->sendRequestHandler;
            }
        };
    }

    /**
     * 测试成功创建存款请求
     * php artisan test tests/Unit/Services/Payment/Providers/Ap8Pay/DepositTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => '479724394',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $expectedUrl = 'https://example.invalid/merchants/deposits';
        $expectedParams = [
            'apiKey' => 'test_merchant_api_key',
            'uniqueCode' => '479724394',
            'senderName' => $this->member->username,
            'senderCode' => (string) $this->member->id,
            'callbackUrl' => 'https://example.invalid/callback',
            'redirectUrl' => 'https://example.invalid/return',
        ];
        $expectedHeaders = [
            'api-key' => 'test_header_api_key',
        ];

        $mockResponse = [
            'data' => [
                'url' => 'https://example.invalid/pay?token=[REDACTED]',
            ],
        ];

        // 使用辅助方法创建 Deposit 实例
        $deposit = $this->mockDeposit($mockResponse);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertEquals('https://example.invalid/pay?token=[REDACTED]', $result->url);
        $this->assertEquals('479724394', $result->orderNo);
        $this->assertNull($result->html);
    }

    /**
     * 建單響應的 data.code 是查單用的 taskCode，必須落庫（target/tickets#4446）
     */
    public function test_task_code_is_persisted_as_third_party_order_no()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => '479724420',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // 回應形狀照 Dev 訂單 202610059431218273 的實際建單回應（GCP log Ap8Pay_Deposit）
        $deposit = $this->mockDeposit([
            'data' => [
                'url' => 'https://aaimy.ipay21.online/pZLH54xfraDi',
                'code' => '4dU8QTy70',
            ],
        ]);

        // Act
        $deposit->handle($depositRequestData);

        // Assert
        $order->refresh();
        $this->assertEquals('4dU8QTy70', $order->third_party_order_no);
    }

    /**
     * 建單往返要留紀錄（target/tickets#4446）：正式站沒有 GCP log，出站請求只剩這裡查得到
     */
    public function test_deposit_create_is_recorded()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => '479724425',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $deposit = $this->mockDeposit([
            'data' => ['url' => 'https://aaimy.ipay21.online/x', 'code' => '4dU8QTy70'],
        ]);

        // Act
        $deposit->handle(DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]));

        // Assert
        $log = OrderRequestLog::where('order_no', '479724425')->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame('deposit_create', $log->action);
        $this->assertSame('ok', $log->result);
        $this->assertSame('4dU8QTy70', $log->third_party_order_no);
        $this->assertSame('deposit_order', $log->orderable_type);
        $this->assertSame($order->id, $log->orderable_id);
        $this->assertStringContainsString('479724425', (string) $log->request_body);
    }

    /**
     * 测试 API 返回缺少 url 字段
     */
    public function test_missing_url_in_response()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => '479724395',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $mockResponse = [
            'data' => [
                // 缺少 url 字段
            ],
        ];

        // 使用辅助方法创建 Deposit 实例
        $deposit = $this->mockDeposit($mockResponse);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Ap8Pay deposit error: url not found');
        $deposit->handle($depositRequestData);
    }

    /**
     * 测试 API 返回缺少 data 字段
     */
    public function test_missing_data_in_response()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => '479724396',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $mockResponse = [
            // 缺少 data 字段
            'message' => 'Some error',
        ];

        // 使用辅助方法创建 Deposit 实例
        $deposit = $this->mockDeposit($mockResponse);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Ap8Pay deposit error: url not found');
        $deposit->handle($depositRequestData);
    }

    /**
     * 测试 sendRequest 抛出异常
     */
    public function test_send_request_exception()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => '479724397',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // 使用辅助方法创建 Deposit 实例，sendRequest 抛出异常
        $deposit = $this->mockDeposit(function () {
            throw new \Exception('Network error');
        });

        // Assert & Act
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Network error');
        $deposit->handle($depositRequestData);
    }

    /**
     * 测试 API URL 末尾有斜杠的情况
     */
    public function test_api_url_with_trailing_slash()
    {
        // Arrange
        $info = [
            'HeaderApiKey' => 'test_header_api_key',
            'MerchantApiKey' => 'test_merchant_api_key',
            'APIUrl' => 'https://example.invalid/', // 末尾有斜杠
        ];

        $this->agentCashFlow->info = $info;

        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => '479724398',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '100.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $mockResponse = [
            'data' => [
                'url' => 'https://example.invalid/pay?token=[REDACTED]',
            ],
        ];

        $actualUrl = null;

        $actualUrl = null;

        // 使用辅助方法创建 Deposit 实例，记录实际调用的 URL
        $deposit = $this->mockDeposit(function ($url, $params, $headers) use ($mockResponse, &$actualUrl) {
            $actualUrl = $url;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 验证 URL 应该移除末尾斜杠
        $this->assertEquals('https://example.invalid/merchants/deposits', $actualUrl);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals('https://example.invalid/pay?token=[REDACTED]', $result->url);
    }

    /**
     * 测试参数构建正确性
     */
    public function test_request_parameters()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Test Order Type',
            'type' => 1,
            'status' => 1,
            'key' => 'test',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'ORDER123456',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '200.00',
            'point' => 0,
            'remark' => '',
            'rate' => 1,
            'admin' => 0,
        ]);

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/deposit',
            'returnUrl' => 'https://example.invalid/deposit',
            'ip' => '0.0.0.0',
        ]);

        $expectedParams = [
            'apiKey' => 'test_merchant_api_key',
            'uniqueCode' => 'ORDER123456',
            'senderName' => $this->member->username,
            'senderCode' => (string) $this->member->id,
            'callbackUrl' => 'https://example.invalid/deposit',
            'redirectUrl' => 'https://example.invalid/deposit',
        ];

        $expectedHeaders = [
            'api-key' => 'test_header_api_key',
        ];

        $mockResponse = [
            'data' => [
                'url' => 'https://example.invalid/pay?token=[REDACTED]',
            ],
        ];

        $actualUrl = null;
        $actualParams = null;
        $actualHeaders = null;

        // 使用辅助方法创建 Deposit 实例，记录实际调用的参数
        $deposit = $this->mockDeposit(function ($url, $params, $headers) use ($mockResponse, &$actualUrl, &$actualParams, &$actualHeaders) {
            $actualUrl = $url;
            $actualParams = $params;
            $actualHeaders = $headers;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 验证参数
        $this->assertEquals('https://example.invalid/merchants/deposits', $actualUrl);
        $this->assertEquals($expectedParams['apiKey'], $actualParams['apiKey']);
        $this->assertEquals($expectedParams['uniqueCode'], $actualParams['uniqueCode']);
        $this->assertEquals($expectedParams['senderName'], $actualParams['senderName']);
        $this->assertEquals($expectedParams['senderCode'], $actualParams['senderCode']);
        $this->assertEquals($expectedParams['callbackUrl'], $actualParams['callbackUrl']);
        $this->assertEquals($expectedParams['redirectUrl'], $actualParams['redirectUrl']);
        $this->assertEquals($expectedHeaders['api-key'], $actualHeaders['api-key']);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals('ORDER123456', $result->orderNo);
    }
}
