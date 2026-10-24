<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 取款方式: 1=銀行號碼, 2=GCASH, 3=加密貨幣, 4=外幣銀行, 5=現金, 6=澳洲銀行, 7=澳洲PayId, 8=印度銀行
 */
enum WithdrawalTypeEnum: int
{
    case BANK = 1; // 銀行號碼

    case GCASH = 2; // GCASH

    case CRYPTO = 3; // 加密貨幣

    case FOREIGN_BANK = 4; // 外幣銀行

    case CASH = 5; // 現金

    case AUSTRALIA_BANK = 6; // 澳洲銀行

    case AUSTRALIA_PAY_ID = 7; // 澳洲PayId

    case INDIA_BANK = 8;

    public function label(): string
    {
        return match ($this) {
            self::BANK => '銀行號碼',
            self::GCASH => 'GCASH',
            self::CRYPTO => '加密貨幣',
            self::FOREIGN_BANK => '外幣銀行',
            self::CASH => '現金',
            self::AUSTRALIA_BANK => '澳洲銀行',
            self::AUSTRALIA_PAY_ID => '澳洲PayId',
            self::INDIA_BANK => '印度銀行',
        };
    }
}
