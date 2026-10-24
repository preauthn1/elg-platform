<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Maintenance;

use App\Models\AcfCascadeDeactivateLog;
use App\Models\AgentCashFlow;
use App\Models\AgentCashFlowOrderType;
use App\Models\CashFlow;
use App\Models\OrderType;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * php artisan test --compact tests/Feature/Console/Commands/CashFlow/Maintenance/AcfCascadeRestoreTest.php
 *
 * 依審計 log 還原「被 ACF 下架連動關閉」的 ACFOT。
 *
 * 關鍵界線：只還原 log 記錄過的列（from_status 寫回），營運自行停用的 ACFOT 從未進 log，
 * 因此永遠不會被誤開——這是「ACF 重啟不自動連動開回」的配套顯式操作。
 */
class AcfCascadeRestoreTest extends TestCase
{
    private const int CURRENCY_MYR = 1;

    private const int AGENT_GROUP = 688;

    public function test_restores_only_cascade_deactivated_rows(): void
    {
        // acfot[0] active、acfot[1] 營運自行停用 → 下架連動只會記錄 acfot[0]
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfotStatuses: [1, 0]);
        $acf->update(['status' => 0]);

        $this->artisan('fix:acf-cascade-restore', ['--force' => true])
            ->assertExitCode(0);

        $this->assertSame(1, (int) $acfots[0]->refresh()->status, '被連動下架的應還原為原 status');
        $this->assertSame(0, (int) $acfots[1]->refresh()->status, '營運自行停用的不得被還原（從未進 log）');
    }

    public function test_restore_marks_log_and_is_idempotent(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfotStatuses: [1]);
        $acf->update(['status' => 0]);

        $cascadeLog = AcfCascadeDeactivateLog::query()
            ->where('agent_cash_flow_id', $acf->id)
            ->where('action', AcfCascadeDeactivateLog::ACTION_CASCADE_DEACTIVATE)
            ->firstOrFail();

        $this->artisan('fix:acf-cascade-restore', ['--force' => true])->assertExitCode(0);

        // 原 log 回填 restored_by_log_id，並新增一筆 restore 事件
        $cascadeLog->refresh();
        $this->assertNotNull($cascadeLog->restored_by_log_id, '還原後原 log 應回填 restored_by_log_id 形成閉環');

        $restoreLog = AcfCascadeDeactivateLog::query()->find($cascadeLog->restored_by_log_id);
        $this->assertNotNull($restoreLog);
        $this->assertSame(AcfCascadeDeactivateLog::ACTION_RESTORE, $restoreLog->action);
        $this->assertSame(AcfCascadeDeactivateLog::SOURCE_COMMAND, $restoreLog->source);

        // 冪等：重跑不再處理，且不得再開任何列
        $acfots[0]->update(['status' => 0]);
        $this->artisan('fix:acf-cascade-restore', ['--force' => true])->assertExitCode(0);
        $this->assertSame(0, (int) $acfots[0]->refresh()->status, '已還原過的 log 不得重複還原');
    }

    public function test_dry_run_does_not_write(): void
    {
        [$acf, $acfots] = $this->makeAcfWithAcfots(acfotStatuses: [1]);
        $acf->update(['status' => 0]);

        $this->artisan('fix:acf-cascade-restore', ['--dry-run' => true, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(0, (int) $acfots[0]->refresh()->status, 'dry-run 不得寫入');
        $this->assertSame(
            0,
            AcfCascadeDeactivateLog::query()
                ->where('agent_cash_flow_id', $acf->id)
                ->where('action', AcfCascadeDeactivateLog::ACTION_RESTORE)
                ->count(),
            'dry-run 不得寫審計',
        );
    }

    public function test_acf_option_scopes_restore(): void
    {
        [$acfA, $acfotsA] = $this->makeAcfWithAcfots(acfotStatuses: [1]);
        [$acfB, $acfotsB] = $this->makeAcfWithAcfots(acfotStatuses: [1]);
        $acfA->update(['status' => 0]);
        $acfB->update(['status' => 0]);

        $this->artisan('fix:acf-cascade-restore', ['--acf' => (string) $acfA->id, '--force' => true])
            ->assertExitCode(0);

        $this->assertSame(1, (int) $acfotsA[0]->refresh()->status, '指定的 ACF 應被還原');
        $this->assertSame(0, (int) $acfotsB[0]->refresh()->status, '未指定的 ACF 不得被動到');
    }

    /**
     * @param  array<int, int>  $acfotStatuses
     * @return array{0: AgentCashFlow, 1: Collection<int, AgentCashFlowOrderType>}
     */
    private function makeAcfWithAcfots(array $acfotStatuses): array
    {
        $cashFlow = CashFlow::create([
            'name' => 'Test Restore ' . uniqid(),
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
            'name' => '(MYR) Restore Test',
            'rate' => 1,
            'info' => ['API_URL' => 'https://merchant.example'],
            'status' => 1,
            'admin' => 0,
        ]);

        $acfots = collect($acfotStatuses)->map(function (int $status, int $i) use ($cashFlow, $acf): AgentCashFlowOrderType {
            $orderType = OrderType::create([
                'cash_flow_id' => $cashFlow->id,
                'currency_id' => self::CURRENCY_MYR,
                'name' => 'Restore OT ' . $i,
                'type' => 1,
                'status' => 1,
                'parent' => 0,
                'key' => 'RESTORE_' . $i . '_' . uniqid(),
                'choose_bank' => 0,
                'info' => '{}',
                'admin' => 0,
            ]);

            return AgentCashFlowOrderType::create([
                'agent_cash_flow_id' => $acf->id,
                'order_type_id' => $orderType->id,
                'cash_flow_id' => $cashFlow->id,
                'min_amount' => 0,
                'max_amount' => 0,
                'available_amount' => '',
                'settings' => '{}',
                'sorts' => 0,
                'status' => $status,
            ]);
        });

        return [$acf, $acfots];
    }
}
