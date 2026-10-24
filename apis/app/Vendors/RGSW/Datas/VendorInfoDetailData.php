<?php

declare(strict_types=1);

namespace App\Vendors\RGSW\Datas;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $apiUrl,
        public string $appKey,
        public string $appSecret,
        public string $token,
    ) {}
}
