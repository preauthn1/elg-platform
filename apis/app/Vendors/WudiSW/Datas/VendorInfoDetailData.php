<?php

declare(strict_types=1);

namespace App\Vendors\WudiSW\Datas;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $ACCOUNT_ID,
        public string $MD5_KEY,
        public string $SECURITY_CODE,
    ) {}
}
