<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Checkin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckinListResource extends JsonResource
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
             * @var int 打卡活動ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string 代理名稱
             *
             * @example "frank"
             **/
            'agent_name' => $this->agent_name,
            /**
             * @var string 開始時間
             *
             * @example "2025-06-01 00:00:00"
             **/
            'start_date' => $this->start_date ? Carbon::parse($this->start_date)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string 結束時間
             *
             * @example "2025-06-30 23:59:00"
             **/
            'end_date' => $this->end_date ? Carbon::parse($this->end_date)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string 狀態(0:關閉,1:開啟)
             *
             * @example "1"
             **/
            'status' => $this->status,
        ];
    }
}
