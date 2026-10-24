<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/Maintenance/UpdateAgentCashFlowOrderTypeLimitsTest.php
 */
class UpdateAgentCashFlowOrderTypeLimitsTest extends TestCase
{
    public function test_command_updates_parent_and_child_order_type_limits(): void
    {
        $chain = $this->createPaymentChannelChain();

        $this->artisan('fix:agent-cash-flow-order-type-limits', [
            'agent_cash_flow_id' => $chain['agentCashFlow']->id,
            'parent_order_type_id' => $chain['parentOrderType']->id,
            'min_amount' => 10000,
            'max_amount' => 20000000,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(10000, $chain['parentAcfot']->refresh()->min_amount);
        $this->assertSame(20000000, $chain['parentAcfot']->max_amount);
        $this->assertSame(10000, $chain['childAcfot']->refresh()->min_amount);
        $this->assertSame(20000000, $chain['childAcfot']->max_amount);
        $this->assertSame(100, $chain['siblingAcfot']->refresh()->min_amount);
        $this->assertSame(1000, $chain['siblingAcfot']->max_amount);
    }

    public function test_dry_run_does_not_update_limits(): void
    {
        $chain = $this->createPaymentChannelChain();

        $this->artisan('fix:agent-cash-flow-order-type-limits', [
            'agent_cash_flow_id' => $chain['agentCashFlow']->id,
            'parent_order_type_id' => $chain['parentOrderType']->id,
            'min_amount' => 10000,
            'max_amount' => 20000000,
            '--dry-run' => true,
            '--force' => true,
        ])->assertSuccessful();

        $this->assertSame(10, $chain['parentAcfot']->refresh()->min_amount);
        $this->assertSame(100, $chain['parentAcfot']->max_amount);
        $this->assertSame(20, $chain['childAcfot']->refresh()->min_amount);
        $this->assertSame(200, $chain['childAcfot']->max_amount);
    }

    public function test_command_fails_when_min_amount_is_greater_than_max_amount(): void
    {
        $chain = $this->createPaymentChannelChain();

        $this->artisan('fix:agent-cash-flow-order-type-limits', [
            'agent_cash_flow_id' => $chain['agentCashFlow']->id,
            'parent_order_type_id' => $chain['parentOrderType']->id,
            'min_amount' => 200,
            'max_amount' => 100,
            '--force' => true,
        ])->assertFailed();
    }

    private function createPaymentChannelChain(): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test CashFlow ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => 1,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'rate' => 1,
            'name' => 'Test Agent CashFlow ' . uniqid(),
            'info' => '',
            'status' => 1,
            'admin' => 0,
        ]);

        $parentOrderType = $this->createOrderType($cashFlow, 0, 'parent');
        $childOrderType = $this->createOrderType($cashFlow, $parentOrderType->id, 'child');
        $siblingOrderType = $this->createOrderType($cashFlow, 0, 'sibling');

        return [
            'cashFlow' => $cashFlow,
            'agentCashFlow' => $agentCashFlow,
            'parentOrderType' => $parentOrderType,
            'childOrderType' => $childOrderType,
            'siblingOrderType' => $siblingOrderType,
            'parentAcfot' => $this->createAgentCashFlowOrderType($agentCashFlow, $parentOrderType, 10, 100),
            'childAcfot' => $this->createAgentCashFlowOrderType($agentCashFlow, $childOrderType, 20, 200),
            'siblingAcfot' => $this->createAgentCashFlowOrderType($agentCashFlow, $siblingOrderType, 100, 1000),
        ];
    }

    private function createOrderType(CashFlow $cashFlow, int $parentId, string $name): OrderType
    {
        return OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => $name . ' ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'parent' => $parentId,
            'key' => $name,
            'info' => '{}',
            'admin' => 0,
        ]);
    }

    private function createAgentCashFlowOrderType(
        AgentCashFlow $agentCashFlow,
        OrderType $orderType,
        int $minAmount,
        int $maxAmount,
    ): AgentCashFlowOrderType {
        return AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $orderType->id,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'status' => 1,
            'settings' => '{}',
        ]);
    }
}
