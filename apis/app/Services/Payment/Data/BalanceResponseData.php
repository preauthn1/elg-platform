<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

use Spatie\LaravelData\Data;

class BalanceResponseData extends Data
{
    public function __construct(
        public int $merchantStatus, // 商戶狀態
        public ?string $depositBalance, // 存款額度
        public ?string $withdrawBalance,  // 提款額度
    ) {}
}
