<?php

declare(strict_types=1);

namespace App\Data\Vendor;

use Spatie\LaravelData\Data;

class GetGameListResultData extends Data
{
    public function __construct(
        public array $gameList,
    ) {}
}
