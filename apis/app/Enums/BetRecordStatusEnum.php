<?php

declare(strict_types=1);

namespace App\Enums;

class BetRecordStatusEnum
{
    public const int PENDING = 0; // 建立注單

    public const int IN_PROGRESS = 1; // 進行中

    public const int REFUND = 2; // 退款

    public const int SETTLE = 3; // 已結算
}
