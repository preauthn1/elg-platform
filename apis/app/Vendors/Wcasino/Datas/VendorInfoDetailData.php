<?php

declare(strict_types=1);

namespace App\Vendors\Wcasino\Datas;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $AUTH_URL,
        public string $CLIENT_ID,
        public string $CLIENT_SECRET,
        public string $CLIENT_QID,
        public string $CLIENT_CLASS,
        public string $CURRENCY,
    ) {}
}
