<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2RecycleListResource extends JsonResource
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
             * @var string 活動名稱
             *
             * @example "免費送"
             **/
            'name' => $this->name,
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             **/
            'agent_username' => $this->agent_username,
            /**
             * @var string 會員名稱
             *
             * @example "zoe102"
             **/
            'member_username' => $this->member_username,
            /**
             * @var float 最終餘額
             *
             * @example 100
             **/
            'final_balance' => (float) $this->final_balance,
            /**
             * @var float 活動派獎金額
             *
             * @example 10
             **/
            'withdraw' => (float) $this->withdraw,
            /**
             * @var float 回收金額
             *
             * @example 90
             **/
            'recycle_amount' => (float) $this->recycle_amount,
            /**
             * @var string 回收時間
             *
             * @example "2025-06-18 17:38:25"
             **/
            'created_at' => \Carbon\Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
            /**
             * @var int 狀態(1:系統排程回收2:完成活動回收3:放棄活動回收4:系統重置回收)
             *
             * @example 1
             **/
            'status' => $this->status,
        ];
    }
}
