<?php

declare(strict_types=1);

namespace App\Vendors\Top55;

enum BetRecordStatusEnum: int
{
    case BET = 1;

    case SETTLEMENT = 2;

    case CANCEL = 3;
}
