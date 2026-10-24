<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventProgressiveJackpot;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventProgressiveJackpotBetSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 遊戲類型，對應 game_types.id
             *
             * @example 1
             **/
            'game_type_id' => $this->game_type_id,
            /**
             * @var string 解鎖比例(%)，已 ×100 轉換為前端顯示用百分比數字
             *
             * @example "0.3000"
             **/
            'rate' => $this->rate,
        ];
    }
}
