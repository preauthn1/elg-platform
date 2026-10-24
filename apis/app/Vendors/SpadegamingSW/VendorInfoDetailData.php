<?php

declare(strict_types=1);

namespace App\Vendors\SpadegamingSW;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $GAME_URL,
        public string $Merchant_Code,
        public string $CURRENCY,
        public string $Security_key
    ) {}
}
