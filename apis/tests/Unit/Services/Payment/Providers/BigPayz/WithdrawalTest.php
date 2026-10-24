<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\BigPayz;

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
use App\Services\Payment\Providers\BigPayz\Withdrawal;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use Tests\TestCase;

/**
 * BigPayz Withdrawal (Payout) unit tests.
 */
#[RequiresPhpExtension('redis')]
class WithdrawalTest extends TestCase
{
    private Agent $agent;

    private AgentCashFlow $agentCashFlow;

    private Member $member;

    private Currency $currency;

    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();

        $this->agent = $this->createAgentAndDomain();

        $this->currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR', 'name' => 'Malaysian Ringgit', 'rate' => 1, 'status' => 1, 'admin' => 0]));

        $info = [
            'PAYIN_API_URL' => 'https://api.bigpayz.test',
            'PAYOUT_API_URL' => 'https://api.bigpayz.test',
            'DUITNOW_API_URL' => 'https://upi.bigpayz.test',
            'MERCHANT_CODE' => 'test_merchant',
            'API_KEY' => 'test_api_key',
            'available_banks' => [
                ['BankCode_ELG' => 'CIMB', 'BankCode_Third' => 'CIMB'],
                ['BankCode_ELG' => 'MB2U', 'BankCode_Third' => 'MB2U'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 999,
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

    public function test_success_returns_invoice_number(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'CIMB',
            'name' => 'CIMB Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 999,
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
            'account_no' => '1234567890',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 500.00,
            'status' => 21,
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        Http::fake([
            '*Payout/Withdrawal' => Http::response([
                'error_code' => 0,
                'invoice_number' => 'INV-PAYOUT-001',
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $request = WithdrawalRequestData::from(['order' => $withdrawalOrder, 'orderId' => $withdrawalOrder->id]);

        $response = $withdrawal->handle($request);

        $this->assertTrue($response->isSuccess);
        $this->assertEquals('INV-PAYOUT-001', $response->providerOrderNo);
        $this->assertEmpty($response->errorMessage);

        $withdrawalOrder->refresh();
        $this->assertNotEmpty($withdrawalOrder->third_party_order_no);

        // 同步受理：記錄維持 PENDING，等回調確認
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
    }

    public function test_failed_error_code_returns_failure(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'CIMB',
            'name' => 'CIMB Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 999,
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
            'account_no' => '1234567890',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        Http::fake([
            '*Payout/Withdrawal' => Http::response([
                'error_code' => 1,
                'error_message' => 'Insufficient balance',
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $request = WithdrawalRequestData::from(['order' => $withdrawalOrder, 'orderId' => $withdrawalOrder->id]);

        $response = $withdrawal->handle($request);

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Insufficient balance', $response->errorMessage ?? '');

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    public function test_error_code_401_maps_to_insufficient_balance(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'CIMB',
            'name' => 'CIMB Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 999,
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
            'account_no' => '1234567890',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        Http::fake([
            '*Payout/Withdrawal' => Http::response([
                'error_code' => 401,
            ], 200),
        ]);

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $request = WithdrawalRequestData::from(['order' => $withdrawalOrder, 'orderId' => $withdrawalOrder->id]);

        $response = $withdrawal->handle($request);

        $this->assertFalse($response->isSuccess);
        $this->assertSame('Insufficient balance', $response->errorMessage);

        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $withdrawalOrder->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    public function test_unsupported_bank_code_returns_failure(): void
    {
        $bank = Bank::create([
            'currency_id' => 1,
            'code' => 'UNKNOWN',
            'name' => 'Unknown Bank',
            'status' => 1,
            'admin' => 0,
        ]);

        $withdrawalOrder = WithdrawalOrder::create([
            'bank_id' => $bank->id,
            'bank_code' => 'UNKNOWN',
            'bank_name' => 'Unknown Bank',
            'account_name' => 'Test User',
            'account_no' => '1234567890',
            'agent_id' => $this->agent->id,
            'member_id' => $this->member->id,
            'amount' => 100.00,
            'status' => 21,
            'third_party_order_no' => '',
            'change_logs' => '{}',
        ]);

        Http::fake();

        $withdrawal = new Withdrawal($this->agentCashFlow);
        $request = WithdrawalRequestData::from(['order' => $withdrawalOrder, 'orderId' => $withdrawalOrder->id]);

        $response = $withdrawal->handle($request);

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Provider bank code not configured', $response->errorMessage ?? '');

        // pre-flight 失敗（bank code 未配置）：不建立任何代付記錄
        $this->assertDatabaseMissing('withdrawal_payout_records', ['withdrawal_order_id' => $withdrawalOrder->id]);

        Http::assertNothingSent();
    }
}
