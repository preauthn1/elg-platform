<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Checkin;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CheckinDetailResource extends JsonResource
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
             * @var int 代理ID
             *
             * @example 4249
             **/
            'agent_id' => $this->agent_id,
            /**
             * @var int 稽核流水倍數
             *
             * @example 2
             **/
            'flow_limit_multiple' => $this->flow_limit_multiple,
            /**
             * @var int 阻擋重複IP
             *
             * @example 1
             **/
            'ip_block' => $this->ip_block,
            /**
             * @var int 阻擋重複手機號
             *
             * @example 0
             **/
            'phone_block' => $this->phone_block,
            /**
             * @var int 阻擋重複真實姓名
             *
             * @example 1
             **/
            'account_block' => $this->account_block,
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
             * @var CheckinDetailPrizeResource 打卡獎勵
             *
             **/
            'checkin_prizes' => $this->prizes ? CheckinDetailPrizeResource::collection($this->prizes) : [],
        ];
    }
}
