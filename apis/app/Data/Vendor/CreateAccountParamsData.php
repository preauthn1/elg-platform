<?php

declare(strict_types=1);

namespace App\Data\Vendor;

use App\Models\VendorInfo;
use Spatie\LaravelData\Data;

class CreateAccountParamsData extends Data
{
    public function __construct(
        public VendorInfo $vendorInfo,
        public int $serialNumber,
        public string $username,
    ) {}
}
