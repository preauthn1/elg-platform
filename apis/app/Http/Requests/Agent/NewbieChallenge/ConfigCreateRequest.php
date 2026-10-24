<?php

declare(strict_types=1);

namespace App\Http\Requests\Agent\NewbieChallenge;

use App\Http\Requests\AbstractRequest;
use Illuminate\Contracts\Validation\Validator;

class ConfigCreateRequest extends AbstractRequest
{
    use VendorExclusionValidationTrait;

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $this->validateVendorExclusivity($validator, 'allowed_vendor_ids', 'excluded_vendor_ids');
            $this->validateVendorExclusivity($validator, 'bonus_flow_vendor_ids', 'excluded_bonus_flow_vendor_ids');
        });
    }

    public function rules(): array
    {
        return [
            /**
             * @var int|null 適用代理ID，null=全站
             *
             * @example null
             */
            'agent_id' => 'nullable|integer',
            /**
             * @var string 活動金
             *
             * @example "88"
             */
            'activity_amount' => 'required|numeric|min:0',
            /**
             * @var string 挑戰流水倍數
             *
             * @example "5"
             */
            'challenge_flow_multiple' => 'required|numeric|min:0',
            /**
             * @var string 彩金流水倍數
             *
             * @example "12"
             */
            'bonus_flow_multiple' => 'required|numeric|min:0',
            /**
             * @var string 最低解鎖金額
             *
             * @example "10"
             */
            'min_unlock_amount' => 'required|numeric|min:0',
            /**
             * @var string 最高解鎖金額
             *
             * @example "1888"
             */
            'max_unlock_amount' => 'required|numeric|min:0',
            /**
             * @var int 彩金有效期(小時)
             *
             * @example 48
             */
            'bonus_window_hours' => 'required|integer|min:1',
            /**
             * @var array|null 第二階段挑戰流水計算範圍的遊戲商/遊戲ID清單，與 excluded_vendor_ids 互斥，null=不限制遊戲商
             *
             * @example [214, 223]
             */
            'allowed_vendor_ids' => 'nullable|array',
            'allowed_vendor_ids.*' => 'required|integer',
            /**
             * @var array|null 第二階段挑戰流水計算範圍的遊戲類型清單，null=不限制遊戲類型
             *
             * @example null
             */
            'allowed_game_type_ids' => 'nullable|array',
            'allowed_game_type_ids.*' => 'required|integer',
            /**
             * @var array|null 第二階段挑戰流水排除的遊戲商清單，與 allowed_vendor_ids 互斥，null=不排除
             *
             * @example null
             */
            'excluded_vendor_ids' => 'nullable|array',
            'excluded_vendor_ids.*' => 'required|integer',
            /**
             * @var array|null 第四階段彩金流水計算範圍的遊戲商/遊戲ID清單，null=不限制遊戲商
             *
             * @example null
             */
            'bonus_flow_vendor_ids' => 'nullable|array',
            'bonus_flow_vendor_ids.*' => 'required|integer',
            /**
             * @var array|null 第四階段彩金流水排除的遊戲商清單，與 bonus_flow_vendor_ids 互斥，null=不排除
             *
             * @example null
             */
            'excluded_bonus_flow_vendor_ids' => 'nullable|array',
            'excluded_bonus_flow_vendor_ids.*' => 'required|integer',
            /**
             * @var array|null 第四階段彩金流水計算範圍的遊戲類型清單，null=不限制遊戲類型
             *
             * @example null
             */
            'bonus_flow_game_type_ids' => 'nullable|array',
            'bonus_flow_game_type_ids.*' => 'required|integer',
            /**
             * @var array|null 排除代理（含下線），null=不排除
             *
             * @example [4249]
             */
            'exclude_agent' => 'nullable|array',
            'exclude_agent.*' => 'required|integer',
            /**
             * @var int|null 1=同IP(members.last_login_ip)僅一人可參加
             *
             * @example 1
             */
            'ip_block' => 'nullable|integer|in:0,1',
            /**
             * @var int|null 1=同手機號僅一人可參加
             *
             * @example 1
             */
            'phone_block' => 'nullable|integer|in:0,1',
            /**
             * @var int|null 1=同真實姓名(first_name)僅一人可參加
             *
             * @example 1
             */
            'account_block' => 'nullable|integer|in:0,1',
            /**
             * @var int|null 1=僅活動開始後註冊的玩家可參加，0=活動開始前註冊的玩家也可參加
             *
             * @example 1
             */
            'restrict_new_member' => 'nullable|integer|in:0,1',
            /**
             * @var int|null 後台活動列表排序，數字越大越前面
             *
             * @example 0
             */
            'sort' => 'nullable|integer',
            /**
             * @var int 狀態; 0:停用, 1:啟用
             *
             * @example 1
             */
            'status' => 'required|integer|in:0,1',
            /**
             * @var string|null 活動開始時間
             *
             * @example "2026-08-13 00:00:00"
             */
            'start_time' => 'nullable|string',
            /**
             * @var string|null 活動結束時間
             *
             * @example null
             */
            'end_time' => 'nullable|string',
            /**
             * @var array|null 各語系內容
             */
            'config_lang' => 'nullable|array',
            /**
             * @var int languages.id
             *
             * @example 2
             */
            'config_lang.*.language_id' => 'required|integer',
            /**
             * @var string|null 活動名稱
             *
             * @example "新人專享挑戰"
             */
            'config_lang.*.name' => 'nullable|string',
            /**
             * @var string|null 活動圖片
             *
             * @example "https://example.invalid/image.jpg"
             */
            'config_lang.*.image' => 'nullable|string',
            /**
             * @var string|null 活動說明與規則文字
             *
             * @example "<div>內容</div>"
             */
            'config_lang.*.content' => 'nullable|string',
        ];
    }
}
