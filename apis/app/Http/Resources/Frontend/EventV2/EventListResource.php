<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class EventListResource extends JsonResource
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
             * @var int|null 活動V1 ID
             *
             * @example 1
             **/
            'event_v1_id' => $this->event_v1_id,
            /**
             * @var int|null 活動V2 ID
             *
             * @example 1
             **/
            'event_v2_id' => $this->event_v2_id,
            /**
             * @var int|null 非活動V1/V2的其他活動類型（如邀請好友），2:邀請好友；活動V1/V2固定為 null
             *
             * @example 2
             **/
            'tp_event' => $this->tp_event,
            /**
             * @var string|null 活動名稱
             *
             * @example "免費送"
             **/
            'name' => $this->name,
            /**
             * @var string|null 活動圖片
             *
             * @example "https://example.invalid/image.jpg"
             **/
            'image' => $this->image,
            /**
             * @var array|null 前端所需活動參數
             *
             * @example [
             *  "type" => 1,
             *  "category" => "category",
             * ]
             **/
            'params' => $this->params,
            /**
             * @var array<int> 遊戲類型ID列表
             *
             * @example [1, 2, 3]
             **/
            'game_type_ids' => $this->game_type_ids,
            /**
             * @var string|null 活動結束時間
             *
             * @example "2035-01-01 00:00:00"
             **/
            'end_time' => $this->end_time !== null
                ? Carbon::parse($this->end_time)->toFrontendTime()->toDateTimeString()
                : null,
            /**
             * @var int|null 第三方活動代號，前端依此判斷要導去哪個系統；event_v1/event_v2 為 null，其餘系統各自帶自己的固定代號
             *
             * @example 1
             **/
            'tp_event' => $this->tp_event,
            /**
             * @var string|null 活動 V2 的類型 handler，前端據此分辨要走專屬畫面的類型（紅包＝AngpowService，資訊走 event-v2/angpow/info）；V1／其他系統為 null
             *
             * @example "AngpowService"
             **/
            'handler_class' => $this->handler_class,
        ];
    }
}
