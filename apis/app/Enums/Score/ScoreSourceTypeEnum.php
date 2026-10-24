<?php

declare(strict_types=1);

namespace App\Enums\Score;

/**
 * 積分的來源／參照系統——「這批積分（或這筆異動）對回哪個系統的哪張單」。
 *
 * 兩個欄位共用本 enum，值是字串讓 DB 直接可讀（沿用 agent_operate_logs 的 reference_type 形狀）：
 * - member_score_batches.source_type：這批積分從哪來，配 source_id。不會是 NONE。
 * - member_score_logs.reference_type：這筆異動對回哪張單，配 reference_id。人工調整、到期失效為 NONE。
 *
 * 與 ScoreLogTypeEnum（發放／消耗／人工／到期）正交：那邊管「做了什麼」，這邊管「哪個系統」。
 * 日後 event_v3、任務、簽到、VIP 等來源**只加 case**，不動 schema；#4232 紀錄頁的「來源」欄由兩者組出。
 */
enum ScoreSourceTypeEnum: string
{
    case NONE = ''; // 無參照（僅 logs 使用：人工調整、到期失效）

    case MANUAL = 'manual'; // 後台人工發放（僅 batches 使用；logs 的人工異動 reference 為 NONE）

    case EVENT_V2_USER_JOIN = 'event_v2_user_join'; // 活動 V2，指向 event_v2_user_joins.id

    /**
     * 發放時對應的異動紀錄類型：人工來源寫 MANUAL，其餘系統來源一律 REWARD。
     */
    public function toGrantLogType(): ScoreLogTypeEnum
    {
        return $this === self::MANUAL ? ScoreLogTypeEnum::MANUAL : ScoreLogTypeEnum::REWARD;
    }

    /**
     * 發放時寫進 log 的參照：人工發放無參照，系統來源就是自己。
     */
    public function toGrantReference(): self
    {
        return $this === self::MANUAL ? self::NONE : $this;
    }
}
