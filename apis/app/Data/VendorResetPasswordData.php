<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class VendorResetPasswordData extends Data
{
    public function __construct(
        public string $newPassword,
    ) {}
}
