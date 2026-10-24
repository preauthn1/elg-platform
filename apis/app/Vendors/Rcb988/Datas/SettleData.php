<?php

declare(strict_types=1);

namespace App\Vendors\Rcb988\Datas;

use Spatie\LaravelData\Data;

class SettleData extends Data
{
    public function __construct(
        public string $platformTxId,
        public string $userId,
        public string $platform,
        public ?string $refPlatformTxId,
        public string $settleType,
        public string $gameType,
        public string $gameCode,
        public string $gameName,
        public string $betType,
        public string $winAmount,
        public string $turnover, // Valid bet
        public string $betTime,
        public string $txTime, // 结算时间
        public string $roundId,
        public array $gameInfo,
    ) {}
}
