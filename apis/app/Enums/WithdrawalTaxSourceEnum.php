<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 提款單手續費來源（withdrawal_orders.tax_source）: 0=銀行, 1=會員群組階梯
 */
enum WithdrawalTaxSourceEnum: int
{
    case BANK = 0; // 銀行（banks.tax / tax_type）

    case MEMBER_GROUP = 1; // 會員群組平台手續費階梯
}
