<?php

declare(strict_types=1);

namespace App\Helpers\Traits;

use App\Models\SummaryEventRecord;
use App\Models\SummaryEventRecordAgent;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

trait Sr11CostTrait
{
    use GetContextTrait;

    public function inserCost(int $eventId, string $point, string $createTime): void
    {
        $summaryTime = Carbon::parse($createTime)->format('Y-m-d H:00:00');
        $member = $this->getUserContext()->member;

        SummaryEventRecord::updateOrInsert(
            [
                'event_id' => $eventId,
                'member_id' => $member->id,
                'summary_time' => $summaryTime,
            ],
            [
                'point' => DB::raw('point + ' . $point),
            ]
        );

        SummaryEventRecordAgent::updateOrInsert(
            [
                'event_id' => $eventId,
                'agent_id' => $member->agent->id,
                'summary_time' => $summaryTime,
            ],
            [
                'point' => DB::raw('point + ' . $point),
            ]
        );
    }
}
