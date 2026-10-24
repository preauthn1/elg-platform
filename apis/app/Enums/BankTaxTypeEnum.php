<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 銀行手續費計算方式（banks.tax_type）: 0=固定金額, 1=百分比
 *
 * 百分比時 banks.tax 存小數（0.02＝2%），與會員群組平台手續費階梯的百分比數值（3＝3%）尺度不同，
 * 兩者不可互相套用（見 WithdrawalFeeTierTypeEnum）。
 */
enum BankTaxTypeEnum: int
{
    case FIXED = 0; // 固定金額

    case PERCENT = 1; // 百分比（banks.tax 存小數）
}
