<?php

namespace App\Repositories;

use App\Models\WJackpot;
use DB;

class WJackpotRepository extends Repository
{
    /**
     * WJackpotRepository constructor.
     * @param LuckyDraw $model
     */
    public function __construct()
    {
        $this->model = new WJackpot();
    }

    public function getWJackpotPeriod()
    {
        $data = $this->model::from('w_jackpots as wj')
            ->join('events as e', 'e.id', 'wj.event_id')
            ->join('event_details as ed', function ($join) {
                $join->on('ed.id', 'wj.event_detail_id')
                    ->whereRaw('ed.start_time <= now()');
            })
            ->select(
                'wj.id',
                'wj.no',
                DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) AS start_time'),
                DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) AS end_time')
            )
            ->orderBy('wj.no', 'DESC')
            ->limit(30)
            ->get();
        return $data;
    }

    public function getWJackpotByPeriod($request)
    {
        $data = $this->model::from('w_jackpots as wj')
            ->join('events as e', 'e.id', 'wj.event_id')
            ->join('event_details as ed', 'ed.id', 'wj.event_detail_id')
            ->where('wj.no', $request->period)
            ->select(
                'wj.id',
                'wj.no',
                DB::raw('ed.start_time AS utc_start_time'),
                DB::raw('ed.end_time AS utc_end_time'),
                DB::raw('DATE_ADD(ed.start_time, INTERVAL 8 HOUR) AS start_time'),
                DB::raw('DATE_ADD(ed.end_time, INTERVAL 8 HOUR) AS end_time'),
                DB::raw('DATE_ADD(wj.draw_time, INTERVAL 8 HOUR) AS draw_time'),
                DB::raw("CASE WHEN wj.draw_number IS NOT NULL THEN wj.draw_number ELSE '----' END AS draw_number"),
                DB::raw("CASE WHEN wj.draw_number_2 IS NOT NULL THEN wj.draw_number_2 ELSE '----' END AS draw_number_2"),
                DB::raw("CASE WHEN wj.draw_number_3 IS NOT NULL THEN wj.draw_number_3 ELSE '----' END AS draw_number_3"),
                DB::raw("CASE WHEN wj.draw_number_4 IS NOT NULL THEN wj.draw_number_4 ELSE '----' END AS draw_number_4"),
                DB::raw("CASE WHEN wj.draw_number_5 IS NOT NULL THEN wj.draw_number_5 ELSE '----' END AS draw_number_5"),
                DB::raw("CASE wj.status WHEN 0 THEN '未派發' WHEN 1 THEN '已派發' WHEN 3 THEN '已取消' ELSE '派獎中' END AS status")
            )
            ->first();
        return $data;
    }
}
