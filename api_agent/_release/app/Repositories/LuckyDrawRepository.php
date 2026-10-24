<?php

namespace App\Repositories;

use App\Models\LuckyDraw;
use DB;

class LuckyDrawRepository extends Repository
{
    /**
     * LuckyDrawRepository constructor.
     * @param LuckyDraw $model
     */
    public function __construct()
    {
        $this->model = new LuckyDraw();
    }

    public function getLuckyDrawPeriod()
    {
        $data = $this->model::from('lucky_draws as ld')
            ->join('events as e', 'e.id', 'ld.event_id')
            ->join('event_details as ed', function ($join) {
                $join->on('ed.id', 'ld.event_detail_id')
                    ->whereRaw('ed.start_time <= now()');
            })
            ->select(
                'ld.id',
                'ld.no',
                DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) AS start_time'),
                DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) AS end_time')
            )
            ->orderBy('ld.no', 'DESC')
            ->get();
        return $data;
    }

    public function getLuckyDrawByPeriod($request)
    {
        $data = $this->model::from('lucky_draws as ld')
            ->join('events as e', 'e.id', 'ld.event_id')
            ->join('event_details as ed', 'ed.id', 'ld.event_detail_id')
            ->where('ld.no', $request->period)
            ->select(
                'ld.id',
                'ld.no',
                DB::raw('ed.start_time AS utc_start_time'),
                DB::raw('ed.end_time AS utc_end_time'),
                DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) AS start_time'),
                DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) AS end_time'),
                DB::raw('DATE_ADD(ld.draw_time, INTERVAL 8 HOUR) AS draw_time'),
                DB::raw("CASE WHEN ld.draw_number IS NOT NULL THEN ld.draw_number ELSE '------' END AS draw_number"),
                DB::raw("CASE ld.status WHEN 0 THEN '未派發' WHEN 1 THEN '已派發' WHEN 3 THEN '已取消' ELSE '派獎中' END AS status")
            )
            ->first();
        return $data;
    }
}
