<?php

declare(strict_types=1);

namespace App\Enums\Score;

/**
 * 積分批次狀態（member_score_batches.status）
 *
 * 到期失效（EXPIRED）與正常用罄（EXHAUSTED）必須分開：#4232 主表的「已過期積分」
 * 只算 EXPIRED，沒有這個區分就分不出一批是被用掉還是過期掉。
 */
enum ScoreBatchStatusEnum: int
{
    case ACTIVE = 1; // 有效

    case EXHAUSTED = 2; // 已用罄

    case EXPIRED = 3; // 已到期失效
}
