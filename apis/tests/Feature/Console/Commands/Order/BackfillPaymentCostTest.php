<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Order;

use App\Enums\OrderStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Enums\WithdrawalPayoutStatusEnum;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use App\Models\WithdrawalPayoutRecord;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * order:backfill-payment-cost 三條回補路徑（代收／代付三方成功／代付人工出款）與冪等 target/tickets#4361。
 *
 * php artisan test tests/Feature/Console/Commands/Order/BackfillPaymentCostTest.php
 */
class BackfillPaymentCostTest extends TestCase
{
    #[Group('rules/payment-cost-summary#R-21')]
    #[Group('rules/payment-cost-summary#R-22')]
    public function test_backfills_deposit_third_party_and_manual_withdrawal_costs(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $agentCashFlow->update(['cost' => 0.02]); // 2%
        $member = $this->createMember($agent, ['username' => 'bf_pc_' . uniqid()]);

        // 代收：已付款、成本尚未寫入
        $deposit = Order::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            'agent_id' => 0,
            'member_id' => $member->id,
            'order_no' => 'BF' . uniqid(),
            'amount' => '100.5000',
            'rate' => 1,
            'tax' => 0,
            'point' => '100.5000',
            'balance' => 0,
            'callback' => '',
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'pay_time' => now()->toDateTimeString(),
            'ip' => '0.0.0.0',
            'third_party_order_no' => '',
            'remark' => '',
            'admin' => 0,
        ]);
        // OrderObserver 建單時已算成本；用 raw update 歸零模擬欄位上線前的歷史單
        DB::table('orders')->where('id', $deposit->id)->update(['payment_cost' => 0]);

        // 代付三方成功：渠道取最新一筆成功 payout record
        $thirdParty = $this->makeWithdrawal($agent->id, (int) $agent->cash_flow_group_id, $member->id, WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '1000.0000');
        WithdrawalPayoutRecord::create([
            'cash_flow_id' => $agentCashFlow->cash_flow_id,
            'agent_cash_flow_id' => $agentCashFlow->id,
            'withdrawal_order_id' => $thirdParty->id,
            'transaction_no' => 'BFTXN' . uniqid(),
            'third_party_transaction_no' => '',
            'payout_order_request_body' => '{}',
            'payout_order_response' => '',
            'result_amount' => $thirdParty->amount,
            'result_code' => '',
            'transaction_time' => now()->toDateTimeString(),
            'sign' => '',
            'callback_request_body' => '',
            'is_success_payout' => WithdrawalPayoutStatusEnum::SUCCESS->value,
            'info_1' => '',
            'info_2' => '',
            'info_3' => '',
            'callback_time' => now()->toDateTimeString(),
        ]);

        // 代付人工出款：渠道取該組別的手動渠道（cash_flow_id=6）
        $manualChannel = AgentCashFlow::create([
            'agent_group_id' => $agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => 6,
            'cost' => 0.025,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        $manual = $this->makeWithdrawal($agent->id, (int) $agent->cash_flow_group_id, $member->id, WithdrawalOrderStatusEnum::TRANSFERRED->value, '200.0000');

        $this->artisan('order:backfill-payment-cost', ['--months' => 1, '--chunk' => 1000])->assertSuccessful();

        $this->assertSame('2.0100', (string) $deposit->refresh()->payment_cost);       // 100.5 × 0.02
        $this->assertSame('20.0000', (string) $thirdParty->refresh()->payment_cost);   // 1000 × 1 × 0.02
        $this->assertSame('5.0000', (string) $manual->refresh()->payment_cost);        // 200 × 1 × 0.025

        // 冪等：改了費率再跑，已回補的列不動
        $agentCashFlow->update(['cost' => 0.09]);
        $manualChannel->update(['cost' => 0.09]);
        $this->artisan('order:backfill-payment-cost', ['--months' => 1])->assertSuccessful();

        $this->assertSame('2.0100', (string) $deposit->refresh()->payment_cost);
        $this->assertSame('20.0000', (string) $thirdParty->refresh()->payment_cost);
        $this->assertSame('5.0000', (string) $manual->refresh()->payment_cost);
    }

    #[Group('rules/payment-cost-summary#R-25')]
    public function test_dry_run_writes_nothing(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $agentCashFlow->update(['cost' => 0.02]);
        $member = $this->createMember($agent, ['username' => 'bf_dry_' . uniqid()]);

        $deposit = Order::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'currency_id' => $orderType->currency_id,
            'agent_id' => 0,
            'member_id' => $member->id,
            'order_no' => 'BF' . uniqid(),
            'amount' => '100.0000',
            'rate' => 1,
            'tax' => 0,
            'point' => '100.0000',
            'balance' => 0,
            'callback' => '',
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'pay_time' => now()->toDateTimeString(),
            'ip' => '0.0.0.0',
            'third_party_order_no' => '',
            'remark' => '',
            'admin' => 0,
        ]);
        DB::table('orders')->where('id', $deposit->id)->update(['payment_cost' => 0]);

        $this->artisan('order:backfill-payment-cost', ['--months' => 1, '--dry-run' => true])
            ->expectsOutputToContain('乾跑')
            ->assertSuccessful();

        $this->assertSame('0.0000', (string) $deposit->refresh()->payment_cost);
    }

    private function makeWithdrawal(int $agentId, int $agentGroupId, int $memberId, int $status, string $amount): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $agentId,
            'agent_group_id' => $agentGroupId,
            'member_id' => $memberId,
            'bank_id' => 0,
            'bank_code' => 'BCA',
            'bank_name' => 'Bank Central Asia',
            'account_no' => '1234567890',
            'account_name' => 'Test User',
            'amount' => $amount,
            'rate' => 1,
            'status' => $status,
            'third_party_order_no' => '',
            'admin' => 0,
            'change_logs' => '',
            'memo' => '',
        ]);
    }
}
