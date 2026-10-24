<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\OrderType;
use App\Models\OrderTypeName;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class BackfillCashFlowIdTest extends TestCase
{
    use DatabaseTransactions;

    private const CF = 99777; // 哨兵 cash_flow_id，隔離真實資料

    public function test_execute_backfills_both_tables(): void
    {
        $ot = OrderType::create([
            'cash_flow_id' => self::CF, 'currency_id' => 1, 'name' => 'x', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);
        $acf = AgentCashFlow::create([
            'cash_flow_id' => self::CF, 'agent_group_id' => 1, 'currency_id' => 1,
            'cash_flow_info_id' => 0, 'rate' => 1, 'name' => 'x', 'status' => 1, 'admin' => 0, 'info' => [],
        ]);
        // order_type_id 需指向與 acf 同一 cash_flow_id 的 OrderType，
        // 以符合 AgentCashFlowOrderTypeObserver 的 invariant（acf.cash_flow_id === ot.cash_flow_id，非 legacy 範疇 cf>62 強制）。
        $acfot = AgentCashFlowOrderType::create([
            'cash_flow_id' => 0, 'agent_cash_flow_id' => $acf->id, 'order_type_id' => $ot->id,
            'settings' => '{}', 'status' => 1,
        ]);
        $otn = OrderTypeName::create([
            'cash_flow_id' => 0, 'type_id' => $ot->id, 'language_id' => 1, 'name' => 'x', 'admin' => 0,
        ]);

        $this->artisan('cash-flow:backfill-cash-flow-id', ['--execute' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(self::CF, $acfot->fresh()->cash_flow_id);
        $this->assertSame(self::CF, $otn->fresh()->cash_flow_id);
    }

    public function test_execute_is_idempotent(): void
    {
        $ot = OrderType::create([
            'cash_flow_id' => self::CF, 'currency_id' => 1, 'name' => 'x', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);
        $acf = AgentCashFlow::create([
            'cash_flow_id' => self::CF, 'agent_group_id' => 1, 'currency_id' => 1,
            'cash_flow_info_id' => 0, 'rate' => 1, 'name' => 'x', 'status' => 1, 'admin' => 0, 'info' => [],
        ]);
        $acfot = AgentCashFlowOrderType::create([
            'cash_flow_id' => 0, 'agent_cash_flow_id' => $acf->id, 'order_type_id' => $ot->id,
            'settings' => '{}', 'status' => 1,
        ]);

        $this->artisan('cash-flow:backfill-cash-flow-id', ['--execute' => true, '--force' => true])->assertExitCode(0);
        $this->artisan('cash-flow:backfill-cash-flow-id', ['--execute' => true, '--force' => true])->assertExitCode(0);

        $this->assertSame(self::CF, $acfot->fresh()->cash_flow_id);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $ot = OrderType::create([
            'cash_flow_id' => self::CF, 'currency_id' => 1, 'name' => 'x', 'type' => 1,
            'key' => '--', 'info' => '', 'status' => 1, 'admin' => 0,
        ]);
        $acf = AgentCashFlow::create([
            'cash_flow_id' => self::CF, 'agent_group_id' => 1, 'currency_id' => 1,
            'cash_flow_info_id' => 0, 'rate' => 1, 'name' => 'x', 'status' => 1, 'admin' => 0, 'info' => [],
        ]);
        $acfot = AgentCashFlowOrderType::create([
            'cash_flow_id' => 0, 'agent_cash_flow_id' => $acf->id, 'order_type_id' => $ot->id,
            'settings' => '{}', 'status' => 1,
        ]);

        $this->artisan('cash-flow:backfill-cash-flow-id')->assertExitCode(0);

        $this->assertSame(0, $acfot->fresh()->cash_flow_id);
    }
}
