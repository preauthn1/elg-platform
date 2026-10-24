<?php

declare(strict_types=1);

namespace App\Enums;

enum CashFlowTypeEnum: int
{
    // 類型
    case DEPOSIT = 1;   // 線上支付

    case AUTO_DEPOSIT = 2;   // 自助開分

    case WITHDRAW = 3;   // 代付

    case AUTO_WITHDRAW = 4;   // 自助洗分
}
