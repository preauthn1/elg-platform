<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum CurrencyCodeEnum: string
{
    use HasOptions;

    case TON_TON = 'TON/TON';
    case TON_USDT = 'TON/USDT';

    public function getLabel(): string
    {
        return match ($this) {
            self::TON_TON => self::TON_TON->value,
            self::TON_USDT => self::TON_USDT->value,
        };
    }
}
