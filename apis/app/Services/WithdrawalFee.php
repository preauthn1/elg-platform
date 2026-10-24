<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\WithdrawalTaxSourceEnum;

/**
 * WithdrawalFeeResolver::resolve() 的回傳值：提款單手續費（寫入 withdrawal_orders.tax）與其來源。
 */
final readonly class WithdrawalFee
{
    public function __construct(
        public string $tax,
        public WithdrawalTaxSourceEnum $source,
    ) {}
}
