<?php

declare(strict_types=1);

namespace App\Vendors\PegasusSW;

enum BetRecordStatusEnum: int
{
    /** 未結算 */
    case BET = 0;
    /** 已結算 */
    case SETTLE = 1;
    /** 已取消 */
    case ROLLBACK = 2;
}
