<?php

declare(strict_types=1);

namespace App\Data;

use Spatie\LaravelData\Data;

class AdvertiseImageData extends Data
{
    public function __construct(
        public int $advertiseId,
        public ?string $imageUrl,
        public ?string $imagePcUrl,
    ) {}
}
