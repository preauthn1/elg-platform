<?php

declare(strict_types=1);

namespace App\Vendors\SpadegamingSW;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

class TransferDataDTO extends Data
{
    public function __construct(
        public string $transferId,
        public string $acctId,
        public string $currency,
        public float $amount,
        public int $type,
        public string $channel,
        public string $gameCode,
        public string $transferTime,
        public ?array $specialGame = null,
        public ?string $referenceId = '',
    ) {
        $time = Carbon::parse($transferTime, new \DateTimeZone('+0800'))->toSystemTime();
        $this->transferTime = $time->format('Y-m-d H:i:s');
    }
}
