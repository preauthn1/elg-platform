<?php

declare(strict_types=1);

namespace App\Services\Frontend\Missions\Libs;

use App\Enums\MemberBindingLogTypeEnum;
use App\Models\Mission;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class FillBirthdaySummary implements LibInterface
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

        $query = DB::table('member_binding_logs as mbl')
            ->where('mbl.type', MemberBindingLogTypeEnum::FILL_BIRTHDAY->value)
            ->where('mbl.status', 1)
            ->where('mbl.created_at', '>=', $this->summaryStartDate)
            ->where('mbl.created_at', '<', $this->summaryEndDate)
            ->select('mbl.member_id as member_id', DB::raw('1 as progress'))
            ->distinct();

        return $query;
    }
}
