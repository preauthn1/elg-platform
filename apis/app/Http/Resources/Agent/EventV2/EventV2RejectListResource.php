<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\EventV2;

use App\Enums\EventV2\EventV2ValidateCodeEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventV2RejectListResource extends JsonResource
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
             * @var int id
             *
             * @example 1
             **/
            'id' => $this->id,
            /**
             * @var int 活動詳細ID
             *
             * @example 1
             **/
            'event_v2_detail_id' => $this->event_v2_detail_id,
            /**
             * @var int 活動參加ID
             *
             * @example 1
             **/
            'event_v2_user_join_id' => $this->event_v2_user_join_id,
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
             * @var string 拒絕理由
             *
             * @example "IP已重複"
             **/
            'reject_reason' => EventV2ValidateCodeEnum::from($this->validate_code)->translate(),
            /**
             * @var string IP
             *
             * @example "0.0.0.0"
             **/
            'ip' => $this->ip,
            /**
             * @var string 拒絕參加時間
             *
             * @example "2025-06-18 17:38:25"
             **/
            'reject_time' => \Carbon\Carbon::parse($this->updated_at)->toBackendTime()->toDateTimeString(),
            /**
             * @var null|string 允許參加時間
             *
             * @example "2025-06-18 17:38:25"
             **/
            'allow_time' => $this->allowed_time ? \Carbon\Carbon::parse($this->allowed_time)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var int 請參照 EventV2RejectJoinStatusEnum
             *
             * @example 1
             **/
            'status' => $this->status,
        ];
    }
}
