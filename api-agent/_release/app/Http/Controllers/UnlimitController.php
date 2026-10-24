<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use App\Helpers\Common;
use App\Agent;
use App\Member;
use App\REDIS;
use App\Models\UnlimitWithdrawLog;
use App\Models\UnlimitBonusreportAgent;
use App\Models\TransactionLogAgent;
use Validator;
use DB;

class UnlimitController extends Controller
{
    /**
     * unlimit/memberSearch
     * - 用戶查詢
     *
     * @bodyParam agent string 代理帳號 Example: agent
     * @bodyParam member string 會員帳號 Example: user
     * @bodyParam page integer 頁 Example: 1
     * @bodyParam per_page integer 每頁筆數 Example: 20
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號,ex:1"
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     * }
     *
     */
    public function getMember(Request $request)
    {
        try {
            $request->per_page = isset($request->per_page)? $request->per_page : 20;
            $request->page = isset($request->page)? $request->page : 1;

            //查會員
            $member = ($request->member != '')
                ? Member::where('username', $request->member)->first()->id
                : null;

            //查代理
            $agent_id = ($request->agent != '')
                ? Agent::where('username', $request->agent)
                    ->where('hierarchy', 'like', '%,' . $request->HOST . ',%')
                    ->first()
                    ->id
                : $request->HOST;

            //宣告output parameter
            $paramCount = '@o_total_count';
            //取資料
            $data = DB::select('CALL SP_get_unlimit_summary_report(?, ?, ?, ?, ' . $paramCount . ')',
                array(
                    $agent_id,
                    $member,
                    $request->page,
                    $request->per_page
                ));
            //取筆數
            $output = DB::select(DB::raw('select ' . $paramCount . ' as total'));
            $total = (is_null($output[0]->total)) ? 0 :$output[0]->total;
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (QueryException $e) {
            dd($e);
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

    /**
     * unlimit/agentSearch
     * - 原代理業績
     *
     * @bodyParam agent string 代理帳號 Example: agent
     * @bodyParam start_time datetime 起啟時間 Example: '2020-05-20 00:00:00'
     * @bodyParam end_time datetime 結束時間 Example: '2020-05-20 23:59:59'
     * @bodyParam page integer 頁 Example: 1
     * @bodyParam per_page integer 每頁筆數 Example: 20
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號,ex:1"
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     * }
     *
     */
    public function getAgent(Request $request)
    {
        try {
            $request->page = isset($request->page)? $request->page : 1;
            $request->per_page = isset($request->per_page)? $request->per_page : 20;

            $request->start_time = isset($request->start_time) ? date($request->start_time, strtotime('-8hour')) : date('Y-m-d 00:00:00');
            $request->end_time = isset($request->end_time) ? date($request->end_time, strtotime('-8hour')) : date('Y-m-d 23:59:59');

            $model = DB::table('unlimit_bonusreport_agents as u')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'u.agent_id')
                        ->where('hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->whereBetween('u.created_at', [$request->start_time, $request->end_time]);
            if ($request->agent != '')
            {
                $model = $model->where('a.username', $request->agent);
            }
            $total = $model->count();
            $data = $model
                ->orderby('u.created_at', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'a.username',
                    DB::raw('IFNULL(CONVERT((u.performance_direct + u.performance_team), decimal(16,2)), 0) as performance_total'),
                    DB::raw('IFNULL(CONVERT(u.performance_direct, decimal(16,2)), 0) as performance_direct'),
                    DB::raw('IFNULL(CONVERT(u.performance_team, decimal(16,2)), 0) as performance_team'),
                    DB::raw('IFNULL(CONVERT((u.bonus_direct + u.bonus_team), decimal(16,2)), 0) as bonus_total'),
                    DB::raw('IFNULL(CONVERT(u.bonus_direct, decimal(16,2)), 0) as bonus_direct'),
                    DB::raw('IFNULL(CONVERT(u.bonus_team, decimal(16,2)), 0) as bonus_team'),
                    DB::raw('DATE_ADD(u.created_at, INTERVAL 8 HOUR) as summary_date')
                )
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (QueryException $e) {
            dd($e);
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

    /**
     * unlimit/record
     * - 提現紀錄
     *
     * @bodyParam member string 玩家編號 Example: 'user'
     * @bodyParam order_id string 提單編號 Example: 11
     * @bodyParam start_time datetime 起啟時間 Example: '2020-05-20 00:00:00'
     * @bodyParam end_time datetime 結束時間 Example: '2020-05-20 23:59:59'
     * @bodyParam page integer 頁 Example: 1
     * @bodyParam per_page integer 每頁筆數 Example: 20
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號,ex:1"
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     * }
     *
     */
    public function getWithdrawalRecord(Request $request)
    {
        try {
            $model = new UnlimitWithdrawLog();

            // 驗證POST資料
            /*
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            */

            $request->per_page = isset($request->per_page)? $request->per_page : 20;
            $request->page = isset($request->page)? $request->page : 1;

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time.'-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time.'-8hour'));

            $model = DB::table('unlimit_withdrawlog as uw')
                ->join('members as m', 'm.id', 'uw.member_id');

            $model = $model->where('uw.created_at', '>=', $start_time)
                ->where('uw.created_at', '<=', $end_time);

            if (isset($request->order_no) && $request->order_no != '') {
                $model = $model->where('uw.id', $request->order_no);
            }
            if (isset($request->member) && $request->member != '') {
                $model = $model->where('m.username', $request->member);
            }
            $total = $model->count();

            $data = $model
                ->orderBy('uw.created_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'uw.id',
                    'uw.order_no',
                    'm.username',
                    'uw.reward_point',
                    'uw.withdrawn_point',
                    DB::raw('convert((uw.reward_point - uw.withdrawn_point), decimal(16, 2)) as remaining_reward_points'),
                    'uw.amount',
                    DB::raw('DATE_ADD(uw.created_at, INTERVAL 8 HOUR) as created_at'),
                    DB::raw('DATE_ADD(uw.updated_at, INTERVAL 8 HOUR) as updated_at'),
                    'uw.type',
                    'uw.remark',
                    'uw.admin'
                )
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (QueryException $e) {
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

    /**
     * unlimit/transactionList
     * - 原代理獎勵紀錄
     *
     * @bodyParam member string 玩家編號 Example: 'user'
     * @bodyParam order_id string 提單編號 Example: 11
     * @bodyParam start_time datetime 起啟時間 Example: '2020-05-20 00:00:00'
     * @bodyParam end_time datetime 結束時間 Example: '2020-05-20 23:59:59'
     * @bodyParam page integer 頁 Example: 1
     * @bodyParam per_page integer 每頁筆數 Example: 20
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": "系統編號,ex:1"
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     * }
     *
     */
    public function transactionList(Request $request)
    {
        try {
            $request->per_page = isset($request->per_page)? $request->per_page : 20;
            $request->page = isset($request->page)? $request->page : 1;

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time.'-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time.'-8hour'));

            $model = DB::table('transaction_log_agents as t')
                ->join('agents as a', function($join) use ($request) {
                    $join->on('a.id', 't.from_agent_id')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->join('agents as b', function($join) use ($request) {
                    $join->on('b.id', 't.to_agent_id');
                    if ($request->username != '') {
                        $join->where('b.username', $request->username);
                    }
                })
                ->where('type', 3)
                ->whereBetween('t.created_at', [$start_time, $end_time]);

            $total = $model->count();
            $data = $model
                ->orderBy('t.created_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    't.id',
                    DB::raw('DATE_ADD(t.created_at, INTERVAL 8 HOUR) as created_at'),
                    'b.username',
                    'b.nickname',
                    't.amount',
                    't.to_limit_of_credit',
                    't.operator',
                    't.status',
                    't.remark'
                )
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (QueryException $e) {
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
            'start_time.required' => __('error_message.1311'),
            'start_time.datetime' => __('error_message.1311'),

            'end_time.required' => __('error_message.1312'),
            'end_time.datetime' => __('error_message.1312'),
        ];
    }
}