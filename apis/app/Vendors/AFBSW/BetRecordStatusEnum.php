<?php

declare(strict_types=1);

namespace App\Vendors\AFBSW;

enum BetRecordStatusEnum: int
{
    case BET = 1;

    case PAID = 2;

    case REFOUND = 3;
}
