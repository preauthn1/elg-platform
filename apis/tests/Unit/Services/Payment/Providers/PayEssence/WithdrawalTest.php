<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\PayEssence;

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
use App\Services\Payment\Providers\PayEssence\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * PayEssence 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'MERCHANT_CODE' => 'TEST_MERCHANT',
            'SECRET_KEY' => 'test_secret_key',
            'API_URL' => 'https://example.invalid',
            'available_banks' => [
                ['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB'],
                ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB'],
                ['BankCode_ELG' => 'ERC20', 'BankCode_Third' => 'ERC20'],
                ['BankCode_ELG' => 'TRC20', 'BankCode_Third' => 'TRC20'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'currency_id' => $this->currency->id,
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
     * 測試成功創建代付請求
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalTest.php --filter test_success
     */
    public function test_success()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'Result' => '35001', // Pending
            'PayoutId' => 'PO2024012300001',
        ];

        Http::fake([
            'example.invalid/*' => Http::response($mockResponse, 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertTrue($result->isSuccess);
        $this->assertEquals('PO2024012300001', $result->providerOrderNo);
        // 編排器統一成功回應：errorMessage 為空字串
        $this->assertSame('', $result->errorMessage);

        // 同步受理：記錄維持 PENDING
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試 API 返回錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalTest.php --filter test_api_error_response
     */
    public function test_api_error_response()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        $mockResponse = [
            'Result' => '1102', // Invalid merchant code
        ];

        Http::fake([
            'example.invalid/*' => Http::response($mockResponse, 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('Merchant Code Invalid', $result->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試 HTTP 錯誤
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalTest.php --filter test_http_error
     */
    public function test_http_error()
    {
        // Arrange
        $bank = Bank::where('code', 'MAYBANK')->first() ?? Bank::create([
            'name' => 'Maybank',
            'code' => 'MAYBANK',
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'bank_id' => $bank->id,
            'bank_code' => 'MAYBANK',
            'bank_name' => 'Maybank',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);

        $withdrawalRequestData = new WithdrawalRequestData(order: $withdrawalOrder);

        Http::fake([
            'example.invalid/*' => Http::response([], 500),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        // Act
        $result = $withdrawal->handle($withdrawalRequestData);

        // Assert
        $this->assertInstanceOf(WithdrawalResponseData::class, $result);
        $this->assertFalse($result->isSuccess);
        $this->assertStringContainsString('HTTP 500', $result->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試訂單不存在
     *
     * php artisan test tests/Unit/Services/Payment/Providers/PayEssence/WithdrawalTest.php --filter test_order_not_found
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
}
