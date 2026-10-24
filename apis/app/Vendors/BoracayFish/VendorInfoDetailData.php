<?php

declare(strict_types=1);

namespace App\Vendors\BoracayFish;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $MERCHANT_ID,
        public string $CERT,
        public string $CURRENCY,
    ) {}
}
