<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\YeePay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\YeePay\Withdrawal;
use Tests\TestCase;

/**
 * YeePay 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php
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
            'MERCHANT_UID' => 'test_merchant_uid',
            'MERCHANT_NUM' => 'test_merchant_num',
            'API_SECRET' => 'test_api_secret',
            'WITHDRAWAL_URL' => 'https://example.invalid/withdrawal',
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
     * 測試成功創建代付請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_success
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
            'code' => 1,
            'message' => 'Success',
        ];

        $withdrawal = $this->mockWithdrawal($mockResponse);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertEquals('', $result->errorMessage);

        // 同步受理：記錄維持 PENDING，等回調確認
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試 API 返回錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_api_error_response
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
            'code' => 0,
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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $withdrawalRequestData = new WithdrawalRequestData; // 不存在的訂單

        $mockResponse = [
            'code' => 1,
            'message' => 'Success',
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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_order_already_submitted
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
            'third_party_order_no' => 'YP2024012300001', // 已有三方訂單號
            'admin' => 0,

            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 1,
            'message' => 'Success',
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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_invalid_order_status
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
            'code' => 1,
            'message' => 'Success',
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
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_network_exception
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
    }

    /**
     * 測試請求參數構建（金額無小數點）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_request_parameters_no_decimal
     */
    public function test_request_parameters_no_decimal()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '9876543210',
            'account_name' => 'John Doe',
            'amount' => 123.45, // 有小數點的金額
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,

            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 1,
            'message' => 'Success',
        ];

        $actualParams = null;

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert - 金額應該轉為整數（無小數點）
        $this->assertEquals('123', $actualParams['coin']);
        $this->assertEquals('test_merchant_uid', $actualParams['uid']);
        $this->assertEquals('test_merchant_num', $actualParams['merchant_num']);
        $this->assertEquals('9876543210', $actualParams['target_bank']);
        $this->assertEquals('John Doe', $actualParams['target_bank_user']);
        $this->assertEquals('VIETCOMBANK', $actualParams['bank_name']); // 轉換後的銀行代碼
        $this->assertArrayHasKey('sign', $actualParams);
        $this->assertArrayHasKey('order', $actualParams);
        $this->assertStringStartsWith('YEE', $actualParams['order']); // 交易號前綴
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_large_amount
     */
    public function test_large_amount()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '002',
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
            'code' => 1,
            'message' => 'Success',
        ];

        $actualParams = null;

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertEquals('1000000', $actualParams['coin']); // 轉為整數
        $this->assertTrue($result->isSuccess);
    }

    /**
     * 測試小額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_small_amount
     */
    public function test_small_amount()
    {
        // Arrange
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 1.99, // 小額金額
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,

            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => 1,
            'message' => 'Success',
        ];

        $actualParams = null;

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertEquals('2', $actualParams['coin']); // 四捨五入為整數
        $this->assertTrue($result->isSuccess);
    }

    /**
     * 測試銀行代碼轉換
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_bank_code_conversion
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
            'code' => 1,
            'message' => 'Success',
        ];

        $actualParams = null;

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use ($mockResponse, &$actualParams) {
            $actualParams = $params;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert - 銀行代碼應轉換為三方代碼
        $this->assertEquals('TECHCOMBANK', $actualParams['bank_name']);
    }

    /**
     * 測試缺少 code 欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_missing_code_in_response
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
     * 測試 URL 正確性
     *
     * php artisan test tests/Unit/Services/Payment/Providers/YeePay/WithdrawalTest.php --filter test_request_url
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
            'code' => 1,
            'message' => 'Success',
        ];

        $actualUrl = null;

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use ($mockResponse, &$actualUrl) {
            $actualUrl = $url;

            return $mockResponse;
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertEquals('https://example.invalid/withdrawal', $actualUrl);
    }
}
