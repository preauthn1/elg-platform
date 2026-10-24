<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Checkin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckinRecordResource extends JsonResource
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
             * @var int 打卡活動紀錄ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string 使用者名稱
             *
             * @example "vwtest001"
             **/
            'username' => $this->username,
            /**
             * @var string 打卡時間
             *
             * @example "2025-06-01"
             **/
            'checkin_date' => $this->created_at->toBackendTime()->toDateString(),
            /**
             * @var CheckinRecordPrizeResource 打卡獎勵
             *
             **/
            'prize' => $this->checkinPrizeRecords ? CheckinRecordPrizeResource::collection($this->checkinPrizeRecords) : [],
        ];
    }
}
