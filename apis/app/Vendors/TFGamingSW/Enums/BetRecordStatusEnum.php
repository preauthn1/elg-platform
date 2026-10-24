<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Enums;

enum BetRecordStatusEnum: int
{
    case NO_RESULT = 0;
    case CANCEL = -1;
    case SETTLED = 1;

    case IS_ROLLBACK = 8;
    case IS_SETTLEMENT = 9;
    case IS_UNSETTLEMENT = 10;
}
