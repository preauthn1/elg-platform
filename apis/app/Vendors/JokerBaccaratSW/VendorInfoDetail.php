<?php

declare(strict_types=1);

namespace App\Vendors\JokerBaccaratSW;

use Spatie\LaravelData\Data;

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $API_URL,
        public string $API_ID,
        public string $API_TOKEN,
        public string $CURRENCY,
    ) {}
}
