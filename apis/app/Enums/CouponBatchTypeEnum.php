<?php

declare(strict_types=1);

namespace App\Enums;

enum CouponBatchTypeEnum: int
{
    case POINT = 1;
    case ROLLING_CHIP = 2;
}
