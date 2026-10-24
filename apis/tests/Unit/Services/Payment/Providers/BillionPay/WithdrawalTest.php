<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BillionPay;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\BillionPay\Withdrawal;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * BillionPay 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php
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
            'SECRET_KEY' => 'test_secret_key',
            'USERNAME' => 'test_username',
            'PASSWORD' => 'test_password',
            'LOGIN_URL' => 'https://example.invalid/api/login',
            'DEPOSIT_URL' => 'https://example.invalid/api/deposit',
            'WITHDRAWAL_URL' => 'https://example.invalid/api/withdrawal',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'VIETCOMBANK'],
                ['BankCode_ELG' => '002', 'BankCode_Third' => 'TECHCOMBANK'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1,
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
     * 測試成功創建代付請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        /**
         * @var WithdrawalOrder $withdrawalOrder
         */
        // 會員提款訂單：agent_id = 0、member_id > 0（與正式資料一致，兩欄位互斥）
        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => 0,
            'member_id' => $this->member->id,
            'bank_code' => '001',
            'bank_name' => 'Test Bank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value, // 代付提交中
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $capturedUrl = null;
        $capturedParams = null;

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use (&$capturedUrl, &$capturedParams) {
            $capturedUrl = $url;
            $capturedParams = $params;

            return [
                'transaction_id' => '2025112900000020',
                'internal_id' => '202512011764563595028682',
                'currency' => 'SGD',
                'amount' => '10000.00',
            ];
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $withdrawalOrder->refresh();
        Http::assertSentCount(2);

        // Assert - URL 正確性
        $this->assertEquals('https://example.invalid/api/withdrawal', $capturedUrl);

        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertEquals('', $result->errorMessage);
        $this->assertStringContainsString('Billion', $capturedParams['transaction_id']);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertEquals('card', $capturedParams['method']);
        // customer_id 取 owner->username：會員提款訂單的下單者是會員
        $this->assertEquals((string) $this->member->username, (string) $capturedParams['customer_id']);
        $this->assertEquals('202512011764563595028682', $withdrawalOrder->third_party_order_no);

        // Assert - 銀行代碼應轉換為三方代碼
        $this->assertEquals('VIETCOMBANK', $capturedParams['bank']);

        // Assert - 簽名應存在且為小寫 MD5（32 個字符）
        $this->assertNotNull($capturedParams['signature'] ?? null);
        $this->assertEquals(32, strlen($capturedParams['signature']));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $capturedParams['signature']);
    }

    /**
     * 測試 API 返回錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_api_error_response
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

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_order_not_found
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
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_order_already_submitted
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
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_invalid_order_status
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
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_network_exception
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
        $this->assertTrue($result->isIndeterminate);
        $this->assertStringContainsString('Network timeout', $result->errorMessage);

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    /**
     * 前置路徑：login 失敗＝代付請求未發出 → 直接失敗回應、
     * isIndeterminate=false、不建 payout 記錄（訂單維持原狀態可重送）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_login_failure
     */
    public function test_login_failure_returns_failure_without_record(): void
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

        Http::fake([
            'https://example.invalid/api/login' => Http::response(['message' => 'invalid credentials'], 401),
            'https://example.invalid/api/withdrawal' => Http::response([], 500),
        ]);

        $withdrawal = App::make(Withdrawal::class, ['agentCashFlow' => $this->agentCashFlow]);

        // Act
        $result = $withdrawal->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        // Assert
        $this->assertFalse($result->isSuccess);
        $this->assertFalse($result->isIndeterminate);
        $this->assertSame(0, WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->count());
    }

    /**
     * 測試大額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_large_amount
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

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use (&$actualParams) {
            $actualParams = $params;

            return [
                'transaction_id' => 'BillionTest001',
                'internal_id' => '202512011764563595000001',
                'currency' => 'SGD',
                'amount' => '999999.99',
            ];
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertEquals(999999.99, $actualParams['amount']);
        $this->assertTrue($result->isSuccess);
    }

    /**
     * 測試小額金額處理
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_small_amount
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

        $withdrawal = $this->mockWithdrawal(function ($url, $params) use (&$actualParams) {
            $actualParams = $params;

            return [
                'transaction_id' => 'BillionTest002',
                'internal_id' => '202512011764563595000002',
                'currency' => 'SGD',
                'amount' => '1.99',
            ];
        });

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertEquals(1.99, $actualParams['amount']);
        $this->assertTrue($result->isSuccess);
    }

    /**
     * 測試缺少 code 欄位
     *
     * php artisan test tests/Unit/Services/Payment/Providers/BillionPay/WithdrawalTest.php --filter test_missing_code_in_response
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
     * Mock 代付請求
     */
    private function mockWithdrawal(array|callable $mockResponse): Withdrawal
    {
        Http::fake([
            'https://example.invalid/api/login' => Http::response([
                'bearer_token' => 'test_bearer_token',
                'token_type' => 'Bearer',
                'expires_at' => '3024-10-30 13:48:25',
            ]),
            'https://example.invalid/api/withdrawal' => function ($request) use ($mockResponse) {
                if (is_callable($mockResponse)) {
                    return Http::response($mockResponse($request->url(), $request->data()));
                }

                return Http::response($mockResponse);
            },
        ]);

        return App::make(Withdrawal::class, ['agentCashFlow' => $this->agentCashFlow]);
    }
}
