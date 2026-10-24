<?php

declare(strict_types=1);

namespace App\Vendors\RGSW\Enums;

enum BetRecordStatusEnum: int
{
    case BET = 1;
    case PAYOUT = 2;
    case ENDROUND = 3;
    case BETPAYOUT = 4;
    case REFUND = 5;
    case DEBIT = 6;
    case CREDIT = 7;
    case SUMBET = 8;
}
