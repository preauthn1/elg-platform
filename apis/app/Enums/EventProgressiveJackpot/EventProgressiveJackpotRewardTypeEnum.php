<?php

declare(strict_types=1);

namespace App\Enums\EventProgressiveJackpot;

enum EventProgressiveJackpotRewardTypeEnum: int
{
    case FIXED = 1; // 固定金額

    case PERCENTAGE = 2; // 百分比
}
