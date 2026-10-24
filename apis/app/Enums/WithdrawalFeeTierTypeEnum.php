<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 會員群組平台手續費階梯的收費方式: 0=固定金額, 1=百分比（value 存百分比數值，3=3%、2.5=2.5%，非 0.03）
 */
enum WithdrawalFeeTierTypeEnum: int
{
    case FIXED = 0; // 固定金額

    case PERCENT = 1; // 百分比
}
