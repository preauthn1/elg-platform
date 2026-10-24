<?php

declare(strict_types=1);

namespace App\Services\Agent\GgrSettlement;

use App\Helpers\Common;

/**
 * 公式④–⑨（單一真相來源工單 4121，2026-09-01 定案）：
 *
 *   判斷值 = 加權總和 − 獎金費用（獎金費用 = 獎金基數 × 獎金%）
 *   X      = 判斷值 × 條件設置應得% × 代理設置GGR%
 *   判斷值 >  0 → 代理實得 = X × (1 − 管理費%) − 手續費
 *   判斷值 <= 0 → 代理實得 = X（管理費與手續費都不扣、欄位顯示 0）
 *
 * 判斷條件必須是嚴格 `> 0`（剛好 0 比照負數，工單案例 D：誤當正數會讓
 * 代理從不賺不賠變成倒欠手續費）。管理費抽在兩個百分比都乘完之後
 * （X × 管理費%，不是判斷值 × 管理費%）。
 */
class SettlementCalculator
{
    /**
     * @return array{decision: string, bonus_fee: string, condition_rate: string,
     *               ggr_rate: string, admin_fee_rate: string, admin_fee: string,
     *               handling_fee: string, final: string}
     */
    public function calculate(
        string $weightedTotal,
        string $bonusBase,
        string $bonusRate,
        string $conditionRate,
        string $ggrRate,
        string $adminFeeRate,
        string $handlingFee,
    ): array {
        $bonusFee = Common::numMul($bonusBase, Common::numDiv($bonusRate, '100'));
        $decision = Common::numSub($weightedTotal, $bonusFee);

        $x = Common::numMul(
            Common::numMul($decision, Common::numDiv($conditionRate, '100')),
            Common::numDiv($ggrRate, '100'),
        );

        if (Common::numCompare($decision, '0') > 0) {
            $adminFee = Common::numMul($x, Common::numDiv($adminFeeRate, '100'));
            $final = Common::numSub(Common::numSub($x, $adminFee), $handlingFee);
        } else {
            // 判斷值 <= 0（含剛好 0）：管理費與手續費都不扣，欄位顯示 0 而非負值
            $adminFee = '0';
            $handlingFee = '0';
            $final = $x;
        }

        return [
            'decision' => $decision,
            'bonus_fee' => $bonusFee,
            'condition_rate' => $conditionRate,
            'ggr_rate' => $ggrRate,
            'admin_fee_rate' => $adminFeeRate,
            'admin_fee' => $adminFee,
            'handling_fee' => $handlingFee,
            'final' => $final,
        ];
    }

    /**
     * 落庫金額對齊 DECIMAL(18,2)：round half-up 到 2 位
     * （對齊 DB 儲存精度的 SBR 手法，避免 bcmath truncate 與 DB 捨入不一致）。
     */
    public static function roundAmount(string $value): string
    {
        return Common::numRound($value, 2);
    }
}
