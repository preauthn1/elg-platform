<?php

declare(strict_types=1);

namespace App\Vendors\Astar\Datas;

use Spatie\LaravelData\Data;

class AstarVendorInfoDetail extends Data
{
    public function __construct(
        public string $GAME_URL,
        public string $CS_TOKEN,
        public string $GAMEHALL,
        public string $CURRENCY,
    ) {
        $this->GAME_URL = trim($this->GAME_URL, '/');
    }
}
