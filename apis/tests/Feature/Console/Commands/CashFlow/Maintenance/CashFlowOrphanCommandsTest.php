<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Enums\CashFlow\CashFlowScopeEnum;
use App\Enums\CashFlow\OrphanReasonEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use App\Services\CashFlow\OrphanDetectorService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/Maintenance/CashFlowOrphanCommandsTest.php
 */
class CashFlowOrphanCommandsTest extends TestCase
{
    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agent = $this->createAgentAndDomain();
    }

    public function test_detector_returns_empty_for_healthy_chain(): void
    {
        $chain = $this->createChain();

        $orphans = app(OrphanDetectorService::class)->findOrphans($chain['cashFlow']->id);

        $this->assertCount(0, $orphans);
    }

    public function test_detector_flags_l1_cashflow_disabled(): void
    {
        $chain = $this->createChain();
        $chain['cashFlow']->update(['status' => 0]);

        $orphans = app(OrphanDetectorService::class)->findOrphans($chain['cashFlow']->id);

        $this->assertCount(2, $orphans);
        $orphans->each(fn (object $r) => $this->assertSame(OrphanReasonEnum::L1_CASHFLOW_DISABLED, $r->reason));
    }

    public function test_detector_flags_l2_order_type_disabled(): void
    {
        $chain = $this->createChain();
        $chain['childOrderType']->update(['status' => 0]);

        $orphans = app(OrphanDetectorService::class)->findOrphans($chain['cashFlow']->id);

        $this->assertCount(1, $orphans);
        $this->assertSame(OrphanReasonEnum::L2_ORDER_TYPE_DISABLED, $orphans->first()->reason);
        $this->assertSame($chain['childAcfot']->id, (int) $orphans->first()->acfot_id);
    }

    public function test_detector_flags_l2_parent_disabled(): void
    {
        $chain = $this->createChain();
        $chain['parentOrderType']->update(['status' => 0]);

        $orphans = app(OrphanDetectorService::class)->findOrphans($chain['cashFlow']->id);

        $reasons = $orphans->pluck('reason')->all();
        $this->assertContains(OrphanReasonEnum::L2_PARENT_DISABLED, $reasons);
    }

    public function test_detector_flags_l2_cashflow_mismatch(): void
    {
        $chain = $this->createChain();

        $otherCashFlow = CashFlow::create([
            'name' => 'OtherCashFlow ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);
        $chain['childOrderType']->update(['cash_flow_id' => $otherCashFlow->id]);

        $orphans = app(OrphanDetectorService::class)->findOrphans($chain['cashFlow']->id);

        $mismatch = $orphans->firstWhere('reason', OrphanReasonEnum::L2_CASHFLOW_MISMATCH);
        $this->assertNotNull($mismatch, 'expected L2_CASHFLOW_MISMATCH row');
    }

    public function test_detector_flags_l3_acf_disabled(): void
    {
        $chain = $this->createChain();
        $this->disableAcfBypassingObserver((int) $chain['agentCashFlow']->id);

        $orphans = app(OrphanDetectorService::class)->findOrphans($chain['cashFlow']->id);

        $this->assertCount(2, $orphans);
        $orphans->each(fn (object $r) => $this->assertSame(OrphanReasonEnum::L3_ACF_DISABLED, $r->reason));
    }

    public function test_check_command_outputs_json(): void
    {
        $chain = $this->createChain();
        $chain['childOrderType']->update(['status' => 0]);

        $this->artisan('check:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
            '--json' => true,
        ])->assertExitCode(0);
    }

    public function test_check_command_returns_failure_when_orphans_found_in_human_mode(): void
    {
        $chain = $this->createChain();
        $chain['childOrderType']->update(['status' => 0]);

        $this->artisan('check:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
        ])->assertExitCode(1);
    }

    public function test_check_command_returns_success_when_chain_is_healthy(): void
    {
        $chain = $this->createChain();

        $this->artisan('check:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
        ])->assertExitCode(0);
    }

    public function test_check_command_filters_by_reason(): void
    {
        $chain = $this->createChain();
        $chain['agentCashFlow']->update(['status' => 0]);

        $this->artisan('check:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
            '--reason' => 'L2_order_type_disabled',
        ])->assertExitCode(0);
    }

    public function test_check_command_rejects_unknown_cashflow(): void
    {
        $this->artisan('check:cashflow-orphans', [
            '--cashflow' => '999999999',
        ])->assertExitCode(1);
    }

    public function test_check_command_rejects_unknown_scope(): void
    {
        $this->artisan('check:cashflow-orphans', [
            '--scope' => 'unknown',
        ])->assertExitCode(1);
    }

    public function test_scope_enum_apis_filters_above_boundary(): void
    {
        $query = \DB::table('cash_flows');
        CashFlowScopeEnum::APIS->applyToQuery($query, 'cash_flow_id');

        $this->assertStringContainsString('`cash_flow_id` > ?', $query->toSql());
        $this->assertSame([CashFlowScopeEnum::LEGACY_BOUNDARY_CASH_FLOW_ID], $query->getBindings());
    }

    public function test_scope_enum_legacy_filters_below_boundary(): void
    {
        $query = \DB::table('cash_flows');
        CashFlowScopeEnum::LEGACY->applyToQuery($query, 'cash_flow_id');

        $this->assertStringContainsString('`cash_flow_id` <= ?', $query->toSql());
        $this->assertSame([CashFlowScopeEnum::LEGACY_BOUNDARY_CASH_FLOW_ID], $query->getBindings());
    }

    public function test_scope_enum_all_is_no_op(): void
    {
        $query = \DB::table('cash_flows');
        CashFlowScopeEnum::ALL->applyToQuery($query, 'cash_flow_id');

        $this->assertEmpty($query->getBindings());
    }

    public function test_observer_blocks_creating_with_mismatched_cash_flow(): void
    {
        $chain = $this->createChain();
        $otherCashFlow = CashFlow::create([
            'name' => 'Other ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);
        $otherOrderType = OrderType::create([
            'cash_flow_id' => $otherCashFlow->id,
            'currency_id' => 1,
            'name' => 'mismatch',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => '--',
            'info' => '{}',
            'admin' => 0,
        ]);

        $this->expectException(ApiException::class);
        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $chain['agentCashFlow']->id,
            'order_type_id' => $otherOrderType->id,
            'status' => 1,
            'settings' => '{}',
        ]);
    }

    public function test_observer_allows_creating_when_cash_flow_aligned(): void
    {
        $chain = $this->createChain();
        $extraOrderType = OrderType::create([
            'cash_flow_id' => $chain['cashFlow']->id,
            'currency_id' => 1,
            'name' => 'extra',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => '--',
            'info' => '{}',
            'admin' => 0,
        ]);

        $acfot = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $chain['agentCashFlow']->id,
            'order_type_id' => $extraOrderType->id,
            'status' => 1,
            'settings' => '{}',
        ]);

        $this->assertNotNull($acfot->id);
    }

    public function test_observer_blocks_updating_to_mismatched_order_type(): void
    {
        $chain = $this->createChain();
        $otherCashFlow = CashFlow::create([
            'name' => 'Other ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);
        $otherOrderType = OrderType::create([
            'cash_flow_id' => $otherCashFlow->id,
            'currency_id' => 1,
            'name' => 'mismatch',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => '--',
            'info' => '{}',
            'admin' => 0,
        ]);

        $this->expectException(ApiException::class);
        $chain['childAcfot']->update(['order_type_id' => $otherOrderType->id]);
    }

    public function test_observer_skips_revalidation_when_only_status_changes(): void
    {
        $chain = $this->createChain();

        $chain['childAcfot']->update(['status' => 0]);

        $this->assertSame(0, (int) $chain['childAcfot']->refresh()->status);
    }

    public function test_observer_bypasses_when_acf_points_to_legacy_cash_flow(): void
    {
        $legacyAcf = AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => 42,
            'currency_id' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);
        $apisCashFlow = CashFlow::create([
            'name' => 'APIs ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);
        $apisOrderType = OrderType::create([
            'cash_flow_id' => $apisCashFlow->id,
            'currency_id' => 1,
            'name' => 'apis-ot',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => '--',
            'info' => '{}',
            'admin' => 0,
        ]);

        $acfot = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $legacyAcf->id,
            'order_type_id' => $apisOrderType->id,
            'status' => 1,
            'settings' => '{}',
        ]);

        $this->assertNotNull($acfot->id);
    }

    public function test_observer_blocks_creating_when_agent_cash_flow_missing(): void
    {
        $this->expectException(ApiException::class);
        AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => 999999999,
            'order_type_id' => 1,
            'status' => 1,
            'settings' => '{}',
        ]);
    }

    public function test_fix_command_dry_run_does_not_modify(): void
    {
        $chain = $this->createChain();
        $chain['childOrderType']->update(['status' => 0]);

        $this->artisan('fix:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
            '--dry-run' => true,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(1, (int) AgentCashFlowOrderType::query()
            ->where('id', $chain['childAcfot']->id)
            ->value('status'));
    }

    public function test_fix_command_disables_orphans(): void
    {
        $chain = $this->createChain();
        $chain['childOrderType']->update(['status' => 0]);

        $this->artisan('fix:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) AgentCashFlowOrderType::query()
            ->where('id', $chain['childAcfot']->id)
            ->value('status'));

        $this->assertSame(1, (int) AgentCashFlowOrderType::query()
            ->where('id', $chain['rootAcfot']->id)
            ->value('status'), 'healthy acfot should remain active');
    }

    public function test_fix_command_respects_reason_filter(): void
    {
        $chain = $this->createChain();
        $chain['childOrderType']->update(['status' => 0]);
        $this->disableAcfBypassingObserver((int) $chain['agentCashFlow']->id);

        $this->artisan('fix:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
            '--reason' => 'L2_order_type_disabled',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertSame(0, (int) AgentCashFlowOrderType::query()
            ->where('id', $chain['childAcfot']->id)
            ->value('status'));

        $this->assertSame(1, (int) AgentCashFlowOrderType::query()
            ->where('id', $chain['rootAcfot']->id)
            ->value('status'), 'L3 orphan should not be touched when filtered out');
    }

    /**
     * 以 raw SQL 停用 ACF，繞過 {@see \App\Observers\AgentCashFlowObserver} 的連動下架。
     *
     * Observer 已讓 Eloquent 路徑不再產出 L3 孤兒（下架即連動關閉旗下 acfot），
     * 因此實務上殘留的 L3 孤兒只來自 Observer 攔不到的路徑——raw SQL、其他 codebase、人工改 DB。
     * L3 相關測試改以此法造孤兒，才是在驗真正還會發生的情境（也是排程 --notify 安全網的守備範圍）。
     */
    private function disableAcfBypassingObserver(int $agentCashFlowId): void
    {
        DB::table('agent_cash_flows')->where('id', $agentCashFlowId)->update(['status' => 0]);
    }

    public function test_fix_command_fails_on_unknown_reason(): void
    {
        $chain = $this->createChain();

        $this->artisan('fix:cashflow-orphans', [
            '--cashflow' => (string) $chain['cashFlow']->id,
            '--reason' => 'L9_no_such_reason',
            '--force' => true,
        ])->assertExitCode(1);
    }

    /**
     * 建立完整 cash_flow → order_types (root + child) → agent_cash_flow → acfot 鏈。
     *
     * @return array{
     *   cashFlow: CashFlow,
     *   parentOrderType: OrderType,
     *   childOrderType: OrderType,
     *   agentCashFlow: AgentCashFlow,
     *   rootAcfot: AgentCashFlowOrderType,
     *   childAcfot: AgentCashFlowOrderType,
     * }
     */
    private function createChain(): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test ' . uniqid(),
            'type' => 1,
            'status' => 1,
            'admin' => 0,
        ]);

        $agentCashFlow = AgentCashFlow::create([
            'agent_group_id' => $this->agent->cash_flow_group_id,
            'cash_flow_info_id' => 1,
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'status' => 1,
            'info' => '',
            'admin' => 0,
        ]);

        $parent = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'parent',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => '--',
            'info' => '{}',
            'admin' => 0,
        ]);

        $child = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => 1,
            'name' => 'child',
            'type' => 1,
            'status' => 1,
            'parent' => $parent->id,
            'key' => '--',
            'info' => '{}',
            'admin' => 0,
        ]);

        $rootAcfot = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $parent->id,
            'status' => 1,
            'settings' => '{}',
        ]);

        $childAcfot = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $agentCashFlow->id,
            'order_type_id' => $child->id,
            'status' => 1,
            'settings' => '{}',
        ]);

        return [
            'cashFlow' => $cashFlow,
            'parentOrderType' => $parent,
            'childOrderType' => $child,
            'agentCashFlow' => $agentCashFlow,
            'rootAcfot' => $rootAcfot,
            'childAcfot' => $childAcfot,
        ];
    }
}
