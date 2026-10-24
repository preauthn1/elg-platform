<?php

declare(strict_types=1);

namespace App\Vendors\VplusSW\Enums;

enum BetRecordStatusEnum: int
{
    case BET = 0;
    case SETTLE = 1;
    case REFUND = 2;
}
