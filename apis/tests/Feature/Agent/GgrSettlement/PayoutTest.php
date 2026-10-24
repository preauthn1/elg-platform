<?php

declare(strict_types=1);

namespace Tests\Feature\Agent\GgrSettlement;

use App\Enums\Agent\AgentGroupTypeEnum;
use App\Enums\Agent\GgrSettlementCycleEnum;
use App\Enums\Agent\RoleEnum;
use App\Enums\GgrPayoutStatusEnum;
use App\Enums\StatusEnum;
use App\Enums\Transaction\TransactionLogAgentPlatformEnum;
use App\Enums\Transaction\TransactionLogAgentStatusEnum;
use App\Enums\Transaction\TransactionLogAgentTypeEnum;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use App\Models\TransactionLogAgent;
use App\Services\Agent\GgrSettlement\PayoutService;
use Tests\TestCase;

/**
 * GGR 發放入帳（批次七）：UNPAID 列入帳到代理餘額、狀態守門冪等、
 * 0 元標記、停用代理與停用方案留 UNPAID、方案篩選、dry-run 與實跑同源判定。
 */
class PayoutTest extends TestCase
{
    private Agent $general;

    private GgrPlan $plan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->general = $this->createAgentAndDomain();
        $this->plan = $this->makePlan();
    }

    public function test_payout_credits_agent_and_marks_paid(): void
    {
        $agent = $this->makeLineAgent(balance: '100.0000');
        $settlement = $this->makeSettlement($this->plan, $agent->id, '748.00');

        $mode = app(PayoutService::class)->payoutOne($settlement->id);

        $this->assertSame(PayoutService::MODE_PAID, $mode);

        $agent->refresh();
        $this->assertSame('848.0000', (string) $agent->balance);

        $settlement->refresh();
        $this->assertSame(GgrPayoutStatusEnum::PAID->value, (int) $settlement->payout_status);
        $this->assertNotNull($settlement->paid_at);
        $this->assertNull($settlement->admin, '系統發放不掛管理者');

        $log = TransactionLogAgent::query()->where('to_agent_id', $agent->id)->get();
        $this->assertCount(1, $log);
        $this->assertSame(TransactionLogAgentTypeEnum::GGR_PROFIT_PAYOUT->value, (int) $log[0]->type);
        $this->assertSame($this->general->id, (int) $log[0]->from_agent_id, 'from 取結算列快照的總代');
        $this->assertSame('748.0000', (string) $log[0]->amount);
        $this->assertSame('848.0000', (string) $log[0]->to_limit_of_credit, '入帳後餘額照慣例塞 to_limit_of_credit');
        $this->assertSame(TransactionLogAgentStatusEnum::SUCCESS->value, (int) $log[0]->status);
        $this->assertSame(TransactionLogAgentPlatformEnum::SYSTEM->value, (int) $log[0]->platform);
        $this->assertSame(TransactionLogAgent::OPERATOR_SYSTEM, $log[0]->operator);
        $this->assertStringContainsString("ggr_settlement:{$settlement->id}", (string) $log[0]->remark);
    }

    public function test_payout_is_idempotent(): void
    {
        $agent = $this->makeLineAgent(balance: '0');
        $settlement = $this->makeSettlement($this->plan, $agent->id, '500.00');

        $service = app(PayoutService::class);
        $first = $service->payoutOne($settlement->id);
        $second = $service->payoutOne($settlement->id);

        $this->assertSame(PayoutService::MODE_PAID, $first);
        $this->assertSame(PayoutService::MODE_ALREADY_PAID, $second, '持鎖後狀態守門擋下重複觸發');
        $this->assertSame('500.0000', (string) $agent->refresh()->balance, '餘額只加一次');
        $this->assertSame(1, TransactionLogAgent::query()->where('to_agent_id', $agent->id)->count());
    }

    public function test_zero_payable_marks_paid_without_crediting(): void
    {
        $agent = $this->makeLineAgent(balance: '100.0000');
        $settlement = $this->makeSettlement($this->plan, $agent->id, '0.00', finalAmount: '-552.00');

        $mode = app(PayoutService::class)->payoutOne($settlement->id);

        $this->assertSame(PayoutService::MODE_ZERO_PAID, $mode);
        $this->assertSame('100.0000', (string) $agent->refresh()->balance);
        $this->assertSame(GgrPayoutStatusEnum::PAID->value, (int) $settlement->refresh()->payout_status);
        $this->assertNotNull($settlement->paid_at);
        $this->assertSame(0, TransactionLogAgent::query()->where('to_agent_id', $agent->id)->count());
    }

    public function test_inactive_agent_stays_unpaid_and_retries_after_enable(): void
    {
        $agent = $this->makeLineAgent(balance: '0');
        $agent->update(['status' => StatusEnum::DISABLE->value]);
        $settlement = $this->makeSettlement($this->plan, $agent->id, '300.00');

        $service = app(PayoutService::class);

        $this->assertSame(PayoutService::MODE_AGENT_SKIPPED, $service->payoutOne($settlement->id));
        $this->assertSame(GgrPayoutStatusEnum::UNPAID->value, (int) $settlement->refresh()->payout_status, '停用代理留 UNPAID 下輪重試');
        $this->assertSame('0.0000', (string) $agent->refresh()->balance);

        // 代理恢復後重跑即補發
        $agent->update(['status' => StatusEnum::ENABLE->value]);
        $this->assertSame(PayoutService::MODE_PAID, $service->payoutOne($settlement->id));
        $this->assertSame('300.0000', (string) $agent->refresh()->balance);
    }

    /** 方案停用＝營運止血手段：尚未發放的列不得入帳，重新啟用後照常補發 */
    public function test_disabled_plan_is_not_paid_out(): void
    {
        $agent = $this->makeLineAgent(balance: '0');
        $settlement = $this->makeSettlement($this->plan, $agent->id, '400.00');
        $this->plan->update(['status' => StatusEnum::DISABLE->value]);

        $service = app(PayoutService::class);

        $this->assertSame([], $service->pendingRows(), '停用方案不出現在待發放清單');
        $this->assertSame(0, $service->payoutPending()['counts'][PayoutService::MODE_PAID]);
        $this->assertSame(GgrPayoutStatusEnum::UNPAID->value, (int) $settlement->refresh()->payout_status);
        $this->assertSame('0.0000', (string) $agent->refresh()->balance);

        // payoutOne 為公開入口，單獨呼叫也要擋
        $this->assertSame(PayoutService::MODE_PLAN_SKIPPED, $service->payoutOne($settlement->id));

        $this->plan->update(['status' => StatusEnum::ENABLE->value]);
        $this->assertSame(PayoutService::MODE_PAID, $service->payoutOne($settlement->id));
        $this->assertSame('400.0000', (string) $agent->refresh()->balance);
    }

    /** 結算重跑刪掉待發放列：回報「已刪除」而非「已發放」 */
    public function test_deleted_settlement_reports_missing(): void
    {
        $agent = $this->makeLineAgent(balance: '0');
        $settlement = $this->makeSettlement($this->plan, $agent->id, '100.00');
        $settlementId = $settlement->id;
        $settlement->delete();

        $this->assertSame(PayoutService::MODE_MISSING, app(PayoutService::class)->payoutOne($settlementId));
        $this->assertSame('0.0000', (string) $agent->refresh()->balance);
    }

    public function test_payout_pending_filters_by_plan(): void
    {
        $agentA = $this->makeLineAgent(balance: '0');
        $agentB = $this->makeLineAgent(balance: '0');
        $planB = $this->makePlan();
        $this->makeSettlement($this->plan, $agentA->id, '100.00');
        $this->makeSettlement($planB, $agentB->id, '200.00');

        $stats = app(PayoutService::class)->payoutPending($this->plan->id);

        $this->assertSame(1, $stats['counts'][PayoutService::MODE_PAID]);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame('100.0000', (string) $agentA->refresh()->balance);
        $this->assertSame('0.0000', (string) $agentB->refresh()->balance, '未指定的方案不發');

        // 不帶方案＝全部 UNPAID
        $statsAll = app(PayoutService::class)->payoutPending();
        $this->assertSame(1, $statsAll['counts'][PayoutService::MODE_PAID]);
        $this->assertSame('200.0000', (string) $agentB->refresh()->balance);
    }

    /** 單列失敗不中斷其他列，且統計如實回報失敗數 */
    public function test_single_row_failure_does_not_stop_others(): void
    {
        $good = $this->makeLineAgent(balance: '0');
        $this->makeSettlement($this->plan, $good->id, '150.00');

        // 代理列不存在（硬刪）→ 該列 agent_skipped；另造一列正常發放
        $orphan = $this->makeLineAgent(balance: '0');
        $orphanSettlement = $this->makeSettlement($this->plan, $orphan->id, '250.00');
        $orphan->delete();

        $stats = app(PayoutService::class)->payoutPending();

        $this->assertSame(1, $stats['counts'][PayoutService::MODE_PAID]);
        $this->assertSame(1, $stats['counts'][PayoutService::MODE_AGENT_SKIPPED]);
        $this->assertSame(0, $stats['failed']);
        $this->assertSame('150.0000', (string) $good->refresh()->balance);
        $this->assertSame(GgrPayoutStatusEnum::UNPAID->value, (int) $orphanSettlement->refresh()->payout_status);
    }

    /** dry-run 唯讀，且模式判定與實跑同源（停用代理不得顯示為將入帳） */
    public function test_pending_rows_is_readonly_and_matches_real_run(): void
    {
        $agent = $this->makeLineAgent(balance: '0');
        $settlement = $this->makeSettlement($this->plan, $agent->id, '100.00');

        $disabled = $this->makeLineAgent(balance: '0');
        $disabledSettlement = $this->makeSettlement($this->plan, $disabled->id, '300.00');
        $disabled->update(['status' => StatusEnum::DISABLE->value]);

        $service = app(PayoutService::class);
        $rows = collect($service->pendingRows($this->plan->id))->keyBy('id');

        $this->assertCount(2, $rows);
        $this->assertSame(PayoutService::MODE_PAID, $rows[$settlement->id]['mode']);
        $this->assertSame(PayoutService::MODE_AGENT_SKIPPED, $rows[$disabledSettlement->id]['mode'], '停用代理在乾跑就標為跳過');
        $this->assertSame($agent->username, $rows[$settlement->id]['agent_username']);
        $this->assertSame(GgrPayoutStatusEnum::UNPAID->value, (int) $settlement->refresh()->payout_status, 'dry-run 不落庫');
        $this->assertSame('0.0000', (string) $agent->refresh()->balance);

        // 實跑結果與乾跑標示一致
        $stats = $service->payoutPending($this->plan->id);
        $this->assertSame(1, $stats['counts'][PayoutService::MODE_PAID]);
        $this->assertSame(1, $stats['counts'][PayoutService::MODE_AGENT_SKIPPED]);
    }

    // ---------------------------------------------------------------- helpers

    private function makePlan(): GgrPlan
    {
        $group = AgentGroup::create([
            'agent_group_type' => AgentGroupTypeEnum::CUSTOMIZED_SETTING->value,
            'organization_id' => 1,
            'name' => 'payout group ' . bin2hex(random_bytes(3)),
            'memo' => '',
            'is_default' => 0,
        ]);

        return GgrPlan::create([
            'general_agent_id' => $this->general->id,
            'name' => 'payout plan ' . bin2hex(random_bytes(4)),
            'settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'agent_group_id' => $group->id,
            'start_date' => '2026-08-01',
            'carry_negative' => 0,
            'admin_fee_rate' => '0.00', 'handling_fee' => '0.00', 'ggr_rate' => '100.00', 'bonus_rate' => '0.00',
            'kyc_unique_bank_account' => 0, 'kyc_unique_contact_no' => 0, 'kyc_unique_email' => 0,
            'kyc_unique_ip' => 0, 'kyc_unique_name' => 0, 'kyc_violate_count' => 0,
            'downline_vendor_setting_id' => 0,
            'downline_condition_id' => 0,
            'status' => StatusEnum::ENABLE->value,
            'created_by' => $this->general->id,
            'admin' => $this->general->id,
        ]);
    }

    private function makeLineAgent(string $balance): Agent
    {
        $unique = bin2hex(random_bytes(8));
        $agent = Agent::factory()->create([
            'code' => substr($unique, 0, 10),
            'username' => 'p' . substr($unique, 0, 15),
            'email' => substr($unique, 0, 12) . '@t.ag',
            'status' => StatusEnum::ENABLE->value,
            'role_id' => RoleEnum::AGENT->value,
            'balance' => $balance,
        ]);
        $agent->hierarchy_path = $this->general->hierarchy_path . '/' . $agent->id;
        $agent->hierarchy = "[-1,{$agent->id},-2]";
        $agent->save();

        return $agent;
    }

    private function makeSettlement(GgrPlan $plan, int $agentId, string $payable, string $finalAmount = ''): GgrSettlement
    {
        return GgrSettlement::create([
            'general_agent_id' => $plan->general_agent_id,
            'plan_id' => $plan->id,
            'agent_id' => $agentId,
            'settlement_cycle' => GgrSettlementCycleEnum::DAILY->value,
            'period_start' => '2026-08-20',
            'period_end' => '2026-08-20',
            'ggr_amount' => '0.00',
            'bonus_rate' => '0.00', 'ggr_rate' => '100.00', 'condition_rate' => '100.00', 'admin_fee_rate' => '0.00',
            'handling_fee' => '0.00',
            'final_amount' => $finalAmount !== '' ? $finalAmount : $payable,
            'payable_amount' => $payable,
            'carry_in_amount' => '0.00',
            'carry_out_amount' => '0.00',
            'calc_snapshot' => [],
            'payout_status' => GgrPayoutStatusEnum::UNPAID->value,
            'admin' => null,
        ]);
    }
}
