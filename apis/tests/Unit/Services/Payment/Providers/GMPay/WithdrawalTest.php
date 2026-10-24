<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\GMPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\GMPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * GMPay 代付請求測試（standalone Withdrawal）。
 *
 * php artisan test tests/Unit/Services/Payment/Providers/GMPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $info = [
            'API_URL' => 'https://api.gmpaysg.test',
            'API_KEY' => 'gmpay_test_api_key',
            'SECRET_KEY' => 'gmpay_test_secret',
            'CURRENCY' => 'SGD',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'DBS'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 73,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
        ]);
        $this->agentCashFlow->info = $info;

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
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
    }

    public function test_success_keeps_record_pending(): void
    {
        Http::fake(['*' => Http::response([
            'status' => true,
            'result' => ['transaction_reference' => 'GM-1'],
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('GM-1', $record->third_party_transaction_no);
        // 代付流水號前綴為 GMPay 專屬 'GMP'（非繼承 MajesticPay 的 'MAJ'）
        $this->assertStringStartsWith('GMP', $record->transaction_no);
    }

    public function test_sync_reject_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'message' => 'denied'], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    public function test_request_exception_marks_request_failed(): void
    {
        Http::fake(['*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout')]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::REQUEST_FAILED, $record->is_success_payout);
    }

    public function test_unmapped_bank_code_fails_before_any_request(): void
    {
        Http::fake();
        $this->order->update(['bank_code' => 'NOT_MAPPED']);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $this->assertStringContainsString('Provider bank code not configured', $response->errorMessage ?? '');
        // 查不到三方銀行碼：不建代付紀錄、不送三方
        $this->assertDatabaseMissing('withdrawal_payout_records', ['withdrawal_order_id' => $this->order->id]);
        Http::assertNothingSent();
    }
}
