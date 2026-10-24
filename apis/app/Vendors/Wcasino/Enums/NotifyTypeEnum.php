<?php

declare(strict_types=1);

namespace App\Vendors\Wcasino\Enums;

enum NotifyTypeEnum: int
{
    case BET = 1;
    case CANCEL = 7;
    case SETTLE = 9;
    case RESETTLE = 10;
    case ROLLBACK = 12;
}
