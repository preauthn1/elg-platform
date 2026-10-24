<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class VendorGameUrlResultAppInfoData extends Data
{
    public function __construct(
        public string $username,
        public ?string $password = [REDACTED],

        public array $ios_downloads = [],
        public ?string $ios_open = null,

        public array $android_downloads = [],
        public ?string $android_open = null,

        public ?string $app_icon = null,
    ) {}
}
