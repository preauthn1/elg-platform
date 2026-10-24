<?php

declare(strict_types=1);

namespace App\Vendors\MotivationSW\Enums;

enum BetRecordStatusEnum: int
{
    case BET = 1;
    case ENDROUND = 2;
    case ROLLOUT = 3;
    case ROLLIN = 4;
    case REFUND = 5;
    case DEBIT = 6;
    case CREATE = 7;
}
