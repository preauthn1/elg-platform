<?php

declare(strict_types=1);

namespace App\Data\Ranking;

use Illuminate\Support\Collection;
use Spatie\LaravelData\Data;

class RankingFakeData extends Data
{
    public function __construct(
        public int $last_update,

        /**
         * @var Collection<RankingFakeRecordData>
         */
        public Collection $data,
    ) {}
}
