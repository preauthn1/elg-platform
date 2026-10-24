<?php

declare(strict_types=1);

namespace App\Enums;

enum CouponBatchRewardModeEnum: int
{
    /**
     * 固定金額
     */
    case FIXED = 1;

    /**
     * 隨機獎勵
     */
    case RANDOM = 2;
}
