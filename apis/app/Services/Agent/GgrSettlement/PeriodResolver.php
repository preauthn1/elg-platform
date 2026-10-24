<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Enums\Agent\GgrSettlementCycleEnum;
use App\Models\GgrPlan;
use App\Models\GgrSettlement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 推導 GGR 方案「已完整結束且未結算」的期間清單（catch-up 冪等）。
 *
 * 游標＝ggr_plans.settled_through 次日（引擎每期完成時與完成標記同交易推進，
 * 該期無代理達階、無任何結算列時也會前進；無值則 start_date），另以最新結算列
 * 的 period_end 兜底。逐期往前推到「期末仍早於今天業務日」為止——今天所在的
 * 期間永不結算，引擎因此不需要關帳截斷邏輯。
 *
 * 週期切換（pending_settlement_cycle＋pending_cycle_from，2026-09-01 定案
 * 「只對下一期生效」）：游標推進到生效日時改用新週期繼續推導；生效日前的
 * 期間照原週期產生。promote 落庫（promoteIfDue）由呼叫端在該方案本輪
 * 期間全數結算完成後執行，確保「當期照原週期跑完」。
 */
class PeriodResolver
{
    /** 單次推導期間數上限（日結長期停跑的 catch-up 保險絲，超過留待下輪） */
    private const int MAX_PERIODS = 400;

    /**
     * @return array<int, array{start: Carbon, end: Carbon, cycle: GgrSettlementCycleEnum}>
     *                                                                                      期間清單（舊 → 新；start/end 皆為 GMT+8 業務日、含當日）
     */
    public function pendingPeriods(GgrPlan $plan, Carbon $todayBusinessDate): array
    {
        $cursor = $this->cursor($plan);
        $today = $todayBusinessDate->copy()->startOfDay();
        $cycle = GgrSettlementCycleEnum::from((int) $plan->settlement_cycle);
        $pendingFrom = $this->pendingFrom($plan);
        $periods = [];

        while (count($periods) < self::MAX_PERIODS) {
            if ($pendingFrom !== null && $cursor->gte($pendingFrom)) {
                $cycle = GgrSettlementCycleEnum::from((int) $plan->pending_settlement_cycle);
                $pendingFrom = null;
            }

            $end = $cycle->periodEndFor($cycle->periodStartContaining($cursor));

            // 防禦：生效日理應落在原週期期界上，若落在期中（人為改庫）截到生效日前一天
            if ($pendingFrom !== null && $end->gte($pendingFrom)) {
                Log::warning('GGR PeriodResolver: pending_cycle_from 落在原週期期中，期末截斷', [
                    'plan_id' => $plan->id,
                    'cursor' => $cursor->toDateString(),
                    'pending_cycle_from' => $pendingFrom->toDateString(),
                ]);
                $end = $pendingFrom->copy()->subDay();
            }

            // 期末須早於今天（今天所在的期間尚未完整結束）
            if ($end->gte($today)) {
                break;
            }

            $periods[] = ['start' => $cursor->copy(), 'end' => $end, 'cycle' => $cycle];
            $cursor = $end->copy()->addDay();
        }

        return $periods;
    }

    /**
     * 游標已達新週期生效日時把 pending 落地為現行週期（冪等）。
     *
     * 呼叫端須在該方案本輪 pendingPeriods 全數結算完成後執行。
     */
    public function promoteIfDue(GgrPlan $plan, Carbon $todayBusinessDate): void
    {
        $pendingFrom = $this->pendingFrom($plan);
        if ($plan->pending_settlement_cycle === null || $pendingFrom === null) {
            return;
        }

        // 生效日前的期間必須全數結清才能換週期（「當期照原週期跑完」）
        if ($this->cursor($plan)->lt($pendingFrom)) {
            return;
        }

        // 生效日尚未到（未來期界）時不提前落地，等生效日起第一輪再換
        if ($todayBusinessDate->copy()->startOfDay()->lt($pendingFrom)) {
            return;
        }

        $plan->forceFill([
            'settlement_cycle' => $plan->pending_settlement_cycle,
            'pending_settlement_cycle' => null,
            'pending_cycle_from' => null,
        ])->save();

        Log::info('GGR PeriodResolver: 結算週期已切換生效', [
            'plan_id' => $plan->id,
            'settlement_cycle' => (int) $plan->settlement_cycle,
        ]);
    }

    /**
     * 下一個待結算期間的起點（GMT+8 業務日）。
     *
     * 三者取最晚：start_date、settled_through 次日（每次讀 DB 現值——同一顆 job
     * 內 runPlanPeriod 已推進、記憶體中的 $plan 可能落後）、最新結算列 period_end
     * 次日（settled_through 欄上線前已結算的期間兜底）。
     */
    private function cursor(GgrPlan $plan): Carbon
    {
        $cursor = Carbon::parse($plan->start_date)->startOfDay();

        $settledThrough = GgrPlan::query()->whereKey($plan->id)->value('settled_through');
        if ($settledThrough !== null) {
            $cursor = $cursor->max(Carbon::parse($settledThrough)->addDay()->startOfDay());
        }

        $lastEnd = GgrSettlement::query()
            ->where('plan_id', $plan->id)
            ->orderByDesc('period_start')
            ->value('period_end');
        if ($lastEnd !== null) {
            $cursor = $cursor->max(Carbon::parse($lastEnd)->addDay()->startOfDay());
        }

        return $cursor;
    }

    private function pendingFrom(GgrPlan $plan): ?Carbon
    {
        return $plan->pending_cycle_from !== null
            ? Carbon::parse($plan->pending_cycle_from)->startOfDay()
            : null;
    }
}
