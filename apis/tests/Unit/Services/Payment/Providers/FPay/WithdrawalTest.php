<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\FPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Currency;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\FPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * FPay 代付請求測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/FPay/WithdrawalTest.php
 */
class WithdrawalTest extends TestCase
{
    private AgentCashFlow $agentCashFlow;

    private WithdrawalOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();

        $currency = Currency::findOr(1, fn () => Currency::forceCreate([
            'id' => 1,
            'code' => 'MYR',
            'name' => 'Malaysian Ringgit',
            'rate' => 1,
            'status' => 1,
            'admin' => 0,
        ]));

        $info = [
            'API_URL' => 'https://api.fpay.test',
            'MERCHANT_NO' => 'test_merchant_no',
            'APP_KEY' => 'test_app_key',
            'APP_SECRET' => 'test_app_secret',
            'available_banks' => [
                ['BankCode_ELG' => '001', 'BankCode_Third' => 'TBANK'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 1,
            'cash_flow_info_id' => 1,
            'currency_id' => $currency->id,
            'rate' => 1.5,
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
        Http::fake([
            '*/merchant/auth' => Http::response(['status' => true, 'auth' => 'AUTHTOKEN'], 200),
            '*/merchant/withdraw_orders' => Http::response(['status' => true], 200),
        ]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
    }

    public function test_sync_reject_marks_third_party_rejected(): void
    {
        Http::fake([
            '*/merchant/auth' => Http::response(['status' => true, 'auth' => 'AUTHTOKEN'], 200),
            '*/merchant/withdraw_orders' => Http::response(['status' => false, 'message' => 'denied'], 200),
        ]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }

    public function test_request_exception_marks_request_failed(): void
    {
        Http::fake([
            '*/merchant/auth' => Http::response(['status' => true, 'auth' => 'AUTHTOKEN'], 200),
            '*/merchant/withdraw_orders' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
        ]);

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
        // 查不到三方銀行碼：不取 auth token、不建代付紀錄、不送三方
        $this->assertDatabaseMissing('withdrawal_payout_records', ['withdrawal_order_id' => $this->order->id]);
        Http::assertNothingSent();
    }
}
