<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Database\QueryException;
use App\TransactionAgent;
use Validator;
use DB;

class ReportController extends Controller
{

    /**
     * report/sr02
     *
     * @bodyParam username string 營運商帳號 Example: abc
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "username": "營運商帳號,ex:abc",
     *            "total_in": "總開分金額,ex:100",
     *            "total_out": "總洗分金額,ex:100"
     *        }
     *    ],
     *  "total": {
     *            "total_in": "總開分金額,ex:100",
     *            "total_out": "總洗分金額,ex:100"
     *    }
     * }
     */
    public function sr02(Request $request)
    {
        try {
            $rules = [
                'username' => 'r_alpha_num|between:0,30',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
            ];

            // 驗證POST資料
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 取開洗分記錄(代理開給會員) 
            $model = DB::table('transactions as tm')->leftJoin('users as um', 'um.id', 'tm.user_id');
            $user_id = ($request->HOST != 0) ? $request->HOST : $request->ID; //如果host不是0就是子帳號
            $child = $this->listChild($user_id);
            // 只能統計自己下線的
            $model = $model->where(function ($query) use ($child, $user_id) {
                $query->whereIn('tm.user_id', $child)
                    ->orWhere('host_id', $user_id);
            });
            // $model = $model->whereIn('tm.user_id', $child)->orWhere('host_id', $user_id);
            if (isset($request->username) && $request->username != '') {
                $model = $model->where('um.username', 'like', '%' . $request->username . '%');
            }
            $model = $model->where('tm.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $model = $model->where('tm.created_at', '<=',  date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $model = $model->where('tm.status', 1)->groupBy('um.username');
            $model = $model->select(DB::raw('um.username, (sum(CASE tm.type WHEN 1 THEN tm.amount ELSE 0 END)+sum(CASE tm.type WHEN 3 THEN tm.amount ELSE 0 END)) as total_in, sum((CASE tm.type WHEN 2 THEN tm.amount ELSE 0 END)) as total_out'));

            // 取開洗分記錄(代理之間) 
            $agent = DB::table('transaction_agents as t')->leftJoin('users as a', 'a.id', 't.from');
            // 只能統計自己下線的
            $agent = $agent->where(function ($query) use ($child, $user_id) {
                $query->whereIn('t.from', $child)
                    ->orWhere('host_id', $user_id);
            });
            // $agent = $agent->whereIn('t.from', $child);
            if (isset($request->username) && $request->username != '') {
                $agent = $agent->where('a.username', 'like', '%' . $request->username . '%');
            }
            $agent = $agent->where('t.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $agent = $agent->where('t.created_at', '<=',  date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $agent = $agent->where('t.status', 1)->groupBy('a.username');
            $agent->select(DB::raw('a.username, (sum(CASE t.type WHEN 1 THEN t.amount ELSE 0 END)) as total_in, sum((CASE t.type WHEN 2 THEN t.amount ELSE 0 END)) as total_out'));
            $data = $model->union($agent);
            $data = DB::table(DB::raw("({$model->toSql()}) t"))->select(DB::raw('username, sum(total_in) as total_in, sum(total_out) as total_out'))
                ->mergeBindings($model)->whereNotNull('username')
                ->groupBy('username')
                ->get();
            //先算總數
            $total = DB::table(DB::raw("({$model->toSql()}) t"))->select(DB::raw('sum(total_in) as total_in, sum(total_out) as total_out'))
                ->mergeBindings($model)
                ->get();


            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'total' => $total
            ]);
        } catch (QueryException $e) {
            DB::rollback();
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function messages()
    {
        return [
            'username.r_alpha_num' => __('error_message.2020'),
            'username.between' => __('error_message.2021'),

            'start_date.required' => __('error_message.2011'),
            'start_date.date_format' => __('error_message.2012'),

            'end_date.required' => __('error_message.2013'),
            'end_date.date_format' => __('error_message.2014'),
        ];
    }
}