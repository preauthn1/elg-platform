<?php

declare(strict_types=1);

namespace App\Vendors\PPSW;

class BetRecordStatusEnum
{
    public const int BET = 0; // 投注

    public const int END_ROUND = 1; // 結束回合

    public const int REFUND = 2; // 退款

    public const int SETTLE = 3; // 結算
}
