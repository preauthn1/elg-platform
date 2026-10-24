<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\TakaPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Models\CashFlowBank;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\TakaPay\TakaPayStatusCodeEnum;
use App\Services\Payment\Providers\TakaPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * TakaPay Withdrawal 單元測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/WithdrawalTest.php
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

        $this->currency = Currency::findOr(19, fn () => Currency::forceCreate([
            'id' => 19,
            'code' => 'BDT',
            'name' => 'Bangladeshi Taka',
            'status' => 1,
            'admin' => 0,
            'rate' => 1.0,
        ]));

        $info = [
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'test_merchant',
            'SECRET_KEY' => 'test_secret',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 90,
            'cash_flow_info_id' => 1,
            'currency_id' => 19,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;
        $this->agentCashFlow->setRelation('currency', $this->currency);

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);
    }

    /**
     * 測試成功代付返回 providerOrderNo
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/WithdrawalTest.php --filter test_success_returns_provider_order_no
     */
    public function test_success_returns_provider_order_no()
    {
        // 創建銀行和 CashFlowBank
        $bank = Bank::create([
            'currency_id' => 19,
            'code' => 'BDT0001',
            'name' => 'bKash',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 90,
            'currency_id' => 19,
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0001',
            'provider_bank_code' => '1.bkash', // TakaPay 格式
            'status' => 1,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0001',
            'bank_name' => 'bKash',
            'account_name' => 'Test User',
            'account_no' => '01712345678',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 1000.00,
            'status' => 21, // 代付提交中
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        // Mock API 響應（TakaPay 不回傳 systemTransactionId）
        Http::fake([
            '*/withdraw' => Http::response([
                'status' => TakaPayStatusCodeEnum::SUCCESS->value,
                'description' => 'Success',
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        $request = new WithdrawalRequestData(order: $withdrawalOrder);

        $response = $withdrawal->handle($request);

        // 驗證響應成功但 providerOrderNo 為空
        $this->assertTrue($response->isSuccess);
        $this->assertEmpty($response->providerOrderNo);
        $this->assertEmpty($response->errorMessage);

        // 同步受理：記錄維持 PENDING
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 測試成功代付返回 providerResponse
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/WithdrawalTest.php --filter test_success_returns_provider_response
     */
    public function test_success_returns_provider_response()
    {
        $bank = Bank::create([
            'currency_id' => 19,
            'code' => 'BDT0002',
            'name' => 'Nagad',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 90,
            'currency_id' => 19,
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0002',
            'provider_bank_code' => '1.nagad',
            'status' => 1,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0002',
            'bank_name' => 'Nagad',
            'account_name' => 'Test User',
            'account_no' => '01798765432',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 2000.00,
            'status' => 21, // 代付提交中
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        $mockResponse = [
            'status' => TakaPayStatusCodeEnum::SUCCESS->value,
            'description' => 'Transaction successful',
        ];

        Http::fake([
            '*/withdraw' => Http::response($mockResponse, 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        $request = new WithdrawalRequestData(order: $withdrawalOrder);

        $response = $withdrawal->handle($request);

        // 驗證響應成功
        $this->assertTrue($response->isSuccess);
        $this->assertEmpty($response->errorMessage);
    }

    /**
     * 測試失敗代付不返回 providerOrderNo
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/WithdrawalTest.php --filter test_failed_withdrawal_no_provider_order_no
     */
    public function test_failed_withdrawal_no_provider_order_no()
    {
        $bank = Bank::create([
            'currency_id' => 19,
            'code' => 'BDT0003',
            'name' => 'Rocket',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 90,
            'currency_id' => 19,
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0003',
            'provider_bank_code' => '1.rocket',
            'status' => 1,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0003',
            'bank_name' => 'Rocket',
            'account_name' => 'Test User',
            'account_no' => '019111222333',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 500.00,
            'status' => 21, // 代付提交中
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        // Mock 失敗響應
        Http::fake([
            '*/withdraw' => Http::response([
                'status' => TakaPayStatusCodeEnum::MERCHANT_INSUFFICIENT_FUND->value,
                'description' => 'Insufficient merchant balance',
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);

        $request = new WithdrawalRequestData(order: $withdrawalOrder);

        $response = $withdrawal->handle($request);

        // 驗證失敗響應
        $this->assertFalse($response->isSuccess);
        $this->assertEmpty($response->providerOrderNo);
        $this->assertStringContainsString('Insufficient merchant balance', $response->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /**
     * 停用中的銀行對應不得被取用（查表只認 status=1）
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/WithdrawalTest.php --filter test_disabled_bank_mapping_is_not_used
     */
    public function test_disabled_bank_mapping_is_not_used()
    {
        $bank = Bank::create([
            'currency_id' => 19,
            'code' => 'BDT0004',
            'name' => 'Upay',
            'status' => 1,
            'admin' => 0,
        ]);

        // (cash_flow_id, currency_id, bank_code) 沒有唯一鍵，停用的舊對應會與啟用列並存
        CashFlowBank::create([
            'cash_flow_id' => 90,
            'currency_id' => 19,
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0004',
            'provider_bank_code' => '1.upay',
            'status' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0004',
            'bank_name' => 'Upay',
            'account_name' => 'Test User',
            'account_no' => '019444555666',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 300.00,
            'status' => 21,
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        Http::fake();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Provider bank code not configured', (string) $response->errorMessage);
        $this->assertSame(0, WithdrawalPayoutRecord::count());
        Http::assertNothingSent();
    }

    /**
     * 銀行碼逐字相同才算命中：欄位 collation 不分大小寫，只差大小寫的碼是另一個銀行身分，
     * 不得拿它的三方代碼出款（與 WithdrawalPayoutTrait 的查表路徑同一把尺）。
     *
     * php artisan test tests/Unit/Services/Payment/Providers/TakaPay/WithdrawalTest.php --filter test_case_variant_bank_code_does_not_match
     */
    public function test_case_variant_bank_code_does_not_match()
    {
        $bank = Bank::create([
            'currency_id' => 19,
            'code' => 'bdt0005',
            'name' => 'Rocket',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 90,
            'currency_id' => 19,
            'bank_id' => $bank->id,
            'bank_code' => 'BDT0005',
            'provider_bank_code' => '1.rocket',
            'status' => 1,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'bdt0005',
            'bank_name' => 'Rocket',
            'account_name' => 'Test User',
            'account_no' => '019444555777',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 300.00,
            'status' => 21,
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        Http::fake();

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $withdrawalOrder));

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Provider bank code not configured', (string) $response->errorMessage);
        $this->assertSame(0, WithdrawalPayoutRecord::count());
        Http::assertNothingSent();
    }
}
