<?php

namespace App\Repositories;

use App\Models\TransactionLog;
use DB;

class TransactionLogRepository extends Repository
{
    /**
     * TransactionLogRepository constructor.
     * @param TransactionLog $model
     */
    public function __construct()
    {
        $this->model = new TransactionLog();
    }

    public function getSr14Report($request)
    {
        $model = DB::table('transaction_logs as t')
            ->join('members as m', function($join) use ($request) {
                $join->on('m.id', 't.member_id')
                    ->where('m.username', $request->member_username);
            })
            ->join('agents as a', function($join) use ($request) {
                $join->on('a.id', 'm.parent')
                    ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            })
            ->leftJoin('vendors as v', 'v.id', 't.vendor_id')
            ->where('t.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('t.created_at', '<', date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0, -2) . '59-8hour')))
            ->select(
                DB::raw('DATE_ADD(t.created_at,INTERVAL 8 HOUR) as created_at'),
                DB::raw('m.username as member_username'),
                DB::raw('a.username as agent_username'),
                't.before_balance',
                DB::raw('CASE WHEN t.type = 1 THEN t.point * -1 WHEN t.type = 5 THEN t.point * -1 WHEN t.type = 6 THEN (t.point + t.tax) * -1 WHEN t.type = 16 THEN t.point * -1 ELSE (t.point + t.tax) END AS point'),
                't.balance',
                't.type',
                't.status',
                DB::raw('v.name as vendor_name'),
                't.remark'
            );

        return $model;
    }
}
