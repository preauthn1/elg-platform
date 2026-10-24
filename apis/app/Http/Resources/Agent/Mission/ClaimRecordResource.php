<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\Mission;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ClaimRecordResource extends JsonResource
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
             * @var int 領取紀錄ID
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var string 完成時間
             *
             * @example "2025-06-01 00:00:00"
             **/
            'finish_time' => Carbon::parse($this->finish_time)->toBackendTime()->toDateTimeString(),
            /**
             * @var string|null 領取時間
             *
             * @example "2025-06-01 00:00:00"
             **/
            'claim_time' => $this->claim_time === null ? null : Carbon::parse($this->claim_time)->toBackendTime()->toDateTimeString(),
            /**
             * @var string 任務名稱
             *
             * @example "每日儲值獎勵"
             **/
            'mission_name' => $this->mission_name,
            /**
             * @var string 代理帳號
             *
             * @example "agent01"
             **/
            'agent_username' => $this->agent_username,
            /**
             * @var string 會員帳號
             *
             * @example "member01"
             **/
            'member_username' => $this->member_username,
            /**
             * @var string 獎勵數量
             *
             * @example "100"
             **/
            'reward_value' => $this->reward_value,
            /**
             * @var string 獎勵類型
             *
             * @example "balance"
             **/
            'reward_type' => $this->reward_type->translate(),
            /**
             * @var string 狀態(0:未領取,1:已領取)
             *
             * @example "1"
             **/
            'is_claimed' => $this->is_claimed->translate(),
        ];
    }
}
