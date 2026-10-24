<?php

declare(strict_types=1);

namespace App\Http\Resources\Frontend\EventV2;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventClaimRecordsResource extends JsonResource
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
             * @var string 來源：`eventv1`（舊活動領獎）或 `eventv2`（活動 V2）
             *
             * @example "eventv1"
             */
            'record_source' => $this->record_source,
            /**
             * @var int 來源資料表主鍵（需搭配 record_source 解讀）
             *
             * @example 1
             */
            'record_id' => (int) $this->record_id,
            /**
             * @var string 活動名稱（依請求語系）
             *
             * @example "新春加碼"
             */
            'event_name' => $this->event_name,
            /**
             * @var string V1 為獎項名稱；V2 固定為 Points
             *
             * @example "Points"
             */
            'prize_name' => $this->prize_name,
            /**
             * @var string 獎勵點數或金額
             *
             * @example "100.00"
             */
            'point' => (string) $this->point,
            /**
             * @var string 領獎／完成時間（前端時區字串）
             *
             * @example "2024-01-01 12:00:00"
             */
            'claimed_at' => Carbon::parse($this->claimed_at)->toFrontendTime()->toDateTimeString(),
        ];
    }
}
