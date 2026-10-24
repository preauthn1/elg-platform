<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\Mission;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MissionListResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 任務ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string 任務名稱
             *
             * @example "充值任務"
             **/
            'name' => $this->name,
            /**
             * @var string 任務描述
             *
             * @example "充值任務"
             **/
            'description' => $this->description,
            /**
             * @var string 任務重置類型 MissionResetTypeEnum
             *
             * @example "daily"
             **/
            'reset_type' => $this->reset_type,
            /**
             * @var string 任務行為類型 MissionActionTypeEnum
             *
             * @example "bet"
             **/
            'action_type' => $this->action_type,
            /**
             * @var string 任務進度
             *
             * @example "30.00"
             **/
            'progress' => $this->progress,
            /**
             * @var int 領取狀態
             *
             * @example 0
             **/
            'is_claimed' => $this->is_claimed,
            /**
             * @var string 獎勵類型 MissionRewardTypeEnum
             *
             * @example "balance"
             **/
            'reward_type' => $this->reward_type,
            /**
             * @var string 獎勵值
             *
             * @example "10"
             **/
            'reward_value' => $this->reward_value,
            /**
             * @var string 任務目標值
             *
             * @example "100.00"
             **/
            'requirement' => $this->requirement,
            /**
             * @var josn|null 限制遊戲類型、遊戲商
             *
             * @example "[{"game_type":["1","2"],"vendor_id":["1","6"]}]"
             **/
            'game' => $this->game,
        ];
    }
}
