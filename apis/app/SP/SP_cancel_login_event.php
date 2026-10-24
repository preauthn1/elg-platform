<?php

declare(strict_types=1);

namespace App\SP;

use App\Models\Agent;
use App\Models\Member;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 洗分時, 取消登入獎勵
 */
class SP_cancel_login_event
{
    public static function handle(Member $member, Agent $agent): int
    {
        $agentIds = $agent->hierarchy_array;
        $now = Carbon::now()->toDateTimeString();

        // 獲取當前活動(type_id; 51:首充, 52:每次充, 53:首充, 54:每次充, 55:每日充)
        $prizes = DB::table('prizes as p')
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
            ->where('p.withdrawal_cancel_reward', 1)
            ->where('p.status', 1)
            ->select('p.id')
            ->get();

        if ($prizes->isEmpty()) {
            return 0;
        }

        // 將現有登入獎勵取消
        $count = DB::table('prize_members')
            ->whereIn('prize_id', $prizes->pluck('id'))
            ->where('member_id', $member->id)
            ->where('status', 0)
            ->update(['status' => 3]);

        return $count;
    }
}
