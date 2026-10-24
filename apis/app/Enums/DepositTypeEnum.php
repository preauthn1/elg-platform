<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 存款方式: 1=電子支付、2=網銀轉帳、3=QR掃碼、4=加密貨幣
 */
enum DepositTypeEnum: int
{
    case ONLINE_PAYMENT = 1; // 電子支付
    case BANK_TRANSFER = 2; // 網銀轉帳
    case QRCODE = 3; // QR 掃碼
    case CRYPTO = 4; // 加密貨幣

    public function label(): string
    {
        return match ($this) {
            self::ONLINE_PAYMENT => '電子支付',
            self::BANK_TRANSFER => '網銀轉帳',
            self::QRCODE => 'QR 掃碼',
            self::CRYPTO => '加密貨幣',
        };
    }
}
