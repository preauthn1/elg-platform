<?php

declare(strict_types=1);

namespace App\Vendors\Nex4DSW;

class BetRecordStatusEnum
{
    public const int BET = 0; // 投注

    public const int PAYOUT = 1; // 結算 (派彩)

    public const int CANCEL = -1; // 取消投注

    public const int REFUND = -2; // 退款
}
