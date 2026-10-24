<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\MemberGroup;

use App\Enums\WithdrawalFeeTierTypeEnum;
use App\Helpers\Common;
use App\Http\Requests\AbstractRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * @knowledge rules/member-group-fee-tiers
 */
class StoreRequest extends AbstractRequest
{
    /** 平台手續費階梯百分比上限（百分比數值，100＝100%） */
    private const int PERCENT_MAX = 100;

    /** 平台手續費階梯最多幾階 */
    private const int TIERS_MAX = 10;

    /** 平台手續費階梯固定金額上限（對齊 withdrawal_orders.tax decimal(11,5)） */
    private const string FIXED_MAX = '999999.99999';

    /**
     * 限額欄位一律 nullable|numeric|min:-1（-1=不限制）。
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /**
             * @var string 群組名稱
             *
             * @example "VIP 群組"
             */
            'name' => 'required|string|max:50',
            /**
             * @var int 擁有者代理編號（建立時必選；admin 可指定任一代理，其餘限本人或下線）
             *
             * @example 33893
             */
            'agent_id' => 'required|integer',
            /**
             * @var string 內部備註
             *
             * @example "高流水客群"
             */
            'remark' => 'nullable|string|max:191',
            /**
             * @var int 排序
             *
             * @example 0
             */
            'sequence' => 'nullable|integer',
            /**
             * @var int 狀態 1:啟用 0:停用
             *
             * @example 1
             */
            'status' => 'required|in:0,1',
            /**
             * @var int 設為預設群組
             *
             * @example 0
             */
            'default_setting' => 'required|in:0,1',
            /**
             * @var int 自助存款帳號白名單開關 0:不限制 1:僅白名單
             *
             * @example 0
             */
            'bank_whitelist_enabled' => 'nullable|in:0,1',
            'deposit_min' => 'nullable|numeric|min:-1',
            'deposit_max' => 'nullable|numeric|min:-1',
            'deposit_daily_count' => 'nullable|integer|min:-1',
            'deposit_daily_amount' => 'nullable|numeric|min:-1',
            'withdraw_min' => 'nullable|numeric|min:-1',
            'withdraw_max' => 'nullable|numeric|min:-1',
            'withdraw_daily_count' => 'nullable|integer|min:-1',
            'withdraw_daily_amount' => 'nullable|numeric|min:-1',
            /**
             * 未送或送 null／空陣列＝未啟用（沿用銀行手續費）。最多 TIERS_MAX（10）階，from 須嚴格遞增。
             *
             * @var array<int, array{from: int, type: int, value: numeric}>|null 平台手續費階梯
             *
             * @example [{"from": 4, "type": 1, "value": 3}, {"from": 11, "type": 0, "value": 50}]
             */
            'withdraw_fee_tiers' => 'nullable|array|max:' . self::TIERS_MAX,
            /**
             * @var int 當日第幾筆提款起適用（GMT+8，從 1 起算）
             *
             * @example 4
             */
            'withdraw_fee_tiers.*.from' => 'required|integer|min:1',
            /**
             * @var int 收費方式 0:固定金額 1:百分比
             *
             * @example 1
             */
            'withdraw_fee_tiers.*.type' => ['required', 'integer', Rule::enum(WithdrawalFeeTierTypeEnum::class)],
            /**
             * 固定金額填金額（上限 999999.99999）；百分比填百分比數值（3=3%、2.5=2.5%，非 0.03，上限 100）。最多 5 位小數。
             *
             * @var numeric 手續費值
             *
             * @example 3
             */
            'withdraw_fee_tiers.*.value' => 'required|numeric|decimal:0,5|min:0',
            'transfer_min' => 'nullable|numeric|min:-1',
            'transfer_max' => 'nullable|numeric|min:-1',
            'transfer_daily_count' => 'nullable|integer|min:-1',
            'transfer_daily_amount' => 'nullable|numeric|min:-1',
            /**
             * @var int[] 自助存款可用帳號 id 清單(bank_accounts.id)
             *
             * @example [26, 73]
             */
            'bank_account_ids' => 'nullable|array',
            'bank_account_ids.*' => 'integer',
        ];
    }

    /**
     * 平台手續費階梯的跨列規則：from 須嚴格遞增（相等或倒退皆錯）；value 依 type 設上限
     * （百分比 ≤ PERCENT_MAX、固定金額 ≤ FIXED_MAX）。
     * 單列格式錯誤交給 rules() 回報，這裡遇到非數值即跳過，避免重複報錯。
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $tiers = $validator->getData()['withdraw_fee_tiers'] ?? null;
            if (!is_array($tiers)) {
                return;
            }

            $previousFrom = null;
            foreach (array_values($tiers) as $index => $tier) {
                if (!is_array($tier)) {
                    continue;
                }

                $from = $tier['from'] ?? null;
                if (is_numeric($from)) {
                    if ($previousFrom !== null && (int) $from <= $previousFrom) {
                        $validator->errors()->add(
                            "withdraw_fee_tiers.{$index}.from",
                            __('validation.gt.numeric', ['attribute' => "withdraw_fee_tiers.{$index}.from", 'value' => $previousFrom]),
                        );
                    }
                    $previousFrom = (int) $from;
                }

                // value 格式已被 rules() 擋下（如科學記號）時不再比較：bcmath 讀不了那種字串
                $valueKey = "withdraw_fee_tiers.{$index}.value";
                $value = $tier['value'] ?? null;
                $type = is_numeric($tier['type'] ?? null) ? WithdrawalFeeTierTypeEnum::tryFrom((int) $tier['type']) : null;
                if ($type === null || !is_numeric($value) || $validator->errors()->has($valueKey)) {
                    continue;
                }

                $max = $type === WithdrawalFeeTierTypeEnum::PERCENT ? (string) self::PERCENT_MAX : self::FIXED_MAX;
                if (Common::numCompare((string) $value, $max, 5) > 0) {
                    $validator->errors()->add(
                        $valueKey,
                        __('validation.max.numeric', ['attribute' => $valueKey, 'max' => $max]),
                    );
                }
            }
        });
    }
}
