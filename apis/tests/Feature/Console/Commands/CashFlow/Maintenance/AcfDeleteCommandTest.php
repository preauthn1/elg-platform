<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Models\AcfCascadeDeactivateLog;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\Order;
use App\Models\OrderType;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/Maintenance/AcfDeleteCommandTest.php
 *
 * fix:acf-delete：軟刪除單筆 ACF（連動軟刪旗下 ACFOT）與依審計 log 還原。
 *
 * 刻意設計：
 * - 軟刪除（SoftDeletes）＝可回滾；連動與審計由 AgentCashFlowObserver 統一承擔。
 * - 只允許刪「無業務足跡」的列：五張事實表（orders / order_histories /
 *   withdrawal_payout_records / ota_orders / summary_profit_share_payment_costs）
 *   任一有引用即拒刪——有史料的是真配置，不是該刪的重複列。
 */
class AcfDeleteCommandTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    public function test_dry_run_reports_without_deleting(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots();

        $this->artisan('fix:acf-delete', ['--acf' => (string) $acf->id, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNotNull(AgentCashFlow::query()->find($acf->id), '乾跑不得刪除 ACF');
        $this->assertNotNull(AgentCashFlowOrderType::query()->find($acfots[0]->id), '乾跑不得刪除 ACFOT');
        $this->assertSame(
            0,
            AcfCascadeDeactivateLog::query()->where('agent_cash_flow_id', $acf->id)->count(),
            '乾跑不得寫審計',
        );
    }

    public function test_delete_soft_deletes_acf_and_cascades(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots();

        $this->artisan('fix:acf-delete', ['--acf' => (string) $acf->id, '--force' => true])
            ->assertExitCode(0);

        $trashed = AgentCashFlow::withTrashed()->find($acf->id);
        $this->assertNotNull($trashed->deleted_at, 'ACF 應被軟刪');
        $this->assertSame(0, (int) $trashed->status, '軟刪列 status 應歸 0（舊系統保險）');
        $this->assertNotNull(
            AgentCashFlowOrderType::withTrashed()->find($acfots[0]->id)->deleted_at,
            'ACFOT 應被連動軟刪',
        );

        $this->assertGreaterThan(
            0,
            AcfCascadeDeactivateLog::query()
                ->where('agent_cash_flow_id', $acf->id)
                ->where('action', AcfCascadeDeactivateLog::ACTION_SOFT_DELETE)
                ->count(),
            '應留下 soft_delete 審計供還原',
        );
    }

    public function test_delete_refused_when_orders_reference(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots();

        Order::create([
            'order_no' => 'ACFDEL' . uniqid(),
            'third_party_order_no' => 'TP' . uniqid(),
            'agent_cash_flow_id' => $acf->id,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $acf->cash_flow_id,
            'order_type_id' => $acfots[0]->order_type_id,
            'currency_id' => self::CURRENCY_MYR,
            'member_id' => 990003,
            'amount' => 100,
            'point' => 100,
            'balance' => 100,
            'remark' => '',
            'admin' => 0,
        ]);

        $this->artisan('fix:acf-delete', ['--acf' => (string) $acf->id, '--force' => true])
            ->assertExitCode(1);

        $this->assertNotNull(AgentCashFlow::query()->find($acf->id), '有訂單足跡必須拒刪');
    }

    public function test_restore_revives_and_closes_loop(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots();

        $this->artisan('fix:acf-delete', ['--acf' => (string) $acf->id, '--force' => true])
            ->assertExitCode(0);

        $this->artisan('fix:acf-delete', ['--acf' => (string) $acf->id, '--restore' => true, '--force' => true])
            ->assertExitCode(0);

        $revived = AgentCashFlow::query()->find($acf->id);
        $this->assertNotNull($revived, '還原後 ACF 應復活');
        $this->assertSame(0, (int) $revived->status, '還原不得自動啟用，須人工確認後開啟');
        $this->assertNotNull(
            AgentCashFlowOrderType::query()->find($acfots[0]->id),
            '連動軟刪的 ACFOT 應隨還原復活',
        );

        $this->assertSame(
            0,
            AcfCascadeDeactivateLog::query()
                ->where('agent_cash_flow_id', $acf->id)
                ->where('action', AcfCascadeDeactivateLog::ACTION_SOFT_DELETE)
                ->whereNull('restored_by_log_id')
                ->count(),
            '還原後 soft_delete 紀錄應全數閉環',
        );
    }

    public function test_restore_on_live_acf_fails(): void
    {
        [$acf] = $this->makeAcfWithAcfots();

        $this->artisan('fix:acf-delete', ['--acf' => (string) $acf->id, '--restore' => true, '--force' => true])
            ->assertExitCode(1);
    }

    public function test_unknown_acf_id_fails(): void
    {
        $this->artisan('fix:acf-delete', ['--acf' => '99999999', '--force' => true])
            ->assertExitCode(1);
    }

    public function test_orphans_dry_run_lists_empty_acf_without_deleting(): void
    {
        [$configuredAcf] = $this->makeAcfWithAcfots();
        $emptyAcf = $this->makeEmptyAcf();

        $this->artisan('fix:acf-delete', ['--orphans' => true, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertNotNull(AgentCashFlow::query()->find($emptyAcf->id), '乾跑不得刪除空殼 ACF');
        $this->assertNotNull(AgentCashFlow::query()->find($configuredAcf->id), '乾跑不得動到任何 ACF');
        $this->assertSame(
            0,
            AcfCascadeDeactivateLog::query()->where('agent_cash_flow_id', $emptyAcf->id)->count(),
            '乾跑不得寫審計',
        );
    }

    public function test_orphans_deletes_empty_acf_and_keeps_footprint_and_configured(): void
    {
        [$configuredAcf, $acfots] = $this->makeAcfWithAcfots();
        $deletableAcf = $this->makeEmptyAcf();
        $footprintAcf = $this->makeEmptyAcf();

        Order::create([
            'order_no' => 'ACFORP' . uniqid(),
            'third_party_order_no' => 'TP' . uniqid(),
            'agent_cash_flow_id' => $footprintAcf->id,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => $footprintAcf->cash_flow_id,
            'order_type_id' => $acfots[0]->order_type_id,
            'currency_id' => self::CURRENCY_MYR,
            'member_id' => 990004,
            'amount' => 100,
            'point' => 100,
            'balance' => 100,
            'remark' => '',
            'admin' => 0,
        ]);

        $this->artisan('fix:acf-delete', ['--orphans' => true, '--force' => true])
            ->assertExitCode(0);

        $trashed = AgentCashFlow::withTrashed()->find($deletableAcf->id);
        $this->assertNotNull($trashed->deleted_at, '零足跡空殼 ACF 應被軟刪');
        $this->assertSame(0, (int) $trashed->status, '軟刪列 status 應歸 0（舊系統保險）');
        $this->assertGreaterThan(
            0,
            AcfCascadeDeactivateLog::query()
                ->where('agent_cash_flow_id', $deletableAcf->id)
                ->where('action', AcfCascadeDeactivateLog::ACTION_SOFT_DELETE)
                ->count(),
            '應留下 soft_delete 審計供還原',
        );

        $this->assertNotNull(
            AgentCashFlow::query()->find($footprintAcf->id),
            '有訂單足跡的空殼 ACF 必須保留（史料引用不可斷）',
        );
        $this->assertNotNull(
            AgentCashFlow::query()->find($configuredAcf->id),
            '有 ACFOT 的 ACF 不在孤兒範疇，不得誤刪',
        );
        $this->assertNull(
            AgentCashFlowOrderType::query()->find($acfots[0]->id)?->deleted_at,
            '正常配置的 ACFOT 不得被連動',
        );
    }

    public function test_orphans_excludes_non_deposit_cash_flow_types(): void
    {
        $payoutAcf = $this->makeEmptyAcf(3);
        $autoDepositAcf = $this->makeEmptyAcf(2);
        $autoWithdrawAcf = $this->makeEmptyAcf(4);

        $this->artisan('fix:acf-delete', ['--orphans' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertNotNull(
            AgentCashFlow::query()->find($payoutAcf->id),
            '代付（type 3）零 ACFOT 是常態形態，不得列入孤兒',
        );
        $this->assertNotNull(
            AgentCashFlow::query()->find($autoDepositAcf->id),
            '自助開分（type 2）屬高風險自動渠道，不得批次刪',
        );
        $this->assertNotNull(
            AgentCashFlow::query()->find($autoWithdrawAcf->id),
            '自助洗分（type 4）runtime 直讀 ACF（不經 ACFOT），不得批次刪',
        );
        $this->assertSame(
            0,
            AcfCascadeDeactivateLog::query()
                ->whereIn('agent_cash_flow_id', [$payoutAcf->id, $autoDepositAcf->id, $autoWithdrawAcf->id])
                ->count(),
            '被排除的型別不得產生任何審計異動',
        );
    }

    public function test_orphans_conflicts_with_acf_and_restore_options(): void
    {
        $this->artisan('fix:acf-delete', ['--orphans' => true, '--acf' => '123'])
            ->assertExitCode(1);

        $this->artisan('fix:acf-delete', ['--orphans' => true, '--restore' => true])
            ->assertExitCode(1);
    }

    /**
     * @return array{0: AgentCashFlow, 1: \Illuminate\Support\Collection<int, AgentCashFlowOrderType>}
     */
    private function makeAcfWithAcfots(): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test AcfDelete ' . uniqid(),
            'type' => 1,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        $acf = AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => '(MYR) AcfDelete Test',
            'rate' => 1,
            'info' => ['API_URL' => 'https://merchant.example'],
            'status' => 1,
            'admin' => 0,
        ]);

        $orderType = OrderType::create([
            'cash_flow_id' => $cashFlow->id,
            'currency_id' => self::CURRENCY_MYR,
            'name' => 'AcfDelete OT',
            'type' => 1,
            'status' => 1,
            'parent' => 0,
            'key' => 'ACFDEL_' . uniqid(),
            'choose_bank' => 0,
            'info' => '{}',
            'admin' => 0,
        ]);

        $acfot = AgentCashFlowOrderType::create([
            'agent_cash_flow_id' => $acf->id,
            'order_type_id' => $orderType->id,
            'cash_flow_id' => $cashFlow->id,
            'min_amount' => 0,
            'max_amount' => 0,
            'available_amount' => '',
            'settings' => '{}',
            'sorts' => 0,
            'status' => 1,
        ]);

        return [$acf, collect([$acfot])];
    }

    private function makeEmptyAcf(int $cashFlowType = 1): AgentCashFlow
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test AcfDelete Empty ' . uniqid(),
            'type' => $cashFlowType,
            'third_party_code' => 'D_TTPAY',
            'status' => 1,
            'admin' => 0,
        ]);

        return AgentCashFlow::create([
            'cash_flow_id' => $cashFlow->id,
            'agent_group_id' => self::AGENT_GROUP,
            'currency_id' => self::CURRENCY_MYR,
            'cash_flow_info_id' => 0,
            'name' => '(MYR) AcfDelete Empty',
            'rate' => 1,
            'info' => ['API_URL' => 'https://merchant.example'],
            'status' => 1,
            'admin' => 0,
        ]);
    }
}
