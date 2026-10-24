<?php

declare(strict_types=1);

namespace App\Vendors\UUSlotSW;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $Security_key,
        public string $Merchant_Code,
        public ?string $CURRENCY = 'MYR',
    ) {}
}
