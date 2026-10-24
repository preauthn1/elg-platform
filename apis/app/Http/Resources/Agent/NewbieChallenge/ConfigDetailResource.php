<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConfigDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int newbie_challenge_configs.id
             *
             * @example 1
             */
            'id' => $this->id,
            /**
             * @var int|null 適用代理ID，null=全站
             *
             * @example null
             */
            'agent_id' => $this->agent_id,
            /**
             * @var string 活動金
             *
             * @example "88.0000"
             */
            'activity_amount' => $this->activity_amount,
            /**
             * @var string 挑戰流水倍數
             *
             * @example "5.0000"
             */
            'challenge_flow_multiple' => $this->challenge_flow_multiple,
            /**
             * @var string 彩金流水倍數
             *
             * @example "12.0000"
             */
            'bonus_flow_multiple' => $this->bonus_flow_multiple,
            /**
             * @var string 最低解鎖金額
             *
             * @example "10.0000"
             */
            'min_unlock_amount' => $this->min_unlock_amount,
            /**
             * @var string 最高解鎖金額
             *
             * @example "1888.0000"
             */
            'max_unlock_amount' => $this->max_unlock_amount,
            /**
             * @var int 彩金有效期(小時)
             *
             * @example 48
             */
            'bonus_window_hours' => $this->bonus_window_hours,
            /**
             * @var array|null 第二階段挑戰流水計算範圍的遊戲商/遊戲ID清單，與 excluded_vendor_ids 互斥，null=不限制遊戲商
             *
             * @example [214, 223]
             */
            'allowed_vendor_ids' => $this->allowed_vendor_ids,
            /**
             * @var array|null 第二階段挑戰流水計算範圍的遊戲類型清單，null=不限制遊戲類型
             *
             * @example null
             */
            'allowed_game_type_ids' => $this->allowed_game_type_ids,
            /**
             * @var array|null 第二階段挑戰流水排除的遊戲商清單，與 allowed_vendor_ids 互斥，null=不排除
             *
             * @example null
             */
            'excluded_vendor_ids' => $this->excluded_vendor_ids,
            /**
             * @var array|null 第四階段彩金流水計算範圍的遊戲商/遊戲ID清單，null=不限制遊戲商
             *
             * @example null
             */
            'bonus_flow_vendor_ids' => $this->bonus_flow_vendor_ids,
            /**
             * @var array|null 第四階段彩金流水計算範圍的遊戲類型清單，null=不限制遊戲類型
             *
             * @example null
             */
            'bonus_flow_game_type_ids' => $this->bonus_flow_game_type_ids,
            /**
             * @var array|null 第四階段彩金流水排除的遊戲商清單，與 bonus_flow_vendor_ids 互斥，null=不排除
             *
             * @example null
             */
            'excluded_bonus_flow_vendor_ids' => $this->excluded_bonus_flow_vendor_ids,
            /**
             * @var ConfigExcludeAgentResource[] 排除代理（含下線）
             */
            'exclude_agent' => ConfigExcludeAgentResource::collection($this->configExcludeAgents),
            /**
             * @var int|null 1=同IP僅一人可參加
             *
             * @example 1
             */
            'ip_block' => $this->ip_block,
            /**
             * @var int|null 1=同手機號僅一人可參加
             *
             * @example 1
             */
            'phone_block' => $this->phone_block,
            /**
             * @var int|null 1=同真實姓名僅一人可參加
             *
             * @example 1
             */
            'account_block' => $this->account_block,
            /**
             * @var int 1=僅活動開始後註冊的玩家可參加，0=活動開始前註冊的玩家也可參加
             *
             * @example 1
             */
            'restrict_new_member' => $this->restrict_new_member,
            /**
             * @var int 後台活動列表排序，數字越大越前面
             *
             * @example 0
             */
            'sort' => $this->sort,
            /**
             * @var int 狀態; 0:停用, 1:啟用
             *
             * @example 1
             */
            'status' => $this->status,
            /**
             * @var string|null 活動開始時間
             *
             * @example "2026-08-13 00:00:00"
             */
            'start_time' => $this->start_time?->toBackendTime()->toDateTimeString(),
            /**
             * @var string|null 活動結束時間
             *
             * @example null
             */
            'end_time' => $this->end_time?->toBackendTime()->toDateTimeString(),
            /**
             * @var array 各語系內容
             */
            'config_lang' => $this->configLangs->map(fn ($lang) => [
                'language_id' => $lang->language_id,
                'name' => $lang->name,
                'image' => $lang->image,
                'content' => $lang->content,
            ]),
        ];
    }
}
