<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\Libs;

use App\Models\Mission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class BindBankCardSummary implements LibInterface
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

        $query = DB::table('member_bank_accounts as mba')
            ->where('mba.status', 1)
            ->where('mba.created_at', '>=', $this->summaryStartDate)
            ->where('mba.created_at', '<', $this->summaryEndDate)
            ->select('mba.member_id as member_id', DB::raw('1 as progress'))
            ->distinct();

        return $query;
    }
}
