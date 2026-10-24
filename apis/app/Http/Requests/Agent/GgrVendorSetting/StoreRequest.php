<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrVendorSetting;

use App\Helpers\Common;
use App\Http\Requests\AbstractRequest;

/**
 * 推廣代理 GGR 遊戲商設置 新增 工單4123
 *
 * details 每項＝一個 vendor_info × game_type 組合，內含 tiers 階梯（至少一階）：
 * 以 threshold_amount（計算金額>=）為階梯鍵、同組合內逐階嚴格遞增（蘊含
 * 工單要求的「同遊戲商內不可重複」）。
 *
 * `0` 是特殊值＝不限制（2026-08-31 工單定案）：threshold_amount 0＝全收、
 * min_winlose 0＝無最低限制、max_ggr 0＝無上限、weight_rate 0＝該階不計算
 * （新增遊戲商自動補列的預設，避免順手儲存誤全開）。
 *
 * 各數值皆有上限：欄位是 decimal/int，只靠 numeric 會讓過大的值一路撞到
 * SQL out-of-range（500）而不是回 422，上限與 migration 的欄位型別對齊。
 */
class StoreRequest extends AbstractRequest
{
    /** 金額類上限，對齊 threshold_amount decimal(18,2) 並留足餘裕 */
    private const string MAX_AMOUNT = '999999999999.99';

    /** 加權%上限，對齊 weight_rate decimal(8,2) */
    private const string MAX_RATE = '999999.99';

    /** 最低輸贏／最大GGR 上限，對齊 unsignedBigInteger 並避開 JS 精度邊界 */
    private const int MAX_INT_AMOUNT = 999999999999;

    public function rules(): array
    {
        return [
            /**
             * @var string 設置名稱
             *
             * @example "主線遊戲商設定"
             */
            'name' => 'required|string|max:100',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'required|in:0,1',
            /**
             * @var array 明細（vendor_info × game_type 組合，至少一項）
             */
            'details' => 'required|array|min:1',
            /**
             * @var int 遊戲商編號 vendors.id
             *
             * @example 61
             */
            'details.*.vendor_id' => 'required|integer',
            /**
             * @var int 接入實體編號 vendor_infos.id（限總代支援範圍）
             *
             * @example 83
             */
            'details.*.vendor_info_id' => 'required|integer',
            /**
             * @var int 遊戲類型編號 game_types.id
             *
             * @example 1
             */
            'details.*.game_type_id' => 'required|integer',
            /**
             * @var array<int> 排除遊戲編號清單（同組合各階共用）
             *
             * @example [101, 102]
             */
            'details.*.excluded_game_ids' => 'nullable|array',
            'details.*.excluded_game_ids.*' => 'integer',
            /**
             * @var int 該組合開關 1:啟用 0:不列入結算
             *
             * @example 1
             */
            'details.*.status' => 'required|in:0,1',
            /**
             * @var array 階梯（至少一階，依列序＝階序）
             */
            'details.*.tiers' => 'required|array|min:1',
            /**
             * @var string 計算金額>=（階梯鍵，非負、最多兩位小數，同組合內逐階遞增；0=不限制）
             *
             * @example "200.00"
             */
            'details.*.tiers.*.threshold_amount' => 'required|numeric|min:0|max:' . self::MAX_AMOUNT . '|decimal:0,2',
            /**
             * @var int 最低遊戲輸贏（加權後門檻；0=無最低限制）
             *
             * @example 1
             */
            'details.*.tiers.*.min_winlose' => 'required|integer|min:0|max:' . self::MAX_INT_AMOUNT,
            /**
             * @var int 最大GGR（可計算加權上限；0=無上限）
             *
             * @example 55
             */
            'details.*.tiers.*.max_ggr' => 'required|integer|min:0|max:' . self::MAX_INT_AMOUNT,
            /**
             * @var string 加權%（非負、最多兩位小數；0=該階不計算）
             *
             * @example "100.00"
             */
            'details.*.tiers.*.weight_rate' => 'required|numeric|min:0|max:' . self::MAX_RATE . '|decimal:0,2',
        ];
    }

    /**
     * 陣列層級的結構驗證：組合唯一（DB unique 只保底）＋階梯鍵逐階嚴格遞增（結算取最高命中階的前提）。
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $details = $this->input('details');
            if (!is_array($details)) {
                return;
            }

            $seen = [];
            foreach ($details as $i => $detail) {
                $key = ($detail['vendor_info_id'] ?? '') . '-' . ($detail['game_type_id'] ?? '');
                if (isset($seen[$key])) {
                    $validator->errors()->add("details.{$i}.vendor_info_id", __('validation.distinct', ['attribute' => "details.{$i}.vendor_info_id"]));

                    continue;
                }
                $seen[$key] = true;

                $prev = null;
                foreach ((array) ($detail['tiers'] ?? []) as $j => $tier) {
                    $threshold = $tier['threshold_amount'] ?? null;
                    // 非合法字面值（含 is_numeric 放行的科學記號）由 decimal:0,2 規則報錯，
                    // 這裡跳過遞增比較，順帶避免把 bcmath 解析不了的值餵進去
                    if (!$this->isDecimalLiteral($threshold)) {
                        continue;
                    }
                    // 金額走字串精確比較，不用 float；相等時明示「重複」——
                    // 工單需求1預期訊息是提示重複，不是籠統的必須大於
                    if ($prev !== null) {
                        $compare = Common::numCompare($threshold, $prev, 2);
                        if ($compare === 0) {
                            $validator->errors()->add("details.{$i}.tiers.{$j}.threshold_amount", __('validation.distinct', ['attribute' => "details.{$i}.tiers.{$j}.threshold_amount"]));
                        } elseif ($compare < 0) {
                            $validator->errors()->add("details.{$i}.tiers.{$j}.threshold_amount", __('validation.gt.numeric', ['attribute' => "details.{$i}.tiers.{$j}.threshold_amount", 'value' => $prev]));
                        }
                    }
                    $prev = $threshold;
                }
            }
        });
    }
}
