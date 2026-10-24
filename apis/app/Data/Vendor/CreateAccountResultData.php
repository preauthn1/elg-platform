<?php

declare(strict_types=1);

namespace App\Data\Vendor;

use Spatie\LaravelData\Data;

class CreateAccountResultData extends Data
{
    public function __construct(
        public bool $status = false,
        public string $username = '',
    ) {}
}
