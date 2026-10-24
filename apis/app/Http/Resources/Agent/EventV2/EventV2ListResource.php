<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2ListResource extends JsonResource
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
             * @var int 活動詳細ID
             *
             * @example 1
             **/
            'event_detail_id' => $this->id,
            /**
             * @var string 代理名稱
             *
             * @example "frank"
             **/
            'agent_username' => $this->agent_username,
            /**
             * @var int 活動分類 ID（event_v2.id）
             *
             * @example 16
             **/
            'event_v2_id' => $this->event_v2_id,
            /**
             * @var string|null 活動分類名稱（event_v2.name，與 event-v2/event-list 下拉同一份）
             *
             * @example "紅包"
             **/
            'event_type_name' => $this->event_type_name,
            /**
             * @var string 活動名稱
             *
             * @example "免費送"
             **/
            'name' => $this->name,
            /**
             * @var string 開始時間
             *
             * @example "2025-05-10 12:19:16"
             **/
            'start_time' => \Carbon\Carbon::parse($this->start_time)->toBackendTime()->toDateTimeString(),
            /**
             * @var string 結束時間
             *
             * @example "2025-07-23 12:19:19"
             **/
            'end_time' => \Carbon\Carbon::parse($this->end_time)->toBackendTime()->toDateTimeString(),
            /**
             * @var int 排序
             *
             * @example 1
             **/
            'sort' => $this->sort,
            /**
             * @var int 狀態(0:停用,1:啟用)
             *
             * @example 1
             **/
            'status' => $this->status,
            /**
             * @var int 領取去向(1:主錢包,2:積分)，見 EventV2WithdrawTypeEnum
             *
             * @example 1
             **/
            'withdraw_type' => $this->withdraw_type->value,
            /**
             * @var int 會員週期／可重複參加(0:不分 1:日 2:週 3:月 4:年)，只有紅包會非 0；見 EventV2PeriodEnum
             *
             * @example 0
             **/
            'member_period' => $this->member_period?->value ?? 0,
            /**
             * @var int 全站週期／包括期間(0:不分 1:日 2:週 3:月 4:年)，只有紅包會非 0
             *
             * @example 0
             **/
            'total_period' => $this->total_period?->value ?? 0,
            /**
             * @var string 建立時間
             *
             * @example "2025-07-23 12:19:19"
             **/
            'created_at' => \Carbon\Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
        ];
    }
}
