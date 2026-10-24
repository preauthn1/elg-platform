<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Helpers\Common;
use App\Models\Agent;
use App\Models\GgrCondition;
use App\Models\GgrConditionTier;
use App\Models\MemberHourlyStatistic;
use App\Models\TotalBetRecord;
use App\Services\Agent\ActiveMemberCriteria;
use Illuminate\Support\Carbon;

/**
 * 4124 條件判定：算出代理本期命中的最高達成階（條件設置應得百分比%的來源）。
 *
 * 會員範圍＝直屬下線（呼叫端傳入）；四項金額門檻是「單一會員各自達成」
 * （依 logic_type 全 OR／全 AND，0＝該項無要求），用戶人數＝合格會員數的
 * 必要條件（0＝不限人數，但仍須至少 1 位合格——旗下 0 位會員不達任何階，
 * 邊界 8-7；人數與四項金額全 0 時有會員即全員合格＝無條件達成，8-15）。
 * 取已達成的最高一階；一階都沒達成回 null（呼叫端不產生結算紀錄，邊界 8-1）。
 *
 * 資料源（期間窗＝結算期間，UTC 換算後查詢）：
 * - 存款＝member_hourly_statistics.deposit_amount（member × UTC 整點）
 * - 總投注＝Σ total_bet_records.bet／有效流水＝Σ valid_bet／
 *   遊戲總收入＝−Σ win_lose（**加權前**原始 GGR，2026-09-01 定案不套 4123 加權）
 * - 活躍（active_user_only=1）＝設定鍵 7006（充值）＋7007（有效流水）皆達成
 *   （0=不限制），先濾活躍再算合格數
 */
class ConditionEvaluator
{
    /**
     * @param  array<int, int>  $memberIds  直屬下線會員 id
     * @return array{tier: GgrConditionTier|null, qualified_count: int, member_total: int, active_count: int|null}
     */
    public function evaluate(
        GgrCondition $condition,
        Agent $agent,
        array $memberIds,
        Carbon $utcStart,
        Carbon $utcEnd,
    ): array {
        $stats = $this->memberStats($memberIds, $utcStart, $utcEnd);

        $activeCount = null;
        if ((int) $condition->active_user_only === 1) {
            $stats = $this->filterActive($agent, $stats);
            $activeCount = count($stats);
        }

        $matched = null;
        $matchedCount = 0;

        // tiers 依 percentage 遞增（建立時已驗證），由高往低找第一個達成階
        /** @var GgrConditionTier $tier */
        foreach ($condition->tiers->sortByDesc('tier') as $tier) {
            $qualified = $this->countQualified((int) $condition->logic_type, $tier, $stats);

            // user_count 0＝不限人數，不是「免看金額門檻」：仍要至少 1 位會員各自達標
            if ($qualified >= max(1, (int) $tier->user_count)) {
                $matched = $tier;
                $matchedCount = $qualified;

                break;
            }
        }

        return [
            'tier' => $matched,
            'qualified_count' => $matchedCount,
            'member_total' => count($memberIds),
            'active_count' => $activeCount,
        ];
    }

    /**
     * 期間內各會員的四項金額聚合。
     *
     * @param  array<int, int>  $memberIds
     * @return array<int, array{deposit: string, total_bet: string, valid_bet: string, game_revenue: string}>
     */
    private function memberStats(array $memberIds, Carbon $utcStart, Carbon $utcEnd): array
    {
        if ($memberIds === []) {
            return [];
        }

        $stats = [];

        MemberHourlyStatistic::query()
            ->whereIn('member_id', $memberIds)
            ->where('summary_hour', '>=', $utcStart)
            ->where('summary_hour', '<', $utcEnd)
            ->groupBy('member_id')
            ->selectRaw('member_id, IFNULL(SUM(deposit_amount), 0) as deposit')
            ->get()
            ->each(function ($row) use (&$stats): void {
                $stats[(int) $row->member_id]['deposit'] = (string) $row->deposit;
            });

        TotalBetRecord::query()
            ->whereIn('member_id', $memberIds)
            ->where('payout_time', '>=', $utcStart)
            ->where('payout_time', '<', $utcEnd)
            ->groupBy('member_id')
            ->selectRaw('member_id, IFNULL(SUM(bet), 0) as total_bet, IFNULL(SUM(valid_bet), 0) as valid_bet, IFNULL(-SUM(win_lose), 0) as game_revenue')
            ->get()
            ->each(function ($row) use (&$stats): void {
                $stats[(int) $row->member_id]['total_bet'] = (string) $row->total_bet;
                $stats[(int) $row->member_id]['valid_bet'] = (string) $row->valid_bet;
                $stats[(int) $row->member_id]['game_revenue'] = (string) $row->game_revenue;
            });

        // 期間內完全無活動的會員也可能因「全 0 門檻＝無條件達成」被計入，補零列
        foreach ($memberIds as $memberId) {
            $stats[$memberId] = ($stats[$memberId] ?? []) + [
                'deposit' => '0',
                'total_bet' => '0',
                'valid_bet' => '0',
                'game_revenue' => '0',
            ];
        }

        return $stats;
    }

    /**
     * 活躍過濾：設定鍵 7006（充值）＋7007（有效流水）兩者皆達成才算活躍
     * （0＝該項不限制；兩者皆 0＝全員活躍）。門檻比較共用 `ActiveMemberCriteria`（SR28 也用）。
     *
     * @param  array<int, array{deposit: string, total_bet: string, valid_bet: string, game_revenue: string}>  $stats
     * @return array<int, array{deposit: string, total_bet: string, valid_bet: string, game_revenue: string}>
     */
    private function filterActive(Agent $agent, array $stats): array
    {
        $criteria = ActiveMemberCriteria::forAgent($agent);

        return array_filter($stats, fn (array $row): bool => $criteria->passes($row['deposit'], $row['valid_bet']));
    }

    /**
     * 該階的合格會員數：四項門檻（0＝無要求）依 logic_type 全 OR／全 AND 判定。
     * OR＝有設定的門檻任一達成（全未設定＝無條件達成）；AND＝有設定的全部達成。
     *
     * @param  array<int, array{deposit: string, total_bet: string, valid_bet: string, game_revenue: string}>  $stats
     */
    private function countQualified(int $logicType, GgrConditionTier $tier, array $stats): int
    {
        $thresholds = array_filter([
            'deposit' => (string) $tier->deposit_amount,
            'total_bet' => (string) $tier->total_bet_amount,
            'game_revenue' => (string) $tier->game_revenue_amount,
            'valid_bet' => (string) $tier->valid_bet_amount,
        ], fn (string $value) => Common::numCompare($value, '0') > 0);

        $count = 0;

        foreach ($stats as $row) {
            if ($this->memberQualified($logicType, $thresholds, $row)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, string>  $thresholds
     * @param  array{deposit: string, total_bet: string, valid_bet: string, game_revenue: string}  $row
     */
    private function memberQualified(int $logicType, array $thresholds, array $row): bool
    {
        if ($thresholds === []) {
            return true;
        }

        foreach ($thresholds as $key => $threshold) {
            $reached = Common::numCompare($row[$key], $threshold) >= 0;

            if ($logicType === GgrCondition::LOGIC_TYPE_OR && $reached) {
                return true;
            }

            if ($logicType === GgrCondition::LOGIC_TYPE_AND && !$reached) {
                return false;
            }
        }

        return $logicType === GgrCondition::LOGIC_TYPE_AND;
    }
}
