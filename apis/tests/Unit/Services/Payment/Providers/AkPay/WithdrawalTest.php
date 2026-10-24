<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment\Providers\AkPay;

use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Providers\AkPay\Withdrawal;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * AkPay 代付請求測試
 *
 * php artisan test tests/Unit/Services/Payment/Providers/AkPay/WithdrawalTest.php
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
            'API_URL' => 'https://api.akpay.test',
            'MERCHANT_TOKEN' => 'test_token',
            'MERCHANT_CODE' => 'AKTEST',
            'CURRENCY' => 'MYR',
            'available_banks' => [
                ['BankCode_ELG' => 'MBB', 'BankCode_Third' => 'MBB'],
            ],
        ];

        $this->agentCashFlow = AgentCashFlow::create([
            'cash_flow_id' => 150,
            'cash_flow_info_id' => 1,
            'rate' => 1.0,
            'info' => json_encode($info),
            'status' => 1,
            'admin' => 0,
            'currency_id' => 1,
        ]);
        $this->agentCashFlow->info = $info;

        $member = Member::factory()->create(['parent' => $agent->id, 'admin' => 0]);

        $this->order = WithdrawalOrder::create([
            'agent_id' => $agent->id,
            'member_id' => $member->id,
            'bank_code' => 'MBB',
            'bank_name' => 'Maybank',
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
            'success' => true,
            'data' => ['tx_id' => 'AK-TX-1'],
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
        $this->assertSame('AK-TX-1', $record->third_party_transaction_no);
    }

    public function test_sync_reject_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'message' => 'denied',
        ], 200)]);

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

    /**
     * 格式漂移守衛：success=true 但 data 回成純量時，不可因 reset() 拋例外而誤入失敗重試，
     * 應正確讀到 success=true → 受理待回調（PENDING），避免雙重撥款向量。
     */
    public function test_success_true_with_scalar_data_stays_pending(): void
    {
        Http::fake(['*' => Http::response([
            'success' => true,
            'data' => 'unexpected scalar',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertTrue($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::PENDING, $record->is_success_payout);
    }

    /**
     * 格式漂移守衛：success=false 且 data 為純量時，不拋例外，走正常同步拒絕（-2）。
     */
    public function test_failure_with_scalar_data_marks_third_party_rejected(): void
    {
        Http::fake(['*' => Http::response([
            'success' => false,
            'data' => 'Insufficient balance',
        ], 200)]);

        $response = (new Withdrawal($this->agentCashFlow))->handle(new WithdrawalRequestData(order: $this->order));

        $this->assertFalse($response->isSuccess);
        $record = WithdrawalPayoutRecord::where('withdrawal_order_id', $this->order->id)->firstOrFail();
        $this->assertSame(WithdrawalPayoutStatusEnum::THIRD_PARTY_REJECTED, $record->is_success_payout);
    }
}
