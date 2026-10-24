<?php

declare(strict_types=1);

namespace App\Vendors\AcewinSW;

enum BetRecordStatusEnum: int
{
    case SETTLE = 1;
    case ROLLBACK = 9;
}
