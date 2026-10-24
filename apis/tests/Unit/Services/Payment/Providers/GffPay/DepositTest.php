<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GffPay;

use App\Enums\DepositResponseType;
use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\Order;
use App\Models\OrderType;
use App\Services\Payment\Data\DepositRequestData;
use App\Services\Payment\Data\DepositResponseData;
use App\Services\Payment\Providers\GffPay\Deposit;
use Tests\TestCase;

/**
 * GffPay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php
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
            'MerchantNo' => 'test_merchant_no',
            'MerchantKey' => 'test_merchant_key',
            'APIUrl' => 'https://example.invalid',
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
     * 創建帶有自定義 sendRequest 實現的 Deposit 實例
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

            protected function sendRequest(string $url, array $params): array
            {
                if (is_callable($this->sendRequestHandler)) {
                    return ($this->sendRequestHandler)($url, $params);
                }

                return $this->sendRequestHandler;
            }
        };
    }

    /**
     * 測試成功創建代收請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_success
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

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'payment_url' => 'https://example.invalid/pay?token=[REDACTED]',
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        // 使用輔助方法創建 Deposit 實例
        $deposit = $this->mockDeposit($mockResponse);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertEquals('https://example.invalid/pay?token=[REDACTED]', $result->url);
        $this->assertEquals('GFF123456789', $result->orderNo);
        $this->assertEquals('GFF_TP_123456', $result->providerOrderNo);
    }

    /**
     * 測試 API 返回錯誤代碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_api_error_response
     */
    public function test_api_error_response()
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

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $mockResponse = [
            'code' => 400,
            'message' => 'Insufficient balance',
        ];

        $deposit = $this->mockDeposit($mockResponse);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('GffPay deposit error: Insufficient balance');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 API 返回缺少 code 欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_missing_code_in_response
     */
    public function test_missing_code_in_response()
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

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // 響應中既沒有 code 也沒有 message，應使用預設錯誤訊息
        $mockResponse = [];

        $deposit = $this->mockDeposit($mockResponse);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('GffPay deposit error: Unknown error');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 sendRequest 拋出異常
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_send_request_exception
     */
    public function test_send_request_exception()
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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF123456792',
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

        $deposit = $this->mockDeposit(function () {
            throw new \Exception('Network timeout');
        });

        // Assert & Act
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Network timeout');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試請求參數構建正確性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_request_parameters
     */
    public function test_request_parameters()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'MoMo',
            'type' => 1,
            'status' => 1,
            'key' => 'VND',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFF987654321',
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

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'payment_url' => 'https://example.invalid/pay?token=[REDACTED]',
                'order_no' => 'GFF_TP_987654',
            ],
        ];

        $actualUrl = null;
        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$actualUrl, &$actualParams) {
            $actualUrl = $url;
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 驗證 URL
        $this->assertEquals('https://example.invalid/deposit-order', $actualUrl);

        // Assert - 驗證參數
        $this->assertEquals('VND', $actualParams['currency_code']);
        $this->assertEquals('test_merchant_no', $actualParams['merchant_code']);
        $this->assertEquals('GFF987654321', $actualParams['merchant_order_no']);
        $this->assertEquals('200.00', $actualParams['amount']);
        $this->assertEquals('https://example.invalid/deposit', $actualParams['callback_url']);
        $this->assertEquals('https://example.invalid/deposit', $actualParams['return_url']);
        $this->assertArrayHasKey('sign', $actualParams);

        // Assert - 驗證響應
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals('GFF987654321', $result->orderNo);
    }

    /**
     * 測試簽名生成正確性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_signature_generation
     */
    public function test_signature_generation()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'Bank QR',
            'type' => 1,
            'status' => 1,
            'key' => 'USD',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFFSIGN123',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '150.00',
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
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'payment_url' => 'https://example.invalid/pay',
                'order_no' => 'GFF_TP_SIGN',
            ],
        ];

        $capturedSign = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$capturedSign) {
            $capturedSign = $params['sign'] ?? null;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 簽名應該存在且為大寫 MD5（32 個字符）
        $this->assertNotNull($capturedSign);
        $this->assertEquals(32, strlen($capturedSign));
        $this->assertEquals(strtoupper($capturedSign), $capturedSign); // 應該是大寫
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $capturedSign); // MD5 格式

        // Assert - 響應正確
        $this->assertInstanceOf(DepositResponseData::class, $result);
    }

    /**
     * 測試金額格式處理（小數點）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_amount_with_decimals
     */
    public function test_amount_with_decimals()
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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFFDECIMAL',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '123.45', // 含小數點的金額
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
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'payment_url' => 'https://example.invalid/pay',
                'order_no' => 'GFF_TP_DECIMAL',
            ],
        ];

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 金額應保留小數點
        $this->assertEquals('123.45', $actualParams['amount']);
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_large_amount
     */
    public function test_large_amount()
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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFFLARGE',
            'status' => OrderStatusEnum::CREATED->value,
            'balance' => 0,
            'amount' => '999999.99', // 大額金額
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
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'payment_url' => 'https://example.invalid/pay',
                'order_no' => 'GFF_TP_LARGE',
            ],
        ];

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertEquals('999999.99', $actualParams['amount']);
        $this->assertInstanceOf(DepositResponseData::class, $result);
    }

    /**
     * 測試 URL 正確性（帶尾斜線）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/DepositTest.php --filter test_url_with_trailing_slash
     */
    public function test_url_with_trailing_slash()
    {
        // Arrange - 創建新的 AgentCashFlow 帶有尾斜線的 URL
        $info = [
            'MerchantNo' => 'test_merchant_no',
            'MerchantKey' => 'test_merchant_key',
            'APIUrl' => 'https://example.invalid/',
        ];

        $agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $agentCashFlow->info = $info;
        $agentCashFlow->save();

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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'GFFURL',
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
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'payment_url' => 'https://example.invalid/pay',
                'order_no' => 'GFF_TP_URL',
            ],
        ];

        $actualUrl = null;

        $deposit = new class($agentCashFlow, function ($url, $params) use ($mockResponse, &$actualUrl) {
            $actualUrl = $url;

            return $mockResponse;
        }) extends Deposit
        {

            private $sendRequestHandler;

            public function __construct($agentCashFlow, $sendRequestHandler)
            {
                parent::__construct($agentCashFlow);
                $this->sendRequestHandler = $sendRequestHandler;
            }

            protected function sendRequest(string $url, array $params): array
            {
                if (is_callable($this->sendRequestHandler)) {
                    return ($this->sendRequestHandler)($url, $params);
                }

                return $this->sendRequestHandler;
            }
        };

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - URL 應該正確處理尾斜線
        $this->assertEquals('https://example.invalid/deposit-order', $actualUrl);
    }
}
