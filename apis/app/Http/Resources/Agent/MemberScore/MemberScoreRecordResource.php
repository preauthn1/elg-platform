<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\MemberScore;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 積分紀錄主表一列（target/tickets#4232）。數量關係：可用＋已過期＋已使用＝累計獲得（已使用不另回，由前端或明細加總）。
 */
class MemberScoreRecordResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            /**
             * @var int 會員系統編號
             *
             * @example 12345
             */
            'member_id' => $this['member_id'],
            /**
             * @var string 會員帳號
             *
             * @example "vwtest001"
             */
            'username' => $this['username'],
            /**
             * @var string 所屬代理帳號
             *
             * @example "agent01"
             */
            'agent_username' => $this['agent_username'],
            /**
             * @var string 可用積分（4 位小數字串，顯示限 2 位）
             *
             * @example "110.0000"
             */
            'available_score' => $this['available_score'],
            /**
             * @var string 已過期積分（歷來累計，只增不減）
             *
             * @example "18.0000"
             */
            'expired_score' => $this['expired_score'],
            /**
             * @var string 累計獲得
             *
             * @example "130.0000"
             */
            'granted_score' => $this['granted_score'],
            /**
             * @var string 已使用＝系統消耗＋人工扣除（不含到期失效；target/tickets#4438）
             *
             * @example "30.0000"
             */
            'used_score' => $this['used_score'],
            /**
             * @var string|null 最近到期時間（後台時區）：還有剩餘、到期時間最早的那一刻；全部無期限或沒剩餘為 null
             *
             * @example "2026-10-05 18:00:00"
             */
            'next_expire_at' => $this['next_expire_at'],
            /**
             * @var string|null 最近到期那一刻會失效的剩餘積分（同一刻到期的多批加總）；無則 null
             *
             * @example "200.0000"
             */
            'next_expire_score' => $this['next_expire_score'],
            /**
             * @var string|null 最近異動時間（後台時區）
             *
             * @example "2026-09-16 15:28:58"
             */
            'last_changed_at' => $this['last_changed_at'],
        ];
    }
}
