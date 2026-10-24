<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\GgrPlan;

use App\Http\Requests\AbstractRequest;

/**
 * 推廣代理 GGR 方案 新增 工單4121
 *
 * 成員下線設置＝兩顆綁定 FK（遊戲商設定＋條件設定各一）；
 * special_agents 每列＝一個被 override 的代理（整組改吃列上綁定）。
 * 綁定歸屬與代理線內檢查在 Service 複驗。
 *
 * 兩個次數欄有跨欄位上限：違規次數不得超過已開啟的唯一限制項數、驗證次數
 * 不得超過已開啟的領取前驗證項數（開幾項就最多要求幾項，超過必然無法達成）。
 */
class StoreRequest extends AbstractRequest
{
    /** 帳戶驗證限制的唯一限制開關（違規次數的上限來源） */
    private const KYC_UNIQUE_FIELDS = [
        'kyc_unique_bank_account',
        'kyc_unique_contact_no',
        'kyc_unique_email',
        'kyc_unique_ip',
        'kyc_unique_name',
    ];

    public function rules(): array
    {
        return [
            /**
             * @var string GGR標題
             *
             * @example "月結 GGR 方案"
             */
            'name' => 'required|string|max:100',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'required|in:0,1',
            /**
             * @var int 結算週期 1:每日 2:每週 3:每月
             *
             * @example 3
             */
            'settlement_cycle' => 'required|in:1,2,3',
            /**
             * @var int 代理群組 agent_groups.id（個性化設置組別）
             *
             * @example 195
             */
            'agent_group_id' => 'required|integer',
            /**
             * @var string 開始日期（不得早於今日業務日，GMT+8）
             *
             * @example "2026-09-01"
             */
            'start_date' => $this->startDateRules(),
            /**
             * @var int 发扬 1:負數GGR用原始數據結轉下期 0:否
             *
             * @example 0
             */
            'carry_negative' => 'required|in:0,1',
            /**
             * @var string 管理費%（非負、最多兩位小數、上限100）
             *
             * @example "5.00"
             */
            'admin_fee_rate' => 'required|numeric|min:0|max:100|decimal:0,2',
            /**
             * @var string 手續費（固定金額，非負、最多兩位小數）
             *
             * @example "10.00"
             */
            'handling_fee' => 'required|numeric|min:0|max:999999999999.99|decimal:0,2',
            /**
             * @var string GGR百分比%
             *
             * @example "60.00"
             */
            'ggr_rate' => 'required|numeric|min:0|max:100|decimal:0,2',
            /**
             * @var string 獎金百分比%
             *
             * @example "10.00"
             */
            'bonus_rate' => 'required|numeric|min:0|max:100|decimal:0,2',
            /**
             * @var int 銀行帳號唯一限制 1:開 0:關
             *
             * @example 0
             */
            'kyc_unique_bank_account' => 'required|in:0,1',
            /**
             * @var int 手機號碼唯一限制
             *
             * @example 0
             */
            'kyc_unique_contact_no' => 'required|in:0,1',
            /**
             * @var int 信箱唯一限制
             *
             * @example 0
             */
            'kyc_unique_email' => 'required|in:0,1',
            /**
             * @var int IP唯一限制
             *
             * @example 0
             */
            'kyc_unique_ip' => 'required|in:0,1',
            /**
             * @var int 姓名唯一限制
             *
             * @example 0
             */
            'kyc_unique_name' => 'required|in:0,1',
            /**
             * @var int 違規次數
             *
             * @example 0
             */
            'kyc_violate_count' => 'required|integer|min:0|max:1000000',
            /**
             * @var int 成員下線設置：遊戲商設定 ggr_vendor_settings.id
             *
             * @example 1
             */
            'downline_vendor_setting_id' => 'required|integer',
            /**
             * @var int 成員下線設置：條件設定 ggr_conditions.id
             *
             * @example 1
             */
            'downline_condition_id' => 'required|integer',
            /**
             * @var array 特殊用戶 override（可空）
             */
            'special_agents' => 'nullable|array',
            /**
             * @var int 被 override 的代理 agents.id（須在總代線內）
             *
             * @example 100213
             */
            'special_agents.*.agent_id' => 'required|integer|distinct',
            /**
             * @var int override 的遊戲商設定 id
             *
             * @example 1
             */
            'special_agents.*.vendor_setting_id' => 'required|integer',
            /**
             * @var int override 的條件設定 id
             *
             * @example 1
             */
            'special_agents.*.condition_id' => 'required|integer',
        ];
    }

    /**
     * 開始日期規則：不得早於今日業務日（GMT+8）。
     *
     * 回填過去日期會讓方案首輪一次補出 start_date 起算的所有歷史期間
     * （PeriodResolver 游標＝max(start_date, settled_through+1, 最新結算列+1)），
     * 每期都以真實歷史注單落庫，並由下一輪發放排程全數入帳；已發放的列結算
     * 重跑不覆蓋、transaction_log_agents 也無沖銷型別，錯誤金額無法經系統回收。
     * 補算歷史請走 Olympus 的結算補跑（帶截至日），不要靠 start_date 回填。
     *
     * @return array<int, string>
     */
    protected function startDateRules(): array
    {
        return [
            'required',
            'date_format:Y-m-d',
            'after_or_equal:' . now()->toBackendTime()->toDateString(),
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $this->assertCountWithinEnabled(
                $validator,
                'kyc_violate_count',
                self::KYC_UNIQUE_FIELDS
            );
        });
    }

    /**
     * 次數欄不得超過同區已開啟的開關數（0 代表不設限，永遠合法）。
     */
    private function assertCountWithinEnabled($validator, string $countField, array $switchFields): void
    {
        $count = $this->input($countField);
        if (!is_numeric($count) || (int) $count === 0) {
            return;
        }

        $enabled = 0;
        foreach ($switchFields as $field) {
            if ((int) $this->input($field) === 1) {
                $enabled++;
            }
        }

        if ((int) $count > $enabled) {
            $validator->errors()->add(
                $countField,
                __('validation.lte.numeric', ['attribute' => $countField, 'value' => $enabled])
            );
        }
    }
}
