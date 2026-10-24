<?php
namespace App\Repositories;

use DB;
use http\Exception\InvalidArgumentException;

class RedEnvelopeRepository
{
    /**
     * @param $user_ids=>在會員系統的代理編號
     * @param $username=>會員帳號
     * @param $start_time=>開始時間
     * @param $end_time=>結束時間
     * @param $page=>頁數,
     * @param $per_page=>一頁幾筆
     * @return array
     * @throws InvalidArgumentException
     */
    public function SearchRecordsPagination(array $user_ids, $username, $start_time, $end_time, $page=1, $per_page=20) {
        if(isset($start_time) && isset($end_time) && $start_time != '' && $end_time != '') {
            $model = DB::connection('cs')
                ->table('red_envelopes as s')
                ->leftJoin('room_members as r', function($join) {
                    $join->on('s.room_id', 'r.room_id');
                    $join->on('s.member_id','!=', 'r.member_id');
                })
                ->leftJoin('members as sm', 'sm.id', 's.member_id')
                ->leftJoin('members as rm', 'rm.id', 'r.member_id')
                ->select(
                    's.id',
                    DB::raw('DATE_ADD(s.created_at,INTERVAL 8 HOUR) as created_at'),
                    DB::raw('1 as type'), 'sm.username as self_username',
                    'rm.username as other_username',
                    's.amount',
                    's.status'
                )
                ->whereBetween(
                    's.created_at',
                    [
                        date('Y-m-d H:i:s', strtotime($start_time . '-8hour')),
                        date('Y-m-d H:i:s', strtotime($end_time . '-8hour'))
                    ]
                );

            if (isset($username) && $username != '') {
                $model = $model->where( function ($join) use ($username) {
                    $join->where('sm.username', $username)
                        ->orWhere('rm.username', $username);
                });
            }

            $model = $model->where( function($join) use($user_ids) {
               $join->whereIn('sm.user_id', $user_ids)
                   ->orWhereIn('rm.user_id', $user_ids);
            });

            $total = $model->getCountForPagination();
            $data = $model->orderBy('s.created_at', 'desc')
                ->skip(($page - 1) * $per_page)
                ->take($per_page)
                ->get();

            $pagination = array();
            $pagination['per_page'] = $per_page;
            $pagination['total'] = $total;
            $pages = ceil($total / $per_page);
            $pagination['pages'] = $pages;
            $pagination['previous'] = ($page != 1 && $total > 0) ? ($page - 1) : '';
            $pagination['current'] = $page;
            $pagination['next'] = ($pages > $page) ? ($page + 1) : '';
            return ['data' => $data, 'pagination' => $pagination];
        } else {
            abort(500, "Invalid argument");
        }
    }
}