<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum CurrencyEnum: int
{
    use HasOptions;

    case TON_TON = 1;   // TON/TON
    case TON_USDT = 2;   // TON/USDT

    public function getLabel(): string
    {
        return match ($this) {
            self::TON_TON => CurrencyCodeEnum::TON_TON->value,
            self::TON_USDT => CurrencyCodeEnum::TON_USDT->value,
        };
    }
}
