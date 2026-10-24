<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\YeePay;

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
use App\Services\Payment\Providers\YeePay\Deposit;
use Tests\TestCase;

/**
 * YeePay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php
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
            'MERCHANT_UID' => 'test_merchant_uid',
            'MERCHANT_NUM' => 'test_merchant_num',
            'API_SECRET' => 'test_api_secret',
            'DEPOSIT_URL' => 'https://example.invalid/deposit',
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

            protected function sendRequest(string $url, array $params, array $headers = []): array
            {
                if (is_callable($this->sendRequestHandler)) {
                    return ($this->sendRequestHandler)($url, $params, $headers);
                }

                return $this->sendRequestHandler;
            }
        };
    }

    /**
     * 測試成功創建代收請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_success
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
            'key' => '9', // YeePay bank-transfer 類型
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
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

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $mockResponse = [
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https%3A%2F%example.invalid%2Fpay%3Ftoken%3Dabc123',
        ];

        // 使用輔助方法創建 Deposit 實例
        $deposit = $this->mockDeposit($mockResponse);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $result->type);
        $this->assertEquals('https://example.invalid/pay?token=[REDACTED]', $result->url);
        $this->assertEquals('YEE123456789', $result->orderNo);
        $this->assertNull($result->html);
    }

    /**
     * 測試 API 返回錯誤代碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_api_error_response
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
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
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

        $depositRequestData = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        $mockResponse = [
            'code' => 0,
            'message' => 'Insufficient balance',
        ];

        $deposit = $this->mockDeposit($mockResponse);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('YeePay deposit error: Insufficient balance');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 API 返回缺少 code 欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_missing_code_in_response
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
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456791',
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
        $this->expectExceptionMessage('YeePay deposit error: Unknown error');
        $deposit->handle($depositRequestData);
    }

    /**
     * 測試 sendRequest 拋出異常
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_send_request_exception
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
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE123456792',
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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_request_parameters
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
            'key' => '8', // MoMo 類型
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEE987654321',
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
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https://example.invalid/pay?token=[REDACTED]',
        ];

        $actualUrl = null;
        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params, $headers) use ($mockResponse, &$actualUrl, &$actualParams) {
            $actualUrl = $url;
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 驗證 URL
        $this->assertEquals('https://example.invalid/deposit', $actualUrl);

        // Assert - 驗證參數
        $this->assertEquals('test_merchant_uid', $actualParams['uid']);
        $this->assertEquals('test_merchant_num', $actualParams['merchant_num']);
        $this->assertEquals('YEE987654321', $actualParams['merchant_order']);
        $this->assertEquals('200.00', $actualParams['coin']);
        $this->assertEquals('https://example.invalid/deposit', $actualParams['pay_callbackurl']);
        $this->assertEquals('https://example.invalid/deposit', $actualParams['pay_notifyurl']);
        $this->assertEquals('8', $actualParams['pay_type']);
        $this->assertEquals($this->member->username, $actualParams['userinfo']);
        $this->assertEquals('0.0.0.0', $actualParams['user_ip']);
        $this->assertArrayHasKey('sign', $actualParams);
        $this->assertArrayHasKey('pay_date', $actualParams);

        // Assert - 驗證響應
        $this->assertInstanceOf(DepositResponseData::class, $result);
        $this->assertEquals('YEE987654321', $result->orderNo);
    }

    /**
     * 測試簽名生成正確性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_signature_generation
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
            'key' => '7',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEESIGN123',
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
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https://example.invalid/pay',
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
     * 測試 URL 解碼
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_url_decode
     */
    public function test_url_decode()
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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEEURLDECODE',
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

        // 模擬返回 URL 編碼的支付連結
        $mockResponse = [
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https%3A%2F%example.invalid%2Fpay%3Ftoken%3Dabc123%26order%3D456',
        ];

        $deposit = $this->mockDeposit($mockResponse);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - URL 應該被解碼
        $this->assertEquals('https://example.invalid/pay?token=[REDACTED]', $result->url);
    }

    /**
     * 測試金額格式處理（小數點）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_amount_with_decimals
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
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEEDECIMAL',
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
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https://example.invalid/pay',
        ];

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - 金額應保留小數點
        $this->assertEquals('123.45', $actualParams['coin']);
    }

    /**
     * 測試 payurl 為 null 的情況
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_null_payurl
     */
    public function test_null_payurl()
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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEENULLURL',
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
            'code' => 1,
            'message' => 'Success',
            'payurl' => null, // payurl 為 null
        ];

        $deposit = $this->mockDeposit($mockResponse);

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - payurl 為 null 時應返回空字串
        $this->assertEquals('', $result->url);
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_large_amount
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
            'key' => '9',
            'info' => '{}',
            'admin' => 0,
        ]);

        $order = Order::create([
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEELARGE',
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
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https://example.invalid/pay',
        ];

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert
        $this->assertEquals('999999.99', $actualParams['coin']);
        $this->assertInstanceOf(DepositResponseData::class, $result);
    }

    /**
     * 測試特殊字符在 IP 中的處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_ip_address_format
     */
    public function test_ip_address_format()
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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEEIP',
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
            'ip' => '::1', // IPv6 地址
        ]);

        $mockResponse = [
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https://example.invalid/pay',
        ];

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - IPv6 地址應能正確傳遞
        $this->assertEquals('::1', $actualParams['user_ip']);
    }

    /**
     * 測試 extend 欄位為空字串
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/DepositTest.php --filter test_extend_field_empty
     */
    public function test_extend_field_empty()
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
            'agent_id' => $this->agent->id,
            'order_type_id' => $orderType->id,
            'currency_id' => 1,
            'member_id' => $this->member->id,
            'third_party_order_no' => '',
            'order_no' => 'YEEEXTEND',
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
            'code' => 1,
            'message' => 'Success',
            'payurl' => 'https://example.invalid/pay',
        ];

        $actualParams = null;

        $deposit = $this->mockDeposit(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $deposit->handle($depositRequestData);

        // Assert - extend 欄位應該存在且為空字串
        $this->assertArrayHasKey('extend', $actualParams);
        $this->assertEquals('', $actualParams['extend']);
    }
}
