<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\NewbieChallenge;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

class OperationLogListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int newbie_challenge_operation_logs.id
             *
             * @example 1
             */
            'id' => (int) $this->id,
            /**
             * @var int newbie_challenge_progress.id
             *
             * @example 1
             */
            'progress_id' => (int) $this->progress_id,
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
             * @var int 操作者類型，見 NewbieChallengeOperatorTypeEnum(1:系統, 2:會員, 3:代理)
             *
             * @example 2
             */
            'operator_type' => (int) $this->operator_type,
            /**
             * @var int|null 操作者ID，系統操作為null
             *
             * @example 12345
             */
            'operator_id' => $this->operator_id !== null ? (int) $this->operator_id : null,
            /**
             * @var string 動作
             *
             * @example "quit"
             */
            'action' => $this->action,
            /**
             * @var int|null 轉換前狀態
             *
             * @example 1
             */
            'from_stage' => $this->from_stage !== null ? (int) $this->from_stage : null,
            /**
             * @var int|null 轉換後狀態
             *
             * @example 6
             */
            'to_stage' => $this->to_stage !== null ? (int) $this->to_stage : null,
            /**
             * @var string|null 備註
             *
             * @example null
             */
            'remark' => $this->remark,
            /**
             * @var string 建立時間
             *
             * @example "2026-08-13 06:15:00"
             */
            'created_at' => Carbon::parse($this->created_at)->toBackendTime()->toDateTimeString(),
        ];
    }
}
