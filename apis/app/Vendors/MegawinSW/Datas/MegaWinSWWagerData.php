<?php

declare(strict_types=1);

namespace App\Vendors\MegawinSW\Datas;

use Carbon\Carbon;
use Spatie\LaravelData\Data;

class MegaWinSWWagerData extends Data
{
    public function __construct(
        public int $gameId,
        public string $gameClass,
        public string $risk,
        public string $gameRoundId,
        public string $wagerId,
        public float $gold,
        public string $result,
        public string $orderDate,
        public string $addDate,
        public string $resultDate,
        public string $utoken,
        public string $uid,
        public string $ip,
        public string $device,
        public ?string $freegame = null,

        public float $vgold = 0.000,
        public float $winGold = 0.000,
        public float $unusedGold = 0.000,
        public float $commissionGold = 0.000,
    ) {
        $this->orderDate = Carbon::parse($this->orderDate)->toSystemTime()->format('Y-m-d H:i:s');
        $this->addDate = Carbon::parse($this->addDate)->toSystemTime()->format('Y-m-d H:i:s');

        $this->resultDate = $this->resultDate && $this->resultDate !== '0000-00-00 00:00:00' ?
            Carbon::parse($this->resultDate)->toSystemTime()->format('Y-m-d H:i:s') :
            $this->addDate;
    }
}
