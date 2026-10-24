<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class VendorTransferData extends Data
{
    public function __construct(
        /**
         * 交易紀錄 ID
         */
        public int $transactionLogId,
        /**
         * 交易流水號
         */
        public string $transactionNo,

        /**
         * 交易金額, 已乘上 vendor 匯率
         */
        public string $vendorAmount,
    ) {}
}
