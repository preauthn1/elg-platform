<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GffPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\GffPay\Withdrawal;
use Tests\TestCase;

/**
 * GffPay 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
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
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'VIETCOMBANK'],
                ['BankCode_ELG' => '002', 'BankCode_Third' => 'TECHCOMBANK'],
            ],
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
     * 創建帶有自定義 sendRequest 實現的 Withdrawal 實例
     */
    private function mockWithdrawal(callable|array $sendRequestHandler): Withdrawal
    {
        return new class($this->agentCashFlow, $sendRequestHandler) extends Withdrawal
        {
            private $sendRequestHandler;

            public function __construct($agentCashFlow, $sendRequestHandler)
            {
                parent::__construct($agentCashFlow);
                $this->sendRequestHandler = $sendRequestHandler;
            }

            protected function sendRequest(string $path, array $params): array
            {
                if (is_callable($this->sendRequestHandler)) {
                    return ($this->sendRequestHandler)($path, $params);
                }

                return $this->sendRequestHandler;
            }
        };
    }

    /**
     * 測試成功創建代付請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21, // 代付提交中
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertEquals('', $result->errorMessage);
        $this->assertEquals('GFF_TP_123456', $result->providerOrderNo);

        // 同步受理：記錄維持 PENDING
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試 API 返回錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_api_error_response
     */
    public function test_api_error_response()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 400,
            'message' => 'Insufficient balance',
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Insufficient balance', $result->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $withdrawalRequestData = new WithdrawalRequestData; // 不存在的訂單

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('not found', $result->errorMessage);
    }

    /**
     * 測試訂單已有三方訂單號
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_order_already_submitted
     */
    public function test_order_already_submitted()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 22, // 代付處理中
            'third_party_order_no' => 'GFF2024012300001', // 已有三方訂單號
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        // $this->assertStringContainsString('already submitted', $result->errorMessage);
    }

    /**
     * 測試訂單狀態不正確
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_invalid_order_status
     */
    public function test_invalid_order_status()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 24, // 三方成功（不允許再次提交）
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('not allowed', $result->errorMessage);
    }

    /**
     * 測試網路異常
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_network_exception
     */
    public function test_network_exception()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $withdrawal = $this->mockWithdrawal(function () {
            throw new \Exception('Network timeout');
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Network timeout', $result->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試請求參數構建
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_request_parameters
     */
    public function test_request_parameters()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '9876543210',
            'account_name' => 'John Doe',
            'amount' => 123.45,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_987654',
            ],
        ];

        $actualPath = null;
        $actualParams = null;

        $withdrawal = $this->mockWithdrawal(function ($path, $params) use ($mockResponse, &$actualPath, &$actualParams) {
            $actualPath = $path;
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert - 驗證路徑
        $this->assertEquals('/withdraw-order', $actualPath);

        // Assert - 驗證參數
        $this->assertNotNull($actualParams);
        $this->assertEquals('VIETCOMBANK', $actualParams['currency_code']); // 轉換後的銀行代碼
        $this->assertEquals('test_merchant_no', $actualParams['merchant_code']);
        $this->assertStringStartsWith('GFF', $actualParams['merchant_order_no']); // 交易號前綴
        $this->assertEquals(123.45, $actualParams['amount']);
        $this->assertStringContainsString('/withdrawal/notify/', $actualParams['callback_url']);
        $this->assertEquals('9876543210', $actualParams['address']);
        $this->assertArrayHasKey('sign', $actualParams ?? []);

        // Assert - 驗證響應
        $this->assertTrue($result->isSuccess);
    }

    /**
     * 測試銀行代碼轉換
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_bank_code_conversion
     */
    public function test_bank_code_conversion()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '002', // 使用 target 銀行代碼
            'bank_name' => 'Test Bank 2',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $actualParams = null;

        $withdrawal = $this->mockWithdrawal(function ($path, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert - 銀行代碼應轉換為三方代碼
        $this->assertEquals('TECHCOMBANK', $actualParams['currency_code']);
    }

    /**
     * 測試銀行代碼不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_bank_code_not_found
     */
    public function test_bank_code_not_found()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '999', // 不存在的銀行代碼
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Bank code not set', $result->errorMessage);
    }

    /**
     * 測試缺少 code 欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_missing_code_in_response
     */
    public function test_missing_code_in_response()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = []; // 完全沒有 code 和 message

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Unknown error', $result->errorMessage);
    }

    /**
     * 測試 code 不等於 200
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_code_not_200
     */
    public function test_code_not_200()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 201, // 不等於 200
            'message' => 'Processing',
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Processing', $result->errorMessage);
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_large_amount
     */
    public function test_large_amount()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 999999.99, // 大額金額
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_LARGE',
            ],
        ];

        $actualParams = null;

        $withdrawal = $this->mockWithdrawal(function ($path, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertEquals(999999.99, $actualParams['amount']);
        $this->assertTrue($result->isSuccess);
    }

    /**
     * 測試 URL 正確性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_request_url
     */
    public function test_request_url()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $actualPath = null;

        $withdrawal = $this->mockWithdrawal(function ($path, $params) use ($mockResponse, &$actualPath) {
            $actualPath = $path;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertEquals('/withdraw-order', $actualPath);
    }

    /**
     * 測試 URL 正確性（帶尾斜線）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_url_with_trailing_slash
     */
    public function test_url_with_trailing_slash()
    {
        // Arrange - 創建新的 AgentCashFlow 帶有尾斜線的 URL
        $info = [
            'MerchantNo' => 'test_merchant_no',
            'MerchantKey' => 'test_merchant_key',
            'APIUrl' => 'https://example.invalid/',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'VIETCOMBANK'],
                ['BankCode_ELG' => '002', 'BankCode_Third' => 'TECHCOMBANK'],
            ],
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

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $actualPath = null;

        $withdrawal = new class($agentCashFlow, function ($path, $params) use ($mockResponse, &$actualPath) {
            $actualPath = $path;

            return $mockResponse;
        }) extends Withdrawal
        {

            private $sendRequestHandler;

            public function __construct($agentCashFlow, $sendRequestHandler)
            {
                parent::__construct($agentCashFlow);
                $this->sendRequestHandler = $sendRequestHandler;
            }

            protected function sendRequest(string $path, array $params): array
            {
                if (is_callable($this->sendRequestHandler)) {
                    return ($this->sendRequestHandler)($path, $params);
                }

                return $this->sendRequestHandler;
            }
        };

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert - URL 應該正確處理尾斜線
        $this->assertEquals('/withdraw-order', $actualPath);
    }

    /**
     * 測試簽名生成正確性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/GffPay/WithdrawalTest.php --filter test_signature_generation
     */
    public function test_signature_generation()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 200,
            'message' => 'Success',
            'data' => [
                'order_no' => 'GFF_TP_123456',
            ],
        ];

        $capturedSign = '';

        $withdrawal = $this->mockWithdrawal(function ($path, $params) use ($mockResponse, &$capturedSign) {
            $capturedSign = $params['sign'] ?? '';

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert - 簽名應該存在且為大寫 MD5（32 個字符）
        $this->assertNotNull($capturedSign);
        $this->assertEquals(32, strlen($capturedSign));
        $this->assertEquals(strtoupper($capturedSign), $capturedSign); // 應該是大寫
        $this->assertMatchesRegularExpression('/^[A-F0-9]{32}$/', $capturedSign); // MD5 格式

        // Assert - 響應正確
        $this->assertTrue($result->isSuccess);
    }
}
