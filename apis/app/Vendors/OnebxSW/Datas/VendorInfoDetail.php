<?php

declare(strict_types=1);

namespace App\Vendors\OnebxSW\Datas;

use Spatie\LaravelData\Data;

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $API_URL,
        public string $MERCHANT_ID,
        public string $SECRET_KEY,
    ) {}
}
