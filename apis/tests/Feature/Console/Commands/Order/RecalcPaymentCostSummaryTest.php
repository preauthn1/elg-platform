<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Order;

use App\Enums\OrderStatusEnum;
use App\Enums\WithdrawalOrderStatusEnum;
use App\Models\Order;
use App\Models\WithdrawalOrder;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * order:recalc-payment-cost-summary：+8 區間→UTC、代收／代付（依 payment_time）會員與代理彙總重建、重跑不撞主鍵 target/tickets#4361。
 *
 * php artisan test tests/Feature/Console/Commands/Order/RecalcPaymentCostSummaryTest.php
 */
class RecalcPaymentCostSummaryTest extends TestCase
{
    #[Group('rules/payment-cost-summary#R-14')]
    #[Group('rules/payment-cost-summary#R-15')]
    #[Group('rules/payment-cost-summary#R-17')]
    public function test_rebuilds_member_and_agent_summaries_for_deposit_and_withdrawal(): void
    {
        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $member = $this->createMember($agent, ['username' => 'rc_pc_' . uniqid()]);

        // 9/10 12:30 +8 = 9/10 04:30 UTC → 桶 04:00
        $deposit = Order::create([
            'agent_cash_flow_id' => $agentCashFlow->id, 'order_type_id' => $orderType->id, 'currency_id' => $orderType->currency_id,
            'agent_id' => 0, 'member_id' => $member->id, 'order_no' => 'RC' . uniqid(), 'amount' => '100.0000', 'rate' => 1,
            'tax' => 0, 'point' => '100.0000', 'payment_cost' => '2.0000', 'balance' => 0, 'callback' => '',
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value, 'pay_time' => '2026-09-10 04:30:00', 'ip' => '0.0.0.0',
            'third_party_order_no' => '', 'remark' => '', 'admin' => 0,
        ]);
        // OrderObserver 建單時依渠道 cost（factory 為 0）覆寫 payment_cost，改用 raw update 設定測試值
        DB::table('orders')->where('id', $deposit->id)->update(['payment_cost' => '2.0000']);

        $paid = $this->makeWithdrawal($agent->id, (int) $agent->cash_flow_group_id, $member->id, WithdrawalOrderStatusEnum::THIRD_PARTY_SUCCESS->value, '5.0000');
        $paid->update(['payment_time' => '2026-09-10 05:10:00']); // 24 的 payment_time 由回調成功寫入（#3967）
        $manual = $this->makeWithdrawal($agent->id, (int) $agent->cash_flow_group_id, $member->id, WithdrawalOrderStatusEnum::TRANSFERRED->value, '1.5000');
        $manual->update(['payment_time' => '2026-09-10 05:40:00']);

        // 先塞一筆舊值，驗證重跑會先刪再寫、不撞主鍵
        DB::table('summary_event_records')->insert(['summary_time' => '2026-09-10 04:00:00', 'member_id' => $member->id, 'event_id' => 3, 'point' => 999]);

        $this->artisan('order:recalc-payment-cost-summary', ['--from' => '2026-09-10', '--to' => '2026-09-10', '--agent' => $agent->username])
            ->assertSuccessful();

        $deposit = DB::table('summary_event_records')->where(['member_id' => $member->id, 'event_id' => 3])->get();
        $this->assertCount(1, $deposit);
        $this->assertSame('2026-09-10 04:00:00', (string) $deposit[0]->summary_time);
        $this->assertSame('2.000000', (string) $deposit[0]->point);

        $withdrawal = DB::table('summary_event_records')->where(['member_id' => $member->id, 'event_id' => 8])->get();
        $this->assertCount(1, $withdrawal);
        $this->assertSame('2026-09-10 05:00:00', (string) $withdrawal[0]->summary_time);
        $this->assertSame('6.500000', (string) $withdrawal[0]->point); // 5 + 1.5 同一小時

        $agentRows = DB::table('summary_event_record_agents')->where('agent_id', $agent->id)->orderBy('event_id')->get();
        $this->assertCount(2, $agentRows);
        $this->assertSame('2.000000', (string) $agentRows[0]->point);
        $this->assertSame('6.500000', (string) $agentRows[1]->point);

        // 重跑：結果不變、不撞主鍵
        $this->artisan('order:recalc-payment-cost-summary', ['--from' => '2026-09-10', '--to' => '2026-09-10', '--agent' => $agent->username])->assertSuccessful();
        $this->assertSame(1, DB::table('summary_event_records')->where(['member_id' => $member->id, 'event_id' => 8])->count());

        DB::table('summary_event_records')->where('member_id', $member->id)->delete();
        DB::table('summary_event_record_agents')->where('agent_id', $agent->id)->delete();
    }

    #[Group('rules/payment-cost-summary#R-20')]
    public function test_rejects_to_before_from_and_unknown_agent(): void
    {
        $this->artisan('order:recalc-payment-cost-summary', ['--from' => '2026-09-05', '--to' => '2026-09-01', '--dry-run' => true])->assertFailed();
        $this->artisan('order:recalc-payment-cost-summary', ['--from' => '2026-09-01', '--agent' => 'no_such_agent_' . uniqid(), '--dry-run' => true])->assertFailed();
    }

    private function makeWithdrawal(int $agentId, int $agentGroupId, int $memberId, int $status, string $amount): WithdrawalOrder
    {
        return WithdrawalOrder::create([
            'agent_id' => $agentId, 'agent_group_id' => $agentGroupId, 'member_id' => $memberId, 'bank_id' => 0,
            'bank_code' => 'BCA', 'bank_name' => 'Bank Central Asia', 'account_no' => '1234567890', 'account_name' => 'Test User',
            'amount' => $amount, 'rate' => 1, 'payment_cost' => $amount, 'status' => $status, 'third_party_order_no' => '',
            'admin' => 0, 'change_logs' => '', 'memo' => '',
        ]);
    }
}
