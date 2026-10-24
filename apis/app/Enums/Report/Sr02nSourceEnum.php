<?php

declare(strict_types=1);

namespace App\Enums\Report;

/**
 * SR02N「來源」（沿用 SR02 的 source 值域；2 已廢不收）。
 */
enum Sr02nSourceEnum: int
{
    case ALL = 0;               // 全部
    case CUSTOMER_SERVICE = 1;  // 客服系統
    case ONLINE_DEPOSIT = 3;    // 存款支付商（3.X）
    case ONLINE_PAYOUT = 4;     // 取款支付商（4.X）

    /** 選了支付商來源：代理兩段整段不併（SR02 同） */
    public function isCashFlow(): bool
    {
        return $this === self::ONLINE_DEPOSIT || $this === self::ONLINE_PAYOUT;
    }
}
