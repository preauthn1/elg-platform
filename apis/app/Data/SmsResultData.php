<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class SmsResultData extends Data
{
    public function __construct(
        /**
         * 1: 發送成功
         * 2: 發送失敗
         *
         * @var int
         */
        public int $status,
        public string $response,
        public string $transactionId, // 三方的交易ID
    ) {}
}
