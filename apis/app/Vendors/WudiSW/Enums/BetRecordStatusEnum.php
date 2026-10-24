<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Enums;

enum BetRecordStatusEnum: int
{
    case START = 0;
    case END = 9;
    case REFUND = -1;
    case REWARD = 10;
}
