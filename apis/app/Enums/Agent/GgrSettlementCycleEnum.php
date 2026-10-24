<?php

declare(strict_types=1);

namespace App\Enums\Agent;

use Illuminate\Support\Carbon;

/**
 * GGR 方案結算週期（ggr_plans.settlement_cycle / ggr_settlements.settlement_cycle）。
 *
 * 期間定義（2026-09-01 工單 4121 定案，GMT+8 業務日）：
 * 每日＝單一業務日；每週＝週一至週日；每月＝日曆月。
 * plan.start_date 之前不產生期間；週期切換只對下一期生效
 * （pending_settlement_cycle，引擎結算完當期後 promote）。
 */
enum GgrSettlementCycleEnum: int
{
    case DAILY = 1;

    case WEEKLY = 2;

    case MONTHLY = 3;

    /**
     * 含指定業務日的期間起點。
     */
    public function periodStartContaining(Carbon $businessDate): Carbon
    {
        return match ($this) {
            self::DAILY => $businessDate->copy()->startOfDay(),
            self::WEEKLY => $businessDate->copy()->startOfWeek(),
            self::MONTHLY => $businessDate->copy()->startOfMonth(),
        };
    }

    /**
     * 指定期間起點對應的期間迄日（含當日）。
     */
    public function periodEndFor(Carbon $periodStart): Carbon
    {
        return match ($this) {
            self::DAILY => $periodStart->copy()->startOfDay(),
            self::WEEKLY => $periodStart->copy()->endOfWeek()->startOfDay(),
            self::MONTHLY => $periodStart->copy()->endOfMonth()->startOfDay(),
        };
    }
}
