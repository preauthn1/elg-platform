<?php

declare(strict_types=1);

namespace App\Http\Resources\Agent\MemberScore;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 積分異動明細一筆（target/tickets#4232 查看彈窗）。
 * 「來源」文案由前端以 type × reference_type 組（REWARD + event_v2_user_join → 活動獎勵發放 · 活動名）。
 */
class MemberScoreLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'log_id' => $this['log_id'],
            /**
             * @var int 被發／被扣的批次 id；跨批扣除拆成多筆時，靠它對回同一批的發放列
             *
             * @example 17
             */
            'batch_id' => $this['batch_id'],
            /**
             * @var string 異動時間（後台時區）
             *
             * @example "2026-09-16 15:28:58"
             */
            'changed_at' => $this['changed_at'],
            /**
             * @var int 做了什麼 ScoreLogTypeEnum：1 系統發放 2 系統消耗 3 人工調整 4 到期失效
             *
             * @example 3
             */
            'type' => $this['type'],
            /**
             * @var int 1 增加 2 扣除
             *
             * @example 2
             */
            'direction' => $this['direction'],
            /**
             * @var string 本筆積分（恆正）
             *
             * @example "20.0000"
             */
            'score' => $this['score'],
            /**
             * @var string 異動後可用積分
             *
             * @example "110.0000"
             */
            'after_score' => $this['after_score'],
            /**
             * @var string|null 該批到期日（後台時區）；無限期或扣除類為 null
             *
             * @example "2026-09-17 03:30:00"
             */
            'expired_at' => $this['expired_at'],
            /**
             * @var string 哪個系統 ScoreSourceTypeEnum：'' 無、'event_v2_user_join' 活動 V2
             *
             * @example "event_v2_user_join"
             */
            'reference_type' => $this['reference_type'],
            'reference_id' => $this['reference_id'],
            /**
             * @var string|null 活動名稱（reference_type 為活動 V2 時）
             *
             * @example "每日免費 19"
             */
            'activity_name' => $this['activity_name'],
            /**
             * @var string|null 操作者帳號；系統動作為 null
             *
             * @example "max30678"
             */
            'operator' => $this['operator'],
            'remark' => $this['remark'],
        ];
    }
}
