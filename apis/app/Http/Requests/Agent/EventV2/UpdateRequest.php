<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\EventV2;

use App\Enums\EventV2\EventV2PeriodEnum;
use App\Enums\EventV2\EventV2WithdrawTypeEnum;
use App\Http\Requests\AbstractRequest;
use App\Http\Requests\Agent\EventV2\Concerns\ValidatesAngpow;
use App\Http\Requests\Agent\EventV2\Concerns\ValidatesEventTargets;
use App\Http\Requests\Agent\EventV2\Concerns\ValidatesWithdrawType;
use App\Models\EventV2Detail;
use Illuminate\Validation\Validator;

class UpdateRequest extends AbstractRequest
{
    use ValidatesAngpow;
    use ValidatesEventTargets;
    use ValidatesWithdrawType {
        withValidator as private withValidatorWithdrawType;
    }

    /** 現有活動（驗證期間同一列會被問多次，只查一次） */
    private ?EventV2Detail $existingDetail = null;

    private bool $existingDetailLoaded = false;

    /** 更新沒送 withdraw_type（舊後台）沿用資料庫現值，不能把積分活動翻回主錢包 */
    protected function fallbackWithdrawType(): EventV2WithdrawTypeEnum
    {
        // Model 已 Enum cast，讀出來就是 EventV2WithdrawTypeEnum；找不到活動時交給 Controller 回「活動不存在」
        return $this->existingDetail()?->withdraw_type ?? EventV2WithdrawTypeEnum::MAIN_WALLET;
    }

    /** 領取去向建立後鎖定：Update 以資料庫現值為準，送不同值會被驗證擋下 */
    protected function lockedWithdrawType(): ?EventV2WithdrawTypeEnum
    {
        return $this->existingDetail()?->withdraw_type;
    }

    /** 更新沒送 member_period／total_period（舊後台）沿用資料庫現值 */
    protected function fallbackPeriod(string $field): EventV2PeriodEnum
    {
        return $this->existingDetail()?->{$field} ?? EventV2PeriodEnum::NONE;
    }

    public function withValidator(Validator $validator): void
    {
        $this->withValidatorWithdrawType($validator);
        $validator->after(function (Validator $v) {
            $this->rejectChangedPeriods($v);
            $this->validateAngpow($v);
            $this->validateTargets($v);
        });
    }

    /**
     * 兩個週期建立後鎖定（Max 2026-10-01，比照 withdraw_type）：週期決定個人與全站上限的累計區間、以及不勾參加即領獎
     * 的作廢邊界，中途改會讓已累計的次數金額和已抽的紀錄對不上。送了且與資料庫現值不同即擋；沒送、null、空字串＝沿用現值。
     */
    private function rejectChangedPeriods(Validator $validator): void
    {
        foreach (EventV2PeriodEnum::FIELDS as $field) {
            $locked = $this->existingDetail()?->{$field};
            $raw = $this->input($field);
            if ($locked instanceof EventV2PeriodEnum && $raw !== null && $raw !== '' && (int) $raw !== $locked->value) {
                $validator->errors()->add($field, __('event_v2.validation.period_locked'));
            }
        }
    }

    private function existingDetail(): ?EventV2Detail
    {
        if (!$this->existingDetailLoaded) {
            $this->existingDetail = EventV2Detail::query()->find($this->input('event_detail_id'));
            $this->existingDetailLoaded = true;
        }

        return $this->existingDetail;
    }

    public function rules(): array
    {
        return [
            // 目標用戶：沒送不動原值（舊後台）；送了就整組重寫，0＝清成不限制；見 ValidatesEventTargets
            ...$this->targetRules('sometimes'),
            /**
             * @var int 活動詳細ID
             *
             * @example 1
             **/
            'event_detail_id' => 'integer',
            /**
             * @var int 活動ID
             *
             * @example 1
             **/
            'event_v2_id' => 'required|integer',
            /**
             * @var int 代理ID
             *
             * @example 4249
             **/
            'agent_id' => 'required|integer',
            /**
             * @var string|null 參加條件公式
             *
             * @example "deposit == 30"
             **/
            'condition_formula' => 'nullable|string',
            /**
             * @var string|null 參加條件客製化設定
             *
             * @example "{\"deposit\":30}"
             **/
            'condition_config' => 'nullable|array',
            /**
             * @var string|null 活動獎勵公式
             *
             * @example "10"
             **/
            'reward_formula' => 'nullable|string',
            /**
             * @var string|null 活動獎勵客製化設定
             *
             * @example null
             **/
            'reward_config' => 'nullable|array',
            /**
             * @var string|null 餘額限制公式
             *
             * @example "(deposit + reward) * 4"
             **/
            'balance_formula' => 'nullable|string',
            /**
             * @var string|null 餘額限制客製化設定
             *
             * @example null
             **/
            'balance_config' => 'nullable|array',
            /**
             * @var string|null 流水限制公式
             *
             * @example null
             **/
            'flow_formula' => 'nullable|string',
            /**
             * @var string|null 流水限制客製化設定
             *
             * @example null
             **/
            'flow_config' => 'nullable|array',
            /**
             * @var string|null 提款限制公式
             *
             * @example "1000"
             **/
            'withdraw_formula' => 'nullable|string',
            /**
             * @var string|null 提款限制客製化設定
             *
             * @example null
             **/
            'withdraw_config' => 'nullable|array',
            /**
             * @var int 活動序號vendor id
             *
             * @example 120
             **/
            'bonus_vendor_id' => 'nullable|integer',
            /**
             * @var array 是否阻擋重複IP(0:停用,1:啟用)
             *
             * @example 1
             **/
            'ip_block' => 'nullable|array',
            /**
             * @var array 是否阻擋重複手機號(0:停用,1:啟用)
             *
             * @example 1
             **/
            'phone_block' => 'nullable|array',
            /**
             * @var array 是否阻擋重複真實姓名(0:停用,1:啟用)
             *
             * @example 1
             **/
            'account_block' => 'nullable|array',
            /**
             * @var int 是否為參加即領獎(0:停用,1:啟用)
             *
             * @example 1
             **/
            'join_withdraw' => 'required|integer',
            /**
             * @var int 成本類型，說明請參考 EventV2DetailCostTypeEnum
             *
             * @example 1
             **/
            'cost_type' => 'required|integer',
            /**
             * @var int|null 領取去向(1:主錢包,2:積分)，沒送不動原值；見 EventV2WithdrawTypeEnum
             *
             * @example 1
             **/
            'withdraw_type' => $this->withdrawTypeRule('sometimes'),
            /**
             * @var int|null member_period 會員週期／可重複參加(0:不分 1:日 2:週 3:月 4:年)，沒送不動原值；只有紅包可設非 0，見 EventV2PeriodEnum
             * @var int|null total_period 全站週期／包括期間，值域同上：max_join／max_amount 的累計區間
             *
             * @example 1
             **/
            ...$this->periodRules('sometimes'),
            /**
             * @var string 開始時間
             *
             * @example "2025-05-10 12:19:16"
             **/
            'start_time' => 'required|string',
            /**
             * @var string 結束時間
             *
             * @example "2025-07-23 12:19:19"
             **/
            'end_time' => 'required|string',
            /**
             * @var int 是否回收(0:停用,1:啟用)
             *
             * @example 1
             **/
            'is_recycle' => 'required|integer',
            /**
             * @var int 狀態(0:停用,1:啟用)
             *
             * @example 1
             **/
            'status' => 'required|integer',
            /**
             * @var array|null 活動詳細語系
             **/
            'detail_lang' => 'nullable|array',
            /**
             * @var int 語系ID
             *
             * @example 2
             **/
            'detail_lang.*.language_id' => 'required|integer',
            /**
             * @var string|null 活動名稱
             *
             * @example "每日簽到存 30 送 10"
             **/
            'detail_lang.*.name' => 'nullable|string',
            /**
             * @var string|null 活動圖片
             *
             * @example "https://example.invalid/image.jpg"
             **/
            'detail_lang.*.image' => 'nullable|string',
            /**
             * @var string|null 活動獎勵名稱
             *
             * @example "威幣"
             **/
            'detail_lang.*.reward_name' => 'nullable|string',
            /**
             * @var string|null 活動內容
             *
             * @example "<div>內容</div>"
             **/
            'detail_lang.*.content' => 'nullable|string',
            /**
             * @var array|null 排除代理
             **/
            'exclude_agent' => 'nullable|array',
            /**
             * @var int 代理ID
             *
             * @example 4249
             **/
            'exclude_agent.*' => 'required|integer',
            /**
             * @var array|null 限制遊戲類型
             **/
            'restrict_game_type' => 'nullable|array',
            /**
             * @var int 遊戲類型ID
             *
             * @example 1
             **/
            'restrict_game_type.*' => 'required|integer',
            /**
             * @var array|null 限制遊戲商
             **/
            'restrict_vendor' => 'nullable|array',
            /**
             * @var int 限制遊戲商ID
             *
             * @example 144
             **/
            'restrict_vendor.*' => 'required|integer',
            /**
             * @var array|null 排除遊戲商
             **/
            'exclude_vendor_data' => 'nullable|array',
            /**
             * @var int 排除遊戲商ID
             *
             * @example 145
             **/
            'exclude_vendor_data.*' => 'required|integer',
        ];
    }
}
