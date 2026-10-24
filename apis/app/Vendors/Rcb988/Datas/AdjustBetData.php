<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988\Datas;

use Spatie\LaravelData\Data;

class AdjustBetData extends Data
{
    public function __construct(
        public string $platformTxId,
        public string $userId,
        public string $platform,
        public string $gameType,
        public string $gameCode,
        public string $gameName,
        public string $betAmount,
        public string $adjustAmount,
        public string $updateTime,
        public string $roundId,
        public array $gameInfo,
    ) {}
}
