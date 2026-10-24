<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BlockDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int newbie_challenge_block_records.id
             *
             * @example 1
             */
            'id' => $this->id,
            /**
             * @var int 阻擋維度；見 NewbieChallengeBlockTypeEnum(1=IP, 2=手機, 3=真實姓名)
             *
             * @example 1
             */
            'block_type' => $this->block_type->value,
            /**
             * @var string 錯誤訊息快照
             *
             * @example "此 IP 已有人參加過"
             */
            'message' => $this->message,
            /**
             * @var string 觸發時的 IP
             *
             * @example "0.0.0.0"
             */
            'ip' => $this->ip,
            /**
             * @var string 觸發時間
             *
             * @example "2026-08-31 17:38:25"
             */
            'created_at' => $this->created_at?->toBackendTime()->toDateTimeString(),
        ];
    }
}
