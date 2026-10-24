<?php

declare(strict_types=1);

namespace App\Enums\Score;

/**
 * 積分異動紀錄類型（member_score_logs.type）——這筆異動「做了什麼」。
 *
 * 刻意不含系統名（沒有 EVENT_REWARD 這種 case）：是哪個系統發／扣的看 reference_type
 * （ScoreSourceTypeEnum），兩軸正交，新來源不需要在這裡加 case。
 * #4232 紀錄頁的「來源」欄由 type × reference_type 組出（REWARD + event_v2_user_join → 活動獎勵發放 · 活動名）。
 * 四種來源全部寫進同一張表，每一筆都必須對得回某張單。
 */
enum ScoreLogTypeEnum: int
{
    case REWARD = 1; // 系統發放（活動 V2、日後任務等），增加

    case CONSUME = 2; // 系統消耗（活動參加扣點等），扣除

    case MANUAL = 3; // 後台人工調整（發放或扣除）

    case EXPIRE = 4; // 到期失效（score:expire 排程或扣點前即時到期），扣除
}
