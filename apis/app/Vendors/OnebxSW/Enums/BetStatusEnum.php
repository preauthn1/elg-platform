<?php

declare(strict_types=1);

namespace App\Vendors\OnebxSW\Enums;

enum BetStatusEnum: int
{
    case BET = 0;
    case CANCELED = -1;
    case SETTLED = 1;
}
