<?php

declare(strict_types=1);

namespace App\SP;

use App\Models\Agent;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SP_check_login_event
{
    public static function handle(Member $member, Agent $agent): int
    {
        $memberId = $member->id;
        $agentIds = $agent->hierarchy_array;
        $now = Carbon::now()->toDateTimeString();

        // 查詢符合條件的獎勵數量
        // 獲取當前活動(type_id; 51:首充, 52:每次充, 53:首充, 54:每次充, 55:每日充)
        $totalCount = DB::table('prizes as p')
            ->join('events as e', function ($join) use ($agentIds) {
                $join->on('e.id', '=', 'p.event_id')
                    ->where('e.status', 1)
                    ->whereIn('e.agent_id', $agentIds);
            })
            ->join('event_details as ed', function ($join) use ($now) {
                $join->on('ed.event_id', '=', 'e.id')
                    ->where('ed.status', 1)
                    ->where('ed.start_time', '<=', $now)
                    ->where('ed.end_time', '>=', $now)
                    ->whereIn('ed.action_id', [51, 52, 53, 54, 55]);
            })
            ->join('prize_members as pm', function ($join) use ($memberId) {
                $join->on('pm.prize_id', '=', 'p.id')
                    ->where('pm.member_id', '=', $memberId)
                    ->where('pm.status', '=', 0);
            })
            ->where('p.withdrawal_cancel_reward', 1)
            ->where('p.status', 1)
            ->count();

        return $totalCount;
    }
}
