<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988;

use Spatie\LaravelData\Data;

class Rcb988VendorInfoDetail extends Data
{
    public function __construct(
        public string $AUTH_URL,
        public string $CLIENT_ID,
        public string $CLIENT_SECRET,
        public string $CLIENT_GAME_PLATFORM,
        public string $CLIENT_GAME_TYPE,
        public string $CLIENT_GAME_CODE,
        public string $CLIENT_CLASS,
        public string $CLIENT_MAJOR_MIN_BET,
        public string $CLIENT_MINOR_MIN_BET,
        public string $CLIENT_MAJOR_MAX_BET,
        public string $CLIENT_MINOR_MAX_BET,
        public string $CLIENT_MAJOR_MAX_BET_SUM_PER_HORSE,
        public string $CLIENT_MINOR_MAX_BET_SUM_PER_HORSE,
        public string $CURRENCY,
    ) {}
}
