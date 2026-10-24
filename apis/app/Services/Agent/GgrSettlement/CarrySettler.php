<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Helpers\Common;
use App\Models\GgrSettlement;
use Illuminate\Support\Carbon;

/**
 * 發揚（負數結轉）沖抵（公式⑩，2026-09-01 工單 4121 定案）。
 *
 * 遞延餘額跟著「代理」走（跨方案：換綁條件設置／代理群組不歸零）——
 * carry_in 取該代理在本期之前最新一筆結算的 carry_out_amount，不限定方案。
 * 無上限無期限；代理停用呆帳續留（停用代理不產生新結算，餘額自然凍結）。
 *
 * 沖抵順序：payable = final + carry_in（carry_in <= 0）。
 * - 發揚開且 payable < 0：實發 0、carry_out = payable（繼續累積）
 * - payable >= 0：實發 payable、carry_out = 0（沖抵完剩下才發放）
 * - 發揚關：期末負數歸 0（不產生新 carry_out）；既有殘留餘額仍先沖抵
 */
class CarrySettler
{
    /**
     * @return array{carry_in: string, payable: string, carry_out: string}
     */
    public function settle(bool $carryNegative, int $agentId, Carbon $periodStart, string $finalAmount): array
    {
        $carryIn = $this->latestCarryOut($agentId, $periodStart);
        $raw = Common::numAdd($finalAmount, $carryIn);

        if (Common::numCompare($raw, '0') >= 0) {
            return ['carry_in' => $carryIn, 'payable' => $raw, 'carry_out' => '0'];
        }

        return [
            'carry_in' => $carryIn,
            'payable' => '0',
            'carry_out' => $carryNegative ? $raw : '0',
        ];
    }

    /** 該代理在本期之前最新一筆結算的結轉帶出（＝當前遞延餘額；跨方案） */
    private function latestCarryOut(int $agentId, Carbon $periodStart): string
    {
        $value = GgrSettlement::query()
            ->where('agent_id', $agentId)
            ->where('period_start', '<', $periodStart->toDateString())
            ->orderByDesc('period_start')
            ->orderByDesc('id')
            ->value('carry_out_amount');

        return $value !== null ? (string) $value : '0';
    }
}
