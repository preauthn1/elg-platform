<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayPro;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Providers\PayPro\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayPro 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    protected Agent $agent;

    protected Currency $currency;

    protected Member $member;

    protected AgentCashFlow $agentCashFlow;

    protected function setUp(): void
    {
        parent::setUp();

        // 初始化 Agent
        $this->agent = Agent::factory()->create();

        // 初始化 Currency
        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'IDR',
            'name' => 'Indonesian Rupiah',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        // 初始化 Member
        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        // 初始化 AgentCashFlow
        $info = [
            'API_URL' => 'https://sandbox-api.paypro.tech/indonesia-pay',
            'MERCHANT_NO' => '0000000001',
            'MERCHANT_KEY' => 'test_merchant_key',
            'available_banks' => [
                [
                    'BankCode_ELG' => 'BCA',
                    'BankCode_Third' => 'BCA001',
                ],
                [
                    'BankCode_ELG' => 'BNI',
                    'BankCode_Third' => 'BNI001',
                ],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'rate' => 1.5,
            'admin' => 0,
            'info' => json_encode($info),
        ]);

        $this->agentCashFlow->info = $info;
    }

    /**
     * 測試成功創建代付請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_success_fiat
     */
    public function test_success_fiat()
    {
        // Arrange
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 21, // 代付提交中
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => '0000',
            'msg' => 'Success',
            'data' => [
                'platFormTradeNo' => 'PAYPRO20240101001',
            ],
        ];

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => Http::response($mockResponse, 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertEquals('PAYPRO20240101001', $result->providerOrderNo);

        // 同步受理：記錄維持 PENDING
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試 API 返回錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_api_error_response
     */
    public function test_api_error_response()
    {
        // Arrange
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'code' => '0001',
            'msg' => 'Invalid merchant',
            'data' => [
                'desc' => 'Merchant not found',
            ],
        ];

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => Http::response($mockResponse, 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Invalid merchant', $result->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_order_not_found
     */
    public function test_order_not_found()
    {
        // Arrange
        $withdrawalRequestData = new WithdrawalRequestData; // 不存在的訂單

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('not found', $result->errorMessage);
    }

    /**
     * 測試訂單狀態不允許代付
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_order_status_not_allowed
     */
    public function test_order_status_not_allowed()
    {
        // Arrange
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 24, // 已成功，不允許再次提交
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('not allowed', $result->errorMessage);
    }

    /**
     * 測試訂單已有三方訂單號
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_order_already_submitted
     */
    public function test_order_already_submitted()
    {
        // Arrange
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 21,
            'third_party_order_no' => 'EXISTING123', // 已有三方訂單號
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        // $this->assertStringContainsString('already submitted', $result->errorMessage);
    }

    /**
     * 測試 HTTP 連線錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_http_connection_error
     */
    public function test_http_connection_error()
    {
        // Arrange
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => Http::response([], 500),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
    }

    /**
     * 測試銀行代碼轉換
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_bank_code_conversion
     */
    public function test_bank_code_conversion()
    {
        // Arrange
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => function ($request) {
                $body = $request->data();
                // 驗證銀行代碼已轉換
                $this->assertEquals('BCA001', $body['bankCode']);

                return Http::response([
                    'code' => '0000',
                    'msg' => 'Success',
                    'data' => [
                        'platFormTradeNo' => 'PAYPRO20240101001',
                    ],
                ], 200);
            },
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
    }

    /**
     * 測試金額向下取整
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayPro/WithdrawalTest.php --filter test_amount_floor
     */
    public function test_amount_floor()
    {
        // Arrange
        $bank = Bank::firstOrCreate(['code' => 'BCA'], [
            'name' => 'Bank Central Asia',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100000.99, // 有小數點的金額
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        Http::fake([
            'https://sandbox-api.paypro.tech/indonesia-pay/*' => function ($request) {
                $body = $request->data();
                // 驗證金額已向下取整
                $this->assertEquals(100000.0, $body['amount']);

                return Http::response([
                    'code' => '0000',
                    'msg' => 'Success',
                    'data' => [
                        'platFormTradeNo' => 'PAYPRO20240101001',
                    ],
                ], 200);
            },
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
    }
}
