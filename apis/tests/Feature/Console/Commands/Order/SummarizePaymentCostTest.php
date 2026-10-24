<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Order;

use App\Enums\OrderStatusEnum;
use App\Models\Order;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * summary:payment-cost 每小時排程：窗口 [現在整點 − N 小時, 現在整點)，窗口外不動 target/tickets#4369。
 *
 * php artisan test tests/Feature/Console/Commands/Order/SummarizePaymentCostTest.php
 */
class SummarizePaymentCostTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    #[Group('rules/payment-cost-summary#R-12')]
    #[Group('rules/payment-cost-summary#R-15')]
    public function test_rebuilds_only_the_recent_hour_window(): void
    {
        Carbon::setTestNow('2026-09-10 06:20:00'); // UTC；窗口 [03:00, 06:00)

        $agent = $this->createAgentAndDomain();
        [, $agentCashFlow, $orderType] = $this->createDefaultCashFlow($agent, random_int(10000, 99999), 1);
        $member = $this->createMember($agent, ['username' => 'sp_pc_' . uniqid()]);

        foreach (['2026-09-10 04:15:00' => '2.0000', '2026-09-10 05:59:00' => '3.0000', '2026-09-10 02:30:00' => '9.0000'] as $payTime => $cost) {
            $order = Order::create([
                'agent_cash_flow_id' => $agentCashFlow->id, 'order_type_id' => $orderType->id, 'currency_id' => $orderType->currency_id,
                'agent_id' => 0, 'member_id' => $member->id, 'order_no' => 'SP' . uniqid(), 'amount' => '100.0000', 'rate' => 1,
                'tax' => 0, 'point' => '100.0000', 'payment_cost' => $cost, 'balance' => 0, 'callback' => '',
                'status' => OrderStatusEnum::DEPOSIT_FINISHED->value, 'pay_time' => $payTime, 'ip' => '0.0.0.0',
                'third_party_order_no' => '', 'remark' => '', 'admin' => 0,
            ]);
            // OrderObserver 建單時依渠道 cost（factory 為 0）覆寫 payment_cost，改用 raw update 設定測試值
            DB::table('orders')->where('id', $order->id)->update(['payment_cost' => $cost]);
        }

        // 窗口外（02:00 桶）預先放一筆舊值，排程不該碰它
        DB::table('summary_event_records')->insert(['summary_time' => '2026-09-10 02:00:00', 'member_id' => $member->id, 'event_id' => 3, 'point' => 111]);

        $this->artisan('summary:payment-cost', ['--hours' => 3, '--agent' => $agent->username])->assertSuccessful();

        $rows = DB::table('summary_event_records')->where(['member_id' => $member->id, 'event_id' => 3])->orderBy('summary_time')->pluck('point', 'summary_time');
        $this->assertSame(['2026-09-10 02:00:00' => '111.000000', '2026-09-10 04:00:00' => '2.000000', '2026-09-10 05:00:00' => '3.000000'], $rows->map(fn ($v) => (string) $v)->all());

        DB::table('summary_event_records')->where('member_id', $member->id)->delete();
        DB::table('summary_event_record_agents')->where('agent_id', $agent->id)->delete();
    }
}
