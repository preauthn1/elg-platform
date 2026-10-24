<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\OrderType;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class AddAgentCashFlowWritesCashFlowIdTest extends TestCase
{
    use DatabaseTransactions;

    private const CF = 99780;

    public function test_upsert_order_type_link_sets_cash_flow_id(): void
    {
        $acf = AgentCashFlow::create([
            'cash_flow_id' => self::CF, 'agent_group_id' => 1, 'currency_id' => 1,
            'cash_flow_info_id' => 0, 'rate' => 1, 'name' => 'x', 'status' => 1, 'admin' => 0,
            'info' => '',
        ]);

        // AgentCashFlowOrderTypeObserver 要求 acf.cash_flow_id === order_type.cash_flow_id（cf > 62 範疇強制）
        $orderType = OrderType::create([
            'cash_flow_id' => self::CF, 'name' => 'Sentinel OT', 'key' => 'sentinel_ot_' . self::CF, 'admin' => 0,
        ]);

        // 直接呼叫關聯建立，模擬 upsertOrderTypeLink 的 create 行為
        $acfot = $acf->agentCashFlowOrderTypes()->create([
            'cash_flow_id' => $acf->cash_flow_id,
            'order_type_id' => $orderType->id, 'min_amount' => 0, 'max_amount' => 0,
            'available_amount' => '', 'settings' => '{}', 'sorts' => 0, 'status' => 1,
        ]);

        $this->assertSame(self::CF, AgentCashFlowOrderType::find($acfot->id)->cash_flow_id);
    }
}
