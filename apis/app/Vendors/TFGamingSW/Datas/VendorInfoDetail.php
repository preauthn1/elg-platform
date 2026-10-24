<?php

declare(strict_types=1);

namespace App\Vendors\TFGamingSW\Datas;

use Spatie\LaravelData\Data;

class VendorInfoDetail extends Data
{
    public function __construct(
        public string $API_URL,
        public string $GAME_URL,
        public string $OPERATOR_ID,
        public string $PUBLIC_TOKEN,
        public string $PRIVATE_TOKEN,
        public string $DEFAULT_LIMIT_AMOUNT,
    ) {}
}
