<?php

namespace App\Repositories;

use App\Models\LuckyDrawTicket;
use DB;

class LuckyDrawTicketRepository extends Repository
{
    /**
     * LuckyDrawTicketRepository constructor.
     * @param LuckyDrawTicket $model
     */
    public function __construct()
    {
        $this->model = new LuckyDrawTicket();
    }

    public function getLuckyDrawTicketCount($lucky_draw)
    {
        return $this->model
            ->where('lucky_draw_id', $lucky_draw->id)
            ->select(
                DB::raw('COUNT(0) as total_ticket_number'),
                DB::raw('SUM(point) as total_bonus_number'),
                DB::raw('CASE WHEN reward_item_id = 1 THEN 1 ELSE 0 END AS reward_level_1'),
                DB::raw('CASE WHEN reward_item_id = 2 THEN 1 ELSE 0 END AS reward_level_2'),
                DB::raw('CASE WHEN reward_item_id = 3 THEN 1 ELSE 0 END AS reward_level_3'),
                DB::raw('CASE WHEN reward_item_id = 4 THEN 1 ELSE 0 END AS reward_level_4')
            )
            ->first();
    }

    public function getLuckyDrawTickets($request)
    {
        $main = DB::table('lucky_draws as d')
            ->join('lucky_draw_tickets as t', function ($join) use ($request) {
                $join->on('d.id', 't.lucky_draw_id');
                if ($request->ticket_number != '') {
                    $join->where('t.ticket_number', 'like', '%' . $request->ticket_number . '%-%');
                }
            })
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 't.member_id');
                if ($request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', 'a.id', 'm.parent')
            ->where('d.no', $request->period)
            ->groupBy('m.id')
            ->orderBy('t.type', 'DESC')
            ->orderBy('t.reward_item_id', 'ASC')
            ->select(
                'a.username as agent_username',
                'm.username as member_username',
                DB::raw('COUNT(0) AS ticket_number'),
                DB::raw('IFNULL(SUM(t.point), 0) AS total_reward_amount')
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as t'))
            ->mergeBindings($main)
            ->select(
                't.agent_username',
                't.member_username',
                't.ticket_number',
                't.total_reward_amount'
            );

        return $model;
    }
}