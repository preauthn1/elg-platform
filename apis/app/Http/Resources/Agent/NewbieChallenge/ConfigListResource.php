<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class ConfigListResource extends JsonResource
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
            'id' => (int) $this->id,
            /**
             * @var int|null 適用代理ID，null=全站
             *
             * @example null
             */
            'agent_id' => $this->agent_id !== null ? (int) $this->agent_id : null,
            /**
             * @var string|null 代理帳號
             *
             * @example "vwagent001"
             */
            'agent_username' => $this->agent_username,
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
            'bonus_window_hours' => (int) $this->bonus_window_hours,
            /**
             * @var int 1=僅活動開始後註冊的玩家可參加，0=活動開始前註冊的玩家也可參加
             *
             * @example 1
             */
            'restrict_new_member' => (int) $this->restrict_new_member,
            /**
             * @var int 後台活動列表排序，數字越大越前面
             *
             * @example 0
             */
            'sort' => (int) $this->sort,
            /**
             * @var int 狀態; 0:停用, 1:啟用
             *
             * @example 1
             */
            'status' => (int) $this->status,
            /**
             * @var string|null 活動開始時間
             *
             * @example "2026-08-13 00:00:00"
             */
            'start_time' => $this->start_time ? Carbon::parse($this->start_time)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 活動結束時間
             *
             * @example null
             */
            'end_time' => $this->end_time ? Carbon::parse($this->end_time)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string 建立時間
             *
             * @example "2026-08-13 06:15:00"
             */
            'created_at' => Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
        ];
    }
}
