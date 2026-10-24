<?php

declare(strict_types=1);

namespace App\Vendors\Astar\Enums;

enum BetRecordStatusEnum: int
{
    case ROLLOUT = 0; // Rollout
    case ROLLIN = 1;
    case REFUND = 9;
}
