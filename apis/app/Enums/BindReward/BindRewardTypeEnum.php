<?php

declare(strict_types=1);

namespace App\Enums\BindReward;

enum BindRewardTypeEnum: int
{
    case TYPE_BALANCE = 1;

    case TYPE_ROLLING_CHIPS = 2;
}
