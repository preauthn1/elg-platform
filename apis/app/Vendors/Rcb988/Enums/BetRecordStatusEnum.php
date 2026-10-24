<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988\Enums;

enum BetRecordStatusEnum: int
{
    case BET = 0;
    case SETTLE = 1;
    case VOID_SETTLE = 7;
    case VOID_BET = 8;
    case CANCEL = 9;
}
