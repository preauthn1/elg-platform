<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class EventV2DetailResource extends JsonResource
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
             * @var string|null 活動內容
             *
             * @example "<div>內容</div>"
             **/
            'content' => $this['content'],
            /**
             * @var bool 是否為參加即領獎
             *
             * @example true
             **/
            'join_withdraw' => $this['join_withdraw'],
            /**
             * @var bool 是否能參加
             *
             * @example true
             **/
            'validate' => $this['validate'],
            /**
             * @var string 錯誤訊息
             *
             * @example "儲值尚未達到標準"
             **/
            'message' => $this['message'],
            /**
             * @var array 活動優惠碼
             *
             * @example EventV2DetailBonusCodeResource collection
             **/
            'bonus_code' => EventV2DetailBonusCodeResource::collection($this['bonus_code']),
            /**
             * @var string|null 活動結束時間
             *
             * @example "2035-01-01 00:00:00"
             **/
            'end_time' => isset($this['end_time']) && $this['end_time'] !== null
                ? Carbon::parse($this['end_time'])->toFrontendTime()->toDateTimeString()
                : null,
        ];
    }
}
