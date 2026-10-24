<?php

declare(strict_types=1);

namespace App\Data\Vendor;

use App\Models\VendorInfo;
use Spatie\LaravelData\Data;

class GetGameListParamsData extends Data
{
    public function __construct(
        public VendorInfo $vendorInfo,
    ) {}
}
