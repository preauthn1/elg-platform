<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class BlockListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int newbie_challenge_block_joins.id
             *
             * @example 1
             */
            'id' => (int) $this->id,
            /**
             * @var int newbie_challenge_configs.id
             *
             * @example 1
             */
            'config_id' => (int) $this->config_id,
            /**
             * @var int members.id
             *
             * @example 12345
             */
            'member_id' => (int) $this->member_id,
            /**
             * @var string 玩家帳號
             *
             * @example "vwtest001"
             */
            'member_username' => $this->member_username,
            /**
             * @var string 代理帳號
             *
             * @example "vwagent001"
             */
            'agent_username' => $this->agent_username,
            /**
             * @var int|null 最新一筆阻擋維度；見 NewbieChallengeBlockTypeEnum(1=IP, 2=手機, 3=真實姓名)
             *
             * @example 1
             */
            'block_type' => $this->block_type !== null ? (int) $this->block_type : null,
            /**
             * @var string|null 最新一次觸發時的 IP
             *
             * @example "0.0.0.0"
             */
            'ip' => $this->ip,
            /**
             * @var string|null 最新一次觸發的錯誤訊息快照
             *
             * @example "此 IP 已有人參加過"
             */
            'message' => $this->message,
            /**
             * @var string|null 最新一次觸發時間
             *
             * @example "2026-08-31 17:38:25"
             */
            'block_updated_at' => $this->block_updated_at ? Carbon::parse($this->block_updated_at)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var string|null 後台放行時間
             *
             * @example null
             */
            'allowed_time' => $this->allowed_time ? Carbon::parse($this->allowed_time)->toBackendTime()->toDateTimeString() : null,
            /**
             * @var int 狀態；見 NewbieChallengeBlockJoinStatusEnum(1=阻擋中, 2=已放行, 3=已用放行完成參加)
             *
             * @example 1
             */
            'status' => (int) $this->status,
            /**
             * @var string 建立時間
             *
             * @example "2026-08-31 17:38:25"
             */
            'created_at' => Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
        ];
    }
}
