<?php

declare(strict_types=1);

namespace App\Vendors\Wcasino\Enums;

enum BetRecordStatusEnum: int
{
    case PENDING = 0;
    case CANCELLED = -1;
    case SETTLED = 1;
}
