<?php

declare(strict_types=1);

namespace App\Vendors\WFSW;

enum BetRecordStatusEnum: int
{
    /** 投注 */
    case BET = 1;
    /** 派彩 */
    case RESULT = 2;
    /** 回滾 */
    case ROLLBACK = 3;
    /** 獎金 */
    case BONUS = 4;
    /**  jackpot */
    case JACKPOT = 5;
}
