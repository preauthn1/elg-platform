<?php

declare(strict_types=1);

namespace App\Data;

use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

class VendorBonusCodeRequestData extends Data
{
    public function __construct(
        ?Carbon $startAt = null,
        ?Carbon $endAt = null,
    ) {}
}
