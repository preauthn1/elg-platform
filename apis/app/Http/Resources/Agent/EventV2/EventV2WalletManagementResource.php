<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2WalletManagementResource extends JsonResource
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
             * @var int 參加活動ID
             *
             * @example 1
             **/
            'join_id' => $this->join_id,
            /**
             * @var string 活動建立時間
             *
             * @example "2025-06-18 17:38:25"
             **/
            'created_at' => \Carbon\Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
            /**
             * @var int 活動詳細ID
             *
             * @example 2
             **/
            'event_v2_detail_id' => $this->event_v2_detail_id,
            /**
             * @var string 活動名稱
             *
             * @example "免費送"
             **/
            'name' => $this->name,
            /**
             * @var string 會員名稱
             *
             * @example "zoe102"
             **/
            'member_username' => $this->member_username,
            /**
             * @var string 代理帳號
             *
             * @example "frank"
             **/
            'agent_username' => $this->agent_username,
            /**
             * @var int 活動錢包ID
             *
             * @example 2
             **/
            'wallet_id' => $this->wallet_id,
            /**
             * @var float 活動錢包餘額
             *
             * @example 10.25
             **/
            'balance' => (float) $this->balance,
        ];
    }
}
