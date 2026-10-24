<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Enums\Agent\GgrSettlementCycleEnum;
use App\Enums\GgrPayoutStatusEnum;
use App\Enums\QueueJobKeyEnum;
use App\Enums\QueueJobRunSourceEnum;
use App\Enums\StatusEnum;
use App\Helpers\Traits\DistributedLock;
use App\Models\Agent;
use App\Models\GgrCondition;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use App\Models\GgrVendorSetting;
use App\Models\Member;
use App\Models\QueueJobRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * GGR 結算引擎總指揮：對一個 (方案, 期間) 產生 ggr_settlements（終態 UNPAID）。
 *
 * 防重跑三層（照 SBR SummaryBetRecordRunner 模式）：
 * 1. DistributedLock（key = plan × period_start，TTL > job timeout）
 * 2. 取鎖後查 QueueJobRun 完成標記（skipIfCompleted），已完成即跳過
 * 3. 寫入、markCompleted、ggr_plans.settled_through 推進同一 DB 交易（缺列＝缺資料）
 *
 * 結算對象＝方案 agent_group 內啟用中、且在方案總代 hierarchy 線內的代理
 * （個性化組別是全站共用、預設全員同組，不限線內會把別的總代的代理結進本總代）。
 *
 * 逐代理流程：解析綁定（特殊用戶 override 優先；綁定停用即跳過，8-6）
 * → 4124 條件判定（一階未達不產生紀錄，8-1）→ 4123 逐筆落階加權
 * → SR15 獎金歸集 → 公式④–⑨ → 發揚沖抵 → upsert。
 * 重跑語意：既存列 UNPAID 覆蓋、PAID 跳過並記 log（已發放的錢不可被洗掉）；
 * 本輪未產生結果的代理（不再達階、移出群組、綁定停用）其舊 UNPAID 列刪除。
 *
 * ⚠️ 手動重跑「中間期」會使其後已結期的 carry 鏈失準（carry_in 是結算當下
 * 快照）——重跑某期後，其後的 UNPAID 期應一併依序重跑。
 */
class SettlementRunner
{
    use DistributedLock;

    /** 鎖 TTL（秒）：須大於 job timeout（600） */
    private const int LOCK_TTL = 660;

    public function __construct(
        private readonly ConditionEvaluator $conditionEvaluator,
        private readonly BetCollector $betCollector,
        private readonly BonusCollector $bonusCollector,
        private readonly SettlementCalculator $calculator,
        private readonly CarrySettler $carrySettler,
        private readonly MemberEligibilityFilter $eligibilityFilter,
    ) {}

    /**
     * @return array{mode: string, settled: int, skipped_agents: int, agents: array<int, array<string, mixed>>}
     */
    public function runPlanPeriod(
        GgrPlan $plan,
        Carbon $periodStart,
        Carbon $periodEnd,
        GgrSettlementCycleEnum $cycle,
        QueueJobRunSourceEnum $source,
        bool $dryRun = false,
        bool $skipIfCompleted = true,
    ): array {
        $lockKey = sprintf('ggr-settlement:%d:%s', $plan->id, $periodStart->toDateString());

        return $this->withLock($lockKey, self::LOCK_TTL, function () use (
            $plan, $periodStart, $periodEnd, $cycle, $source, $dryRun, $skipIfCompleted
        ) {
            $bucketTime = $periodStart->toDateString() . ' 00:00:00';

            if ($skipIfCompleted
                && QueueJobRun::isCompleted(QueueJobKeyEnum::GGR_SETTLEMENT, $plan->id, $bucketTime)) {
                return ['mode' => 'skipped', 'settled' => 0, 'skipped_agents' => 0, 'agents' => []];
            }

            [$utcStart, $utcEnd] = $this->utcWindow($periodStart, $periodEnd);
            $businessStart = $periodStart->toDateString() . ' 00:00:00';
            $businessEnd = $periodEnd->copy()->addDay()->toDateString() . ' 00:00:00';

            $bindings = $this->resolveBindings($plan);
            $rows = [];
            $skippedAgents = 0;

            $generalAgent = Agent::query()->find($plan->general_agent_id);
            if ($generalAgent === null || $generalAgent->hierarchy_path === null) {
                throw new RuntimeException(sprintf('GGR 方案 %d 的總代 %d 不存在或無 hierarchy_path', $plan->id, $plan->general_agent_id));
            }

            $agents = Agent::query()
                ->where('customized_setting_group_id', $plan->agent_group_id)
                ->where('status', StatusEnum::ENABLE->value)
                ->where('hierarchy_path', 'like', $generalAgent->hierarchy_path . '/%')
                ->get();

            foreach ($agents as $agent) {
                $binding = $bindings['overrides'][$agent->id] ?? $bindings['default'];

                // 綁定的設定停用後不參與結算（4124 邊界 8-6 / 4123 整組 status）
                if ($binding['condition'] === null || $binding['vendor_setting'] === null) {
                    $skippedAgents++;

                    continue;
                }

                $row = $this->settleAgent(
                    $plan, $agent, $binding['condition'], $binding['vendor_setting'],
                    $periodStart, $periodEnd, $cycle, $utcStart, $utcEnd, $businessStart, $businessEnd,
                );

                if ($row === null) {
                    $skippedAgents++;

                    continue;
                }

                $rows[] = $row;
            }

            if ($dryRun) {
                return ['mode' => 'dry-run', 'settled' => count($rows), 'skipped_agents' => $skippedAgents, 'agents' => $rows];
            }

            DB::transaction(function () use ($plan, $rows, $periodStart, $periodEnd, $bucketTime, $source): void {
                foreach ($rows as $row) {
                    $this->upsertSettlement($plan, $row);
                }

                $this->deleteStaleUnpaidRows($plan, $periodStart, array_column($rows, 'agent_id'));
                $this->advanceSettledThrough($plan, $periodEnd);

                QueueJobRun::markCompleted(QueueJobKeyEnum::GGR_SETTLEMENT, $plan->id, $bucketTime, $source);
            });

            Log::info('GGR 結算完成', [
                'plan_id' => $plan->id,
                'period_start' => $periodStart->toDateString(),
                'period_end' => $periodEnd->toDateString(),
                'settled' => count($rows),
                'skipped_agents' => $skippedAgents,
                'source' => $source->value,
            ]);

            return ['mode' => 'settled', 'settled' => count($rows), 'skipped_agents' => $skippedAgents, 'agents' => $rows];
        });
    }

    /**
     * 單一代理整鏈計算；一階都沒達成回 null（不產生結算紀錄，8-1）。
     *
     * @return array<string, mixed>|null
     */
    private function settleAgent(
        GgrPlan $plan,
        Agent $agent,
        GgrCondition $condition,
        GgrVendorSetting $vendorSetting,
        Carbon $periodStart,
        Carbon $periodEnd,
        GgrSettlementCycleEnum $cycle,
        Carbon $utcStart,
        Carbon $utcEnd,
        string $businessStart,
        string $businessEnd,
    ): ?array {
        $directMemberIds = Member::query()->where('parent', $agent->id)->pluck('id')->all();

        // 帳戶驗證限制／領取前驗證：不合格會員在三個 collector 之前就剔除，
        // 讓 GGR、獎金與 #4124 人數判定共用同一份名單（次數 0＝不限制）
        $eligibility = $this->eligibilityFilter->filter($plan, $directMemberIds);
        $memberIds = $eligibility['member_ids'];

        $conditionResult = $this->conditionEvaluator->evaluate($condition, $agent, $memberIds, $utcStart, $utcEnd);
        if ($conditionResult['tier'] === null) {
            return null;
        }

        $bet = $this->betCollector->collect($vendorSetting, $memberIds, $utcStart, $utcEnd);
        $bonus = $this->bonusCollector->collect($agent, $memberIds, $utcStart, $utcEnd, $businessStart, $businessEnd);

        $calc = $this->calculator->calculate(
            weightedTotal: $bet['weighted_total'],
            bonusBase: $bonus['base'],
            bonusRate: (string) $plan->bonus_rate,
            conditionRate: (string) $conditionResult['tier']->percentage,
            ggrRate: (string) $plan->ggr_rate,
            adminFeeRate: (string) $plan->admin_fee_rate,
            handlingFee: (string) $plan->handling_fee,
        );

        $final = SettlementCalculator::roundAmount($calc['final']);
        $carry = $this->carrySettler->settle(
            (int) $plan->carry_negative === 1,
            $agent->id,
            $periodStart,
            $final,
        );

        return [
            'agent_id' => $agent->id,
            'agent_username' => $agent->username,
            'settlement_cycle' => $cycle->value,
            'period_start' => $periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'ggr_amount' => SettlementCalculator::roundAmount($bet['weighted_total']),
            'bonus_rate' => (string) $plan->bonus_rate,
            'ggr_rate' => (string) $plan->ggr_rate,
            'condition_rate' => (string) $conditionResult['tier']->percentage,
            'admin_fee_rate' => (string) $plan->admin_fee_rate,
            'handling_fee' => SettlementCalculator::roundAmount($calc['handling_fee']),
            'final_amount' => $final,
            'payable_amount' => SettlementCalculator::roundAmount($carry['payable']),
            'carry_in_amount' => $carry['carry_in'],
            'carry_out_amount' => SettlementCalculator::roundAmount($carry['carry_out']),
            'calc_snapshot' => [
                'weighted_total' => $bet['weighted_total'],
                'vendors' => $bet['vendors'],
                'bonus' => [...$bonus, 'fee' => $calc['bonus_fee']],
                'decision_value' => $calc['decision'],
                'admin_fee' => SettlementCalculator::roundAmount($calc['admin_fee']),
                'condition' => [
                    'tier' => (int) $conditionResult['tier']->tier,
                    'percentage' => (string) $conditionResult['tier']->percentage,
                    'qualified_count' => $conditionResult['qualified_count'],
                    'member_total' => $conditionResult['member_total'],
                    'active_count' => $conditionResult['active_count'],
                ],
                'carry' => $carry,
                'eligibility' => [
                    'direct_total' => count($directMemberIds),
                    'kyc_excluded' => $eligibility['kyc_excluded'],
                ],
            ],
        ];
    }

    /** @param array<string, mixed> $row */
    private function upsertSettlement(GgrPlan $plan, array $row): void
    {
        $existing = GgrSettlement::query()
            ->where('plan_id', $plan->id)
            ->where('agent_id', $row['agent_id'])
            ->where('period_start', $row['period_start'])
            ->lockForUpdate()
            ->first();

        // 已發放的列不可被重跑洗掉（錢已入帳，快照必須保持發放當下狀態）
        if ($existing !== null && (int) $existing->payout_status === GgrPayoutStatusEnum::PAID->value) {
            Log::warning('GGR 結算重跑跳過已發放列', [
                'settlement_id' => $existing->id,
                'plan_id' => $plan->id,
                'agent_id' => $row['agent_id'],
                'period_start' => $row['period_start'],
            ]);

            return;
        }

        $columns = [
            'general_agent_id' => $plan->general_agent_id,
            'plan_id' => $plan->id,
            'agent_id' => $row['agent_id'],
            'settlement_cycle' => $row['settlement_cycle'],
            'period_start' => $row['period_start'],
            'period_end' => $row['period_end'],
            'ggr_amount' => $row['ggr_amount'],
            'bonus_rate' => $row['bonus_rate'],
            'ggr_rate' => $row['ggr_rate'],
            'condition_rate' => $row['condition_rate'],
            'admin_fee_rate' => $row['admin_fee_rate'],
            'handling_fee' => $row['handling_fee'],
            'final_amount' => $row['final_amount'],
            'payable_amount' => $row['payable_amount'],
            'carry_in_amount' => $row['carry_in_amount'],
            'carry_out_amount' => $row['carry_out_amount'],
            'calc_snapshot' => $row['calc_snapshot'],
            'payout_status' => GgrPayoutStatusEnum::UNPAID->value,
            'admin' => null,
        ];

        if ($existing !== null) {
            $existing->fill($columns)->save();

            return;
        }

        GgrSettlement::create($columns);
    }

    /**
     * 重跑時清掉本輪未產生結果的代理留下的 UNPAID 列（不再達階、移出群組、
     * 綁定停用），否則批次七會照舊列的 payable_amount 發放。PAID 列不動。
     *
     * @param  array<int, int>  $settledAgentIds  本輪有結果的代理
     */
    private function deleteStaleUnpaidRows(GgrPlan $plan, Carbon $periodStart, array $settledAgentIds): void
    {
        $deleted = GgrSettlement::query()
            ->where('plan_id', $plan->id)
            ->where('period_start', $periodStart->toDateString())
            ->where('payout_status', GgrPayoutStatusEnum::UNPAID->value)
            ->when($settledAgentIds !== [], fn ($q) => $q->whereNotIn('agent_id', $settledAgentIds))
            ->delete();

        if ($deleted > 0) {
            Log::info('GGR 結算重跑刪除不再達階代理的 UNPAID 列', [
                'plan_id' => $plan->id,
                'period_start' => $periodStart->toDateString(),
                'deleted' => $deleted,
            ]);
        }
    }

    /**
     * 推進 ggr_plans.settled_through（只前進不後退；重跑中間期不動）。
     * PeriodResolver 以此為游標——無代理達階、無結算列的期間也得前進。
     * 用 base query 更新，不碰 updated_at（那是設定端的修改時間）。
     */
    private function advanceSettledThrough(GgrPlan $plan, Carbon $periodEnd): void
    {
        $end = $periodEnd->toDateString();

        GgrPlan::query()
            ->whereKey($plan->id)
            ->where(fn ($q) => $q->whereNull('settled_through')->orWhere('settled_through', '<', $end))
            ->toBase()
            ->update(['settled_through' => $end]);

        if ($plan->settled_through === null || $plan->settled_through < $end) {
            $plan->settled_through = $end;
        }
    }

    /**
     * 解析方案綁定：預設一組＋特殊用戶 override（整組替換）。
     * 停用（status=0）的綁定回 null（該代理跳過）。
     *
     * @return array{default: array{condition: GgrCondition|null, vendor_setting: GgrVendorSetting|null},
     *               overrides: array<int, array{condition: GgrCondition|null, vendor_setting: GgrVendorSetting|null}>}
     */
    private function resolveBindings(GgrPlan $plan): array
    {
        $plan->loadMissing('specialAgents');

        $conditionIds = [(int) $plan->downline_condition_id];
        $vendorSettingIds = [(int) $plan->downline_vendor_setting_id];

        foreach ($plan->specialAgents as $special) {
            $conditionIds[] = (int) $special->condition_id;
            $vendorSettingIds[] = (int) $special->vendor_setting_id;
        }

        $conditions = GgrCondition::query()
            ->with('tiers')
            ->whereIn('id', array_unique($conditionIds))
            ->where('status', StatusEnum::ENABLE->value)
            ->get()
            ->keyBy('id');
        $vendorSettings = GgrVendorSetting::query()
            ->with('details')
            ->whereIn('id', array_unique($vendorSettingIds))
            ->where('status', StatusEnum::ENABLE->value)
            ->get()
            ->keyBy('id');

        $overrides = [];
        foreach ($plan->specialAgents as $special) {
            $overrides[(int) $special->agent_id] = [
                'condition' => $conditions->get((int) $special->condition_id),
                'vendor_setting' => $vendorSettings->get((int) $special->vendor_setting_id),
            ];
        }

        return [
            'default' => [
                'condition' => $conditions->get((int) $plan->downline_condition_id),
                'vendor_setting' => $vendorSettings->get((int) $plan->downline_vendor_setting_id),
            ],
            'overrides' => $overrides,
        ];
    }

    /**
     * GMT+8 業務日期間 → UTC 半開查詢窗 [start 00:00, end+1 00:00)。
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function utcWindow(Carbon $periodStart, Carbon $periodEnd): array
    {
        $timezone = config('custom.backend.timezone');

        return [
            Carbon::parse($periodStart->toDateString() . ' 00:00:00', $timezone)->setTimezone('UTC'),
            Carbon::parse($periodEnd->copy()->addDay()->toDateString() . ' 00:00:00', $timezone)->setTimezone('UTC'),
        ];
    }
}
