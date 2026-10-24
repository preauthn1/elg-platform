<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrCondition;

use App\Helpers\Common;
use App\Http\Requests\AbstractRequest;

/**
 * 推廣代理 GGR 條件設置 新增 工單4124
 *
 * tiers 每列＝一個階梯：四項金額門檻是**單一會員**各自要達的值（0=該項無要求），
 * user_count 合格會員數 ≥ 此值才命中（0=不限制人數，2026-09-01 工單定案）。
 * 遞增約束只綁 percentage（工單需求1：排序約束綁應得百分比，不綁門檻；
 * 結算取最高命中階的前提）。標題同總代內不可重複，檢查在 Service 層。
 *
 * 各數值皆有上限：欄位是 decimal/int，只靠 numeric 會讓過大的值一路撞到
 * SQL out-of-range（500）而不是回 422，上限與 migration 的欄位型別對齊。
 */
class StoreRequest extends AbstractRequest
{
    /** 金額門檻上限，對齊 decimal(18,2) 並留足餘裕 */
    private const string MAX_AMOUNT = '999999999999.99';

    /** 用戶人數上限，對齊 user_count 的 signed int */
    private const int MAX_USER_COUNT = 2147483647;

    public function rules(): array
    {
        return [
            /**
             * @var string GGR 條件標題
             *
             * @example "60% Winloss Comm"
             */
            'name' => 'required|string|max:100',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'required|in:0,1',
            /**
             * @var int 類型 1:或者(OR) 2:和(AND)，四項門檻全 OR 或全 AND 不混用
             *
             * @example 1
             */
            'logic_type' => 'required|in:1,2',
            /**
             * @var int 僅計算活躍用戶 1:是 0:否
             *
             * @example 0
             */
            'active_user_only' => 'required|in:0,1',
            /**
             * @var array 階梯列（至少一階，依列序＝階序）
             */
            'tiers' => 'required|array|min:1',
            /**
             * @var int 用戶人數（合格會員數門檻；0=不限制人數，不要求逐階遞增）
             *
             * @example 10
             */
            'tiers.*.user_count' => 'required|integer|min:0|max:' . self::MAX_USER_COUNT,
            /**
             * @var string 存款門檻（單一會員各自須達；0=無要求，非負、最多兩位小數）
             *
             * @example "100.00"
             */
            'tiers.*.deposit_amount' => 'required|numeric|min:0|max:' . self::MAX_AMOUNT . '|decimal:0,2',
            /**
             * @var string 總投注金額門檻（單一會員；0=無要求）
             *
             * @example "0"
             */
            'tiers.*.total_bet_amount' => 'required|numeric|min:0|max:' . self::MAX_AMOUNT . '|decimal:0,2',
            /**
             * @var string 遊戲總收入門檻（單一會員；0=無要求）
             *
             * @example "0"
             */
            'tiers.*.game_revenue_amount' => 'required|numeric|min:0|max:' . self::MAX_AMOUNT . '|decimal:0,2',
            /**
             * @var string 有效流水門檻（單一會員；0=無要求）
             *
             * @example "0"
             */
            'tiers.*.valid_bet_amount' => 'required|numeric|min:0|max:' . self::MAX_AMOUNT . '|decimal:0,2',
            /**
             * @var string 應得百分比%（命中此階代理拿的%，逐階遞增）
             *
             * @example "5.00"
             */
            'tiers.*.percentage' => 'required|numeric|min:0|max:100|decimal:0,2',
        ];
    }

    /**
     * 階梯結構驗證：percentage 須依列序嚴格遞增（結算取最高命中階的前提）。
     * user_count 不設遞增約束——工單需求1明定排序約束綁應得百分比、不綁門檻，
     * 且 0=不限制人數，後階人數可小於前階。
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $tiers = $this->input('tiers');
            if (!is_array($tiers)) {
                return;
            }

            $prevPercentage = null;
            foreach ($tiers as $i => $tier) {
                $percentage = $tier['percentage'] ?? null;
                // 非合法字面值（含 is_numeric 放行的科學記號）由 decimal:0,2 規則報錯；
                // 百分比走字串精確比較，不用 float
                if ($this->isDecimalLiteral($percentage)) {
                    if ($prevPercentage !== null && Common::numCompare($percentage, $prevPercentage, 2) <= 0) {
                        $validator->errors()->add("tiers.{$i}.percentage", __('validation.gt.numeric', ['attribute' => "tiers.{$i}.percentage", 'value' => $prevPercentage]));
                    }
                    $prevPercentage = $percentage;
                }
            }
        });
    }
}
