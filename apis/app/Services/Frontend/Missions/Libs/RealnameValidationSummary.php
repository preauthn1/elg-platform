<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\Libs;

use App\Models\Mission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class RealnameValidationSummary implements LibInterface
{
    /**
     * @var string
     */
    private $summaryStartDate;

    /**
     * @var string
     */
    private $summaryEndDate;

    public function exec(Mission $mission, string $summaryStartDate, string $summaryEndDate): Builder
    {
        $this->summaryStartDate = $summaryStartDate;
        $this->summaryEndDate = $summaryEndDate;

        $query = DB::table('realname_validation_requests as rvr')
            ->where('rvr.status', 1)
            ->where('rvr.updated_at', '>=', $this->summaryStartDate)
            ->where('rvr.updated_at', '<', $this->summaryEndDate)
            ->select('rvr.member_id as member_id', DB::raw('1 as progress'))
            ->distinct();

        return $query;
    }
}
