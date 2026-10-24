<?php

declare(strict_types=1);

namespace App\Enums\Score;

/**
 * 積分異動方向（member_score_logs.direction）
 *
 * member_score_logs.score 恆為正數，增減看這個欄位——比照 rolling_chip_logs.amount 恆正的慣例，
 * 加總時不必先判正負。
 */
enum ScoreLogDirectionEnum: int
{
    case INCREASE = 1; // 增加

    case DECREASE = 2; // 扣除
}
