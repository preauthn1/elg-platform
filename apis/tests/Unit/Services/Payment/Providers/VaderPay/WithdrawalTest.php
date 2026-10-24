<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\VaderPay;

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
use App\Services\Payment\Providers\VaderPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * VaderPay 代付測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/VaderPay/WithdrawalTest.php
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
            'code' => 'MYR', 'name' => 'Malaysian Ringgit', 'rate' => 1, 'status' => 1, 'admin' => 0]));

        $info = [
            'API_URL' => 'https://example.invalid',
            'MERCHANT_CODE' => 'merchant01',
            'API_KEY' => 'test_api_key',
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 202,
            'cash_flow_info_id' => 1,
            'currency_id' => 1,
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

    public function test_withdrawal_success(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'CIMB',
            'name' => 'CIMB Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 202,
            'currency_id' => 1,
            'bank_id' => $bank->id,
            'bank_code' => 'CIMB',
            'provider_bank_code' => 'CIMB',
            'status' => 1,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'CIMB',
            'bank_name' => 'CIMB Bank',
            'account_name' => 'Test User',
            'account_no' => '123456789',
            'agent_group_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 1000.00,
            'rate' => 1,
            'point' => 0,
            'tax' => 0,
            'third_party_order_no' => '',
            'status' => 21,
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
        ]);

        Http::fake([
            '*/Payout/Withdrawal' => Http::response([
                'error_code' => 0,
                'error_message' => 'Operation success',
                'invoice_number' => 'INV-1001',
                'currency_code' => 'MYR',
                'merchant_balance' => 9000.0,
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $request = new WithdrawalRequestData(order: $withdrawalOrder);

        $response = $withdrawal->handle($request);

        $this->assertTrue($response->isSuccess);
        $this->assertEquals('INV-1001', $response->providerOrderNo);

        // 同步受理：記錄維持 PENDING
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    public function test_withdrawal_failed(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'CIMB2',
            'name' => 'CIMB Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 202,
            'currency_id' => 1,
            'bank_id' => $bank->id,
            'bank_code' => 'CIMB2',
            'provider_bank_code' => 'CIMB',
            'status' => 1,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'CIMB2',
            'bank_name' => 'CIMB Bank',
            'account_name' => 'Test User',
            'account_no' => '987654321',
            'agent_group_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 2000.00,
            'rate' => 1,
            'point' => 0,
            'tax' => 0,
            'third_party_order_no' => '',
            'status' => 21,
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
        ]);

        Http::fake([
            '*/Payout/Withdrawal' => Http::response([
                'error_code' => -100,
                'error_message' => 'Invalid parameter',
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $request = new WithdrawalRequestData(order: $withdrawalOrder);

        $response = $withdrawal->handle($request);

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Invalid parameter', (string) $response->errorMessage);
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, WithdrawalPayoutRecord::firstOrFail()->is_success_payout);
    }

    /** 停用中的銀行對應不得被取用（查表只認 status=1） */
    public function test_disabled_bank_mapping_is_not_used(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'CIMB3',
            'name' => 'CIMB Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        // (cash_flow_id, currency_id, bank_code) 沒有唯一鍵，停用的舊對應會與啟用列並存
        CashFlowBank::create([
            'cash_flow_id' => 202,
            'currency_id' => 1,
            'bank_id' => $bank->id,
            'bank_code' => 'CIMB3',
            'provider_bank_code' => 'CIMB',
            'status' => 0,
        ]);

        Http::fake();

        $response = (new Withdrawal($this->agentCashFlow))->handle(
            new WithdrawalRequestData(order: $this->makePayoutOrder($bank, 'CIMB3', '987654322'))
        );

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Provider bank code not configured', (string) $response->errorMessage);
        $this->assertSame(0, WithdrawalPayoutRecord::count());
        Http::assertNothingSent();
    }

    /**
     * 銀行碼逐字相同才算命中：欄位 collation 不分大小寫，只差大小寫的碼是另一個銀行身分，
     * 不得拿它的三方代碼出款（與 WithdrawalPayoutTrait 的查表路徑同一把尺）。
     */
    public function test_case_variant_bank_code_does_not_match(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'cimb4',
            'name' => 'CIMB Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 202,
            'currency_id' => 1,
            'bank_id' => $bank->id,
            'bank_code' => 'CIMB4',
            'provider_bank_code' => 'CIMB',
            'status' => 1,
        ]);

        Http::fake();

        $response = (new Withdrawal($this->agentCashFlow))->handle(
            new WithdrawalRequestData(order: $this->makePayoutOrder($bank, 'cimb4', '987654323'))
        );

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Provider bank code not configured', (string) $response->errorMessage);
        $this->assertSame(0, WithdrawalPayoutRecord::count());
        Http::assertNothingSent();
    }

    /** 代付銀行碼是字串 "0" 時仍要照送：empty() 會把它誤判成沒設定 */
    public function test_zero_provider_bank_code_is_not_treated_as_missing(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'ZERO1',
            'name' => 'Zero Code Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 202,
            'currency_id' => 1,
            'bank_id' => $bank->id,
            'bank_code' => 'ZERO1',
            'provider_bank_code' => '0',
            'status' => 1,
        ]);

        Http::fake([
            '*/Payout/Withdrawal' => Http::response([
                'error_code' => -100,
                'error_message' => 'Invalid parameter',
            ], 200),
        ]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(
            new WithdrawalRequestData(order: $this->makePayoutOrder($bank, 'ZERO1', '987654323'))
        );

        // 沒有被「查無代付銀行碼」擋下，而是真的送出請求（三方拒絕與否是另一回事）
        $this->assertStringNotContainsString('Provider bank code not configured', (string) $response->errorMessage);
        Http::assertSent(fn ($request): bool => ($request['bank_code'] ?? null) === '0');
    }

    private function makePayoutOrder(Bank $bank, string $bankCode, string $accountNo): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => $bankCode,
            'bank_name' => $bank->name,
            'account_name' => 'Test User',
            'account_no' => $accountNo,
            'agent_group_id' => 0,
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 2000.00,
            'rate' => 1,
            'point' => 0,
            'tax' => 0,
            'third_party_order_no' => '',
            'status' => 21,
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
        ]);
    }
}
