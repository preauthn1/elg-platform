<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988\Datas;

use Spatie\LaravelData\Data;

class BetData extends Data
{
    public function __construct(
        public string $platformTxId,
        public string $userId,
        public string $currency,
        public string $platform,
        public string $gameType,
        public string $gameCode,
        public string $gameName,
        public string $betType,
        public string $betAmount,
        public string $betTime,
        public string $roundId,
        public array $gameInfo,
    ) {}
}
