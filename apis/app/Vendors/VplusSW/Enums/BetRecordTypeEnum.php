<?php

declare(strict_types=1);

namespace App\Vendors\VplusSW\Enums;

enum BetRecordTypeEnum: int
{
    case WIN_NORMAL = 0;
    case WIN_NORMAL_TRIGGER_FREE_SPIN = 1;
    case WIN_NORMAL_TRIGGER_SPECIAL = 2;
    case BUY_FREE_SPIN = 3;
    case BUY_SPECIAL = 4;
    case REFUND = 101;
}
