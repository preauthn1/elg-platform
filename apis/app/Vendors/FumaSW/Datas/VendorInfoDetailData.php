<?php

declare(strict_types=1);

namespace App\Vendors\FumaSW\Datas;

use Spatie\LaravelData\Data;

class VendorInfoDetailData extends Data
{
    public function __construct(
        public string $API_URL,
        public string $DC,
        public string $KEY,
        public string $IV,
        public string $PARENT,
    ) {}
}
