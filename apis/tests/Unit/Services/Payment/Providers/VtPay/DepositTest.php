<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VtPay;

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
use App\Services\Payment\Providers\VtPay\Deposit;
use Tests\TestCase;

/**
 * VtPay 代收測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VtPay/DepositTest.php
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
            'API_URL' => 'https://b3.vtpay.one/2009586434014167041-7a4',
            'API_SECRET' => 'dRytW3eQ9CPj2r3/0eOx/Q==',
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
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/DepositTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'E-Wallet',
            'type' => 1,
            'key' => 'TNG',
            'admin' => 0,
        ]);
        $orderType->save();

        $order = Order::factory()->create([
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'order_no' => 'TEST123456',
            'amount' => 100.00,
            'status' => OrderStatusEnum::CREATED,
            'admin' => 0,
        ]);

        // Mock 成功響應
        $mockResponse = [
            'error_code' => 200,
            'data' => [
                'order_id' => 'VtPay123456789',
                'order_amount' => 100.00,
                'request_amount' => 100.00,
                'url' => [
                    'https://example.invalid/pay?token=[REDACTED]',
                ],
                'token' => 'abc123',
            ],
        ];

        $deposit = $this->mockDeposit($mockResponse);

        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // Act
        $response = $deposit->handle($request);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $response);
        $this->assertEquals(DepositResponseType::URL_REDIRECT, $response->type);
        $this->assertStringContainsString('example.invalid', $response->url);
        $this->assertEquals('TEST123456', $response->orderNo);
        $this->assertEquals('VtPay123456789', $response->providerOrderNo);
    }

    /**
     * 測試 API 返回錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/DepositTest.php --filter test_api_error
     */
    public function test_api_error()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'E-Wallet',
            'type' => 1,
            'key' => 'TNG',
            'admin' => 0,
        ]);

        $order = Order::factory()->create([
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'order_no' => 'TEST123457',
            'amount' => 100.00,
            'status' => OrderStatusEnum::CREATED,
            'admin' => 0,
        ]);

        // Mock 錯誤響應
        $mockResponse = [
            'error_code' => 400,
            'message' => 'Invalid parameters',
        ];

        $deposit = $this->mockDeposit($mockResponse);

        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('VtPay deposit error: Invalid parameters');

        $deposit->handle($request);
    }

    /**
     * 測試響應中缺少 error_code
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/DepositTest.php --filter test_missing_error_code
     */
    public function test_missing_error_code()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'E-Wallet',
            'type' => 1,
            'key' => 'TNG',
            'admin' => 0,
        ]);

        $order = Order::factory()->create([
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'order_no' => 'TEST123458',
            'amount' => 100.00,
            'status' => OrderStatusEnum::CREATED,
            'admin' => 0,
        ]);

        // Mock 缺少 error_code 的響應
        $mockResponse = [
            'data' => [],
        ];

        $deposit = $this->mockDeposit($mockResponse);

        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // Assert & Act
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('VtPay deposit error: Unknown error');

        $deposit->handle($request);
    }

    /**
     * 測試 URL 為字串格式（兼容性測試）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/VtPay/DepositTest.php --filter test_url_as_string
     */
    public function test_url_as_string()
    {
        // Arrange
        $orderType = OrderType::create([
            'cash_flow_id' => 1,
            'currency_id' => 1,
            'name' => 'E-Wallet',
            'type' => 1,
            'key' => 'TNG',
            'admin' => 0,
        ]);

        $order = Order::factory()->create([
            'member_id' => $this->member->id,
            'order_type_id' => $orderType->id,
            'order_no' => 'TEST123459',
            'amount' => 100.00,
            'status' => OrderStatusEnum::CREATED,
            'admin' => 0,
        ]);

        // Mock URL 為字串的響應
        $mockResponse = [
            'error_code' => 200,
            'data' => [
                'order_id' => 'VtPay123456790',
                'url' => 'https://example.invalid/pay?token=[REDACTED]',
            ],
        ];

        $deposit = $this->mockDeposit($mockResponse);

        $request = DepositRequestData::from([
            'member' => $this->member,
            'order' => $order,
            'callbackUrl' => 'https://example.invalid/callback',
            'returnUrl' => 'https://example.invalid/return',
            'ip' => '0.0.0.0',
        ]);

        // Act
        $response = $deposit->handle($request);

        // Assert
        $this->assertInstanceOf(DepositResponseData::class, $response);
        $this->assertStringContainsString('example.invalid', $response->url);
    }
}
