<?php

declare(strict_types=1);

namespace App\Data\Ranking;

use Spatie\LaravelData\Data;

class RankingGameWinnerData extends Data
{
    public function __construct(
        public string $username,
        public ?string $image,
        public int $total_win_lose,
        public string $vendor_name,
    ) {}
}
