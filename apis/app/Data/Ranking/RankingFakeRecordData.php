<?php

declare(strict_types=1);

namespace App\Data\Ranking;

use Spatie\LaravelData\Data;

class RankingFakeRecordData extends Data
{
    public function __construct(
        public string $username,
        public ?string $image,
        public string $vendor_name,
        public string $game_total_win_lose,
        public string $profit_total_win_lose,
        public ?int $vendor_id = null,
    ) {}
}
