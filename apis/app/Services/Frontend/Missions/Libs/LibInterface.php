<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\Libs;

use App\Models\Mission;
use Illuminate\Database\Query\Builder;

interface LibInterface
{
    public function exec(Mission $mission, string $summaryStartDate, string $summaryEndDate): Builder;
}
