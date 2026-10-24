<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class VendorBonusCodeResponseData extends Data
{
    public function __construct(
        /**
         * 優惠碼
         *
         * @var string
         */
        public string $bonus_code = '',
    ) {}
}
