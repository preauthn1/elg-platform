<?php

declare(strict_types=1);

namespace App\Services\Payment\Data;

/**
 * 永久收款地址（SupportsPermanentDepositAddress::getOrCreatePermanentDepositAddress() 的回傳值）。
 *
 * memo 只有需要 memo 的鏈（XRP、XLM、TON 等）才有值；TRON 一律 null。
 */
final readonly class PermanentDepositAddress
{
    public function __construct(
        public string $address,
        public ?string $memo = null,
    ) {}
}
