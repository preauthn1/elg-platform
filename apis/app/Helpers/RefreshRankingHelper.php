<?php

declare(strict_types=1);

namespace App\Helpers;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Models\EventRefreshRankingRecord;
use App\Models\Member;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class RefreshRankingHelper
{
    public static function checkEventRecord(Member $member): bool
    {
        $event = self::getEventRecord($member);

        if (empty($event)) {
            return false;
        }

        $record = EventRefreshRankingRecord::where('event_refresh_ranking_id', $event['id'])
            ->where('member_id', $member->id)
            ->first();
        if (!is_object($record)) {
            if (is_null($member->mobile) || is_null($member->first_name)) {
                return false;
            }

            $members = DB::table('members')->where(function ($query) use ($member) {
                $query->where('mobile', $member->mobile)
                    ->orWhere('first_name', $member->first_name)
                    ->orWhere('last_login_ip', $member->last_login_ip);
            })
                ->where('id', '!=', $member->id)
                ->pluck('id')
                ->toArray();
            $check = EventRefreshRankingRecord::where('event_refresh_ranking_id', $event['id'])
                ->whereIn('member_id', $members)
                ->get();
            $is_calc = (count($check) > 0) ? 0 : 1;
            EventRefreshRankingRecord::create([
                'event_refresh_ranking_id' => $event['id'],
                'member_id' => $member->id,
                'last_bet_time' => date('Y-m-d H:i:s'),
                'is_calc' => $is_calc,
            ]);
        }

        return true;
    }

    private static function getEventRecord(Member $member): array
    {
        $date = date('Y-m-d HH:00:00');
        $cacheKey = CacheKeys::externalRefreshRankingRecord($member->parent, $date);

        $event = Cache::remember($cacheKey, CacheTtl::ONE_HOUR->value, function () use ($member) {
            $parents = $member->agent->hierarchy_array;
            $event = DB::table('events as e')
                ->join('event_details as ed', function ($join) {
                    $join->on('ed.event_id', 'e.id')
                        ->where('ed.start_time', '<=', date('Y-m-d H:i:s'))
                        ->where('ed.end_time', '>', date('Y-m-d H:i:s'))
                        ->where('ed.status', 1)
                        ->where('ed.action_id', 13);
                })
                ->join('event_refresh_rankings as err', function ($join) {
                    $join->on('err.event_id', 'e.id')
                        ->on('err.event_detail_id', 'ed.id');
                })
                ->whereIn('e.agent_id', $parents)
                ->where('e.status', 1)
                ->select('err.id as id', 'ed.point as point', 'ed.end_time as end_time')
                ->first();

            if (!is_object($event)) {
                return [];
            }

            return (array) $event;
        });

        return $event;
    }
}
