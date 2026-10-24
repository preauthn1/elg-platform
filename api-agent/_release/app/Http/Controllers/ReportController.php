<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Order;
use App\TransactionLog;
use App\Agent;
use App\Member;
use App\REDIS;
use App\AgentChild;
use App\SummaryBetRecord;
use Validator;
use DB;
use Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

class ReportController extends Controller
{

    /**
     * report/deposit
     * -儲值報表(只能看到自己下面的營運商資料)
     *
     * @bodyParam agent_id integer 營運商系統編號 Example: 1
     * @bodyParam member_id integer 會員系統編號 Example: 1
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "type": "支付方式編號,ex:1",
     *            "total": "總金額,ex:100",
     *            "date": "日期,ex:2019-04-01"
     *        },
     *        {
     *            "type": 1,
     *            "total": 200,
     *            "date": "2019-04-02"
     *        }
     *    ]
     * }
     */
    public function deposit(Request $request)
    {
        try {
            $model = new Order();
            $validator = Validator::make($request->all(), $this->rule(), $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 驗證是否有權限查看此營運商的資料
            if (!($this->isChild($request->HOST, $request->id) || $request->id == '')) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
                ]);
            }
            // Redis名稱
            $key = array(
                'deposit',
                'list',
                $request->agent_id,
                $request->member_id,
                $request->start_date,
                $request->end_date
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $model = $model->leftJoin('members as m', 'm.id', '=', 'orders.member_id');
                if ($request->agent_id != null) {
                    $model = $model->where('parent', $request->agent_id);
                } else {
                    // 只能統計自己下線的
                    $model = $model->whereIn('parent', $this->listChild($request->ID));
                }
                if ($request->member_id != '' && isset($request->member_id)) {
                    $model = $model->where('orders.member_id', $request->member_id);
                }
                $model = $model->where('orders.created_at', '>=', $request->start_date . ' 00:00:00');
                $model = $model->where('orders.created_at', '<=', $request->end_date . ' 23:59:59');
                $model = $model->where('orders.status', 1);
                $data = $model->select(DB::raw('orders.order_type_id as type,sum(orders.amount) as total,DATE(orders.created_at) as date'))
                    ->groupBy(DB::raw('orders.order_type_id,DATE(orders.created_at)'))
                    ->get();
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/transaction
     * -轉點報表(只能看到自己下面的營運商資料)
     *
     * @bodyParam agent_id integer 營運商系統編號 Example: 1
     * @bodyParam member_id integer 會員系統編號 Example: 1
     * @bodyParam type_id integer required 轉點類型(1:轉入,2:轉出,3:儲值) Example: 1
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "type": "如果有type_id就依遊戲商分類，其他用轉點類型分,ex:1",
     *            "total": "總金額,ex:100",
     *            "date": "日期,ex:2019-04-01"
     *        },
     *        {
     *            "type": 1,
     *            "total": 200,
     *            "date": "2019-04-02"
     *        }
     *    ]
     * }
     */
    public function transaction(Request $request)
    {
        try {
            $model = new TransactionLog();
            $validator = Validator::make($request->all(), $this->rule(), $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 驗證是否有權限查看此營運商的資料
            if (!($this->isChild($request->ID, $request->id) || $request->ID == $request->id || $request->id == '')) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
                ]);
            }
            // Redis名稱
            $key = array(
                'transaction',
                'list',
                $request->agent_id,
                $request->member_id,
                $request->type_id,
                $request->start_date,
                $request->end_date
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $model = $model->leftJoin('members as m', 'm.id', '=', 'transaction_logs.member_id');
                if ($request->agent_id != null) {
                    $model = $model->where('parent', $request->agent_id);
                } else {
                    // 只能統計自己下線的
                    $model = $model->leftJoin('agents as a', 'a.id', 'm.parent');
                    $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    // $model = $model->whereIn('parent', $this->listChild($request->ID));
                }
                if ($request->member_id != '' && isset($request->member_id)) {
                    $model = $model->where('transaction_logs.member_id', $request->member_id);
                }
                $model = $model->where('transaction_logs.created_at', '>=', $request->start_date . ' 00:00:00');
                $model = $model->where('transaction_logs.created_at', '<=', $request->end_date . ' 23:59:59');
                $model = $model->where('transaction_logs.status', 1000);
                if ($request->type_id != '' && isset($request->type_id)) {
                    $model = $model->where('transaction_logs.type', $request->type_id);
                    $data = $model->select(DB::raw('transaction_logs.vendor_id as type,sum(transaction_logs.point) as total,DATE(transaction_logs.created_at) as date'))
                        ->groupBy(DB::raw('transaction_logs.vendor_id,DATE(transaction_logs.created_at)'))
                        ->get();
                } else {
                    $data = $model->select(DB::raw('transaction_logs.type,sum(transaction_logs.point) as total,DATE(transaction_logs.created_at) as date'))
                        ->groupBy(DB::raw('transaction_logs.type,DATE(transaction_logs.created_at)'))
                        ->get();
                }
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/register
     * -註冊報表(只能看到自己下面的營運商資料)
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "total": "總人數,ex:100",
     *            "date": "日期,ex:2019-04-01"
     *        },
     *        {
     *            "total": 200,
     *            "date": "2019-04-02"
     *        }
     *    ]
     * }
     */
    public function register(Request $request)
    {
        try {
            $model = new Member();
            $validator = Validator::make($request->all(), $this->rule(), $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 驗證是否有權限查看此營運商的資料
            if (!($this->isChild($request->ID, $request->id) || $request->ID == $request->id || $request->id == '')) {
                return response()->json([
                    'code' => 9003,
                    'message' => __('error_message.9003')
                ]);
            }
            // Redis名稱
            $key = array(
                'register',
                'list',
                $request->agent_id,
                $request->start_date,
                $request->end_date
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                if ($request->agent_id != null) {
                    $model = $model->where('members.parent', $request->agent_id);
                } else {
                    // 只能統計自己下線的
                    // 只能統計自己下線的
                    $model = $model->leftJoin('agents as a', 'a.id', 'members.parent');
                    $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    // $model = $model->whereIn('parent', $this->listChild($request->ID));
                }
                $model = $model->where('members.created_at', '>=', $request->start_date . ' 00:00:00');
                $model = $model->where('members.created_at', '<=', $request->end_date . ' 23:59:59');
                $data = $model->select(DB::raw('count(members.id) as total,DATE(members.created_at) as date'))
                    ->groupBy(DB::raw('DATE(members.created_at)'))
                    ->get();
                $redis->set($key, $data);
            } else {
                $data = $redis->get($key);
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/numberOfPeople
     * -營運商下線/會員人數
     *
     * @bodyParam agent_id integer required 營運商系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *            "agent": "下線人數,ex:10",
     *            "member": "會員人數,ex:200"
     *   }
     * }
     */
    public function numberOfPeople(Request $request)
    {
        try {
            $rules = array(
                'agent_id' => 'required|digits_between:0,10'
            );
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $agents = Agent::where('parent', $request->agent_id)->count();
            $members = Member::where('parent', $request->agent_id)->count();
            $data = [
                "agent" => $agents,
                "member" => $members
            ];
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr02Agent, report/sr03Agent
     * -SR02, SR03Agent
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam source integer 來源0:全部1:客服2:線上支付 Example: 0
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_username": "營運商帳號,ex:abc",
     *            "total_in": "總開分金額,ex:100",
     *            "total_out": "總洗分金額,ex:100"
     *        }
     *    ]
     * }
     */
    public function sr02Agent(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'source' => 'in:0,1,2',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 取開洗分記錄            
            $model = DB::table('transaction_logs as l')->leftJoin('members as m', 'm.id', 'l.member_id')->leftJoin('agents as a', 'a.id', 'm.parent');
            // 只能統計自己下線的
            $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            } else if (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username', $request->member_username);
            }
            if (isset($request->source) && $request->source != '') {
                switch ($request->source) {
                    case 1: // 客服開洗分
                        $model = $model->whereIn('type', [4, 5, 10, 11, 12]);
                        break;
                    case 2: // 線上儲值
                        $model = $model->where('type', 3);
                        break;
                }
            } else {
                $model = $model->whereIn('type', [3, 4, 5, 10, 11, 12]);
            }
            $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $model = $model->where('l.status', 1000);
            $data = $model->select(DB::raw('a.username as agent_username, (sum((CASE l.type WHEN 3 THEN l.point ELSE 0 END))+sum((CASE l.type WHEN 4 THEN l.point ELSE 0 END))) as total_in, sum((CASE l.type WHEN 5 THEN l.point ELSE 0 END)) as total_out'))
                ->groupBy(DB::raw('a.id'))
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr02Member
     * -SR02 Member
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam source integer required 來源0:全部1:客服2:線上支付 Example: 0
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "會員系統編號,ex:1",
     *            "created_at": "日期,ex:2019-01-01 00:00:00",
     *            "member_username": "會員帳號,ex:abc",
     *            "source": "來源1:客服2:線上支付,ex:1",
     *            "type": "1:開分,2:洗分,ex:1",
     *            "point": "點數,ex:100"
     *        }
     *    ]
     * }
     */
    public function sr02Member(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'source' => 'in:0,1,2',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 取開洗分記錄            
            $model = DB::table('transaction_logs as l')->leftJoin('members as m', 'm.id', 'l.member_id')->leftJoin('agents as a', 'a.id', 'm.parent');
            // 只能統計自己下線的
            $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('a.username',  $request->agent_username);
            } else if (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username',  $request->member_username);
            }
            if (isset($request->source) && $request->source != '') {
                switch ($request->source) {
                    case 1: // 客服開洗分
                        $model = $model->whereIn('type', [4, 5, 10, 11, 12]);
                        break;
                    case 2: // 線上儲值
                        $model = $model->where('type', 3);
                        break;
                }
            } else {
                $model = $model->whereIn('type', [3, 4, 5, 10, 11, 12]);
            }
            $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $model = $model->where('l.status', 1000);
            //類型3:訂單儲值,4:開分,5:洗分,10:開分(贈點),11:拒絕洗分,12:退還洗分
            $source = DB::raw("CASE l.type WHEN 3 then '線上支付' WHEN 4 THEN '客服' WHEN 5 THEN '客服' WHEN 10 THEN '客服' WHEN 11 THEN '客服' WHEN 12 THEN '客服' END as source");
            $type = DB::raw("CASE l.type WHEN 3 then '開分' WHEN 4 THEN '開分' WHEN 5 THEN '洗分' WHEN 10 THEN '開分(贈點)' WHEN 11 THEN '玩家拒絕洗分' WHEN 12 THEN '洗分逾期' END as type");
            $data = $model->select('l.id', DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'), 'm.username as member_username', $source, $type, 'point')
                ->orderBy('l.created_at', 'DESC')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr03User
     * -SR03 User
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "total_in": "總開分金額,ex:100",
     *            "total_out": "總洗分金額,ex:100"
     *        }
     *    ]
     * }
     */
    public function sr03User(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 取開洗分記錄            
            $model = DB::table('transaction_logs as l')->leftJoin('members as m', 'm.id', 'l.member_id')->leftJoin('agents as a', 'a.id', 'm.parent');
            // 只能統計自己下線的
            $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            } else if (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username', $request->member_username);
            }
            if (isset($request->source) && $request->source != '') {
                switch ($request->source) {
                    case 1: // 客服開洗分
                        $model = $model->whereIn('l.type', [4, 5]);
                        break;
                    case 2: // 線上儲值
                        $model = $model->where('l.type', 3);
                        break;
                }
            } else {
                $model = $model->whereIn('type', [3, 4, 5]);
            }
            $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $model = $model->where('l.status', 1000);
            $data = $model->select(DB::raw('(sum((CASE l.type WHEN 3 THEN l.point ELSE 0 END))+sum((CASE l.type WHEN 4 THEN l.point ELSE 0 END))) as total_in, sum((CASE l.type WHEN 5 THEN l.point ELSE 0 END)) as total_out'))
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr03Member
     * -SR03 Member
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "member_uaername": "會員帳號,ex:abc",
     *            "total_in": "總開分金額,ex:100",
     *            "total_out": "總洗分金額,ex:100"
     *            "agent_username": "營運商帳號,ex:def"
     *        }
     *    ]
     * }
     */
    public function sr03Member(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 取開洗分記錄            
            $model = DB::table('transaction_logs as l')->leftJoin('members as m', 'm.id', 'l.member_id')->leftJoin('agents as a', 'a.id', 'm.parent');
            // 只能統計自己下線的
            $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('a.username', 'like', '%' . $request->agent_username . '%');
            } else if (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username', 'like', '%' . $request->member_username . '%');
            }
            if (isset($request->source) && $request->source != '') {
                switch ($request->source) {
                    case 1: // 客服開洗分
                        $model = $model->whereIn('l.type', [4, 5]);
                        break;
                    case 2: // 線上儲值
                        $model = $model->where('l.type', 3);
                        break;
                }
            } else {
                $model = $model->whereIn('type', [3, 4, 5]);
            }
            $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $model = $model->where('l.status', 1000);
            $data = $model->select('m.username as member_username', DB::raw('(sum((CASE l.type WHEN 3 THEN l.point ELSE 0 END))+sum((CASE l.type WHEN 4 THEN l.point ELSE 0 END))) as total_in, sum((CASE l.type WHEN 5 THEN l.point ELSE 0 END)) as total_out'), 'a.username as agent_username')
                ->groupBy(DB::raw('m.id'))
                ->orderBy('total_in', 'DESC')
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr05
     * -SR05 客服轉入轉出明細表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam source integer required 來源0:客服1:線上支付 Example: 0
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "編號,ex:1",
     *            "transaction_no": "單號,ex:1",
     *            "created_at": "時間,ex:2019-01-01 00:00:00",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "type": "狀態1:轉入,2:轉出,ex:1",
     *            "member_username": "會員帳號,ex:abc",
     *            "member_nickname": "會員暱稱,ex:abc",
     *            "point": "點數,ex:100",
     *            "balance": "變更後點數,ex:100",
     *            "source": "來源1:客服2:線上支付,ex:1",
     *            "operator_username": "執行者帳號,ex:cs1",
     *            "status": "狀態0:處理中1:成功,ex:1",
     *            "remark": "備註,ex:aaa"
     *        }
     *    ]
     * }
     */
    public function sr05(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'digits_between:0,10',
                'source' => 'in:0,1,2',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 取客服轉入轉出            
            $model = DB::table('transaction_logs as l')->leftJoin('members as m', 'm.id', 'l.member_id')->leftJoin('agents as a', 'a.id', 'm.parent');
            // 只能統計自己下線的
            $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            }
            if (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username',  $request->member_username);
            }
            if (isset($request->member_nickname) && $request->member_nickname != '') {
                $model = $model->where('m.nickname',  $request->member_nickname);
            }
            if (isset($request->transaction_no) && $request->transaction_no != '') {
                $model = $model->where('l.id', $request->transaction_no);
            }
            if (isset($request->source) && $request->source != '') {
                switch ($request->source) {
                    case 0: // 全部
                        $model = $model->whereIn('type', [3, 4, 5]);
                        break;
                    case 1: // 客服開洗分
                        $model = $model->whereIn('l.type', [4, 5]);
                        break;
                    case 2: // 線上儲值
                        $model = $model->where('l.type', 3);
                        break;
                }
            }
            $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $model = $model->where('l.status', 1000);
            $source = DB::raw('(CASE l.type WHEN 3 THEN 0 WHEN 4 THEN 1 WHEN 5 THEN 1 END) as source');
            $type = DB::raw('(CASE l.type WHEN 3 THEN 1 WHEN 4 THEN 1 WHEN 5 THEN 2 END) as type');
            $status = DB::raw('(CASE  WHEN l.status=1000 THEN 1 ELSE 0 END) as status');
            $data = $model->select('l.id', 'l.transaction_no', DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'), 'a.username as agent_username', $type, 'm.username as member_username', 'm.nickname as member_nickname', 'l.point', 'l.balance', $source, 'l.operator as operator_username', $status, 'remark')
                ->orderBy('l.created_at', 'DESC')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr06
     * -SR06 平台轉入轉出明細表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam status integer 狀態(沒傳預設全部，1:成功，2:失敗) Example: 1
     * @bodyParam source integer required 來源0:客服1:線上支付 Example: 0
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "編號,ex:1",
     *            "transaction_no": "單號,ex:1",
     *            "created_at": "時間,ex:2019-01-01 00:00:00",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "vendor": "平台名稱,ex:1",
     *            "type": "狀態1:轉出,2:轉入,ex:1",
     *            "member_username": "會員帳號,ex:abc",
     *            "member_nickname": "會員暱稱,ex:abc",
     *            "point": "點數,ex:100",
     *            "balance": "變更後點數,ex:100",
     *            "status": "狀態,ex:1"
     *        }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function sr06(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'digits_between:0,10',
                'source' => 'in:0,1',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
                'page' => 'required|numeric',
                'per_page' => 'required|numeric'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 取客服轉入轉出            
            $model = DB::table('transaction_logs as l')->leftJoin('vendors as v', 'v.id', 'l.vendor_id')->leftJoin('members as m', 'm.id', 'l.member_id')->leftJoin('agents as a', 'a.id', 'm.parent');
            // 只能統計自己下線的
            $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            }
            if (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username',  $request->member_username);
            }
            if (isset($request->member_nickname) && $request->member_nickname != '') {
                $model = $model->where('m.nickname', $request->member_nickname);
            }
            if (isset($request->transaction_no) && $request->transaction_no != '') {
                $model = $model->where('l.id', $request->transaction_no);
            }
            $model = $model->whereIn('l.type', [1, 2]);
            $model = $model->whereBetween('l.created_at', [date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')), date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour'))]);
            // $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            // $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            if ($request->status == 1) {
                $model = $model->where('l.status', 1000);
            } else if ($request->status == 2) {
                $model = $model->where('l.status', '!=', 1000);
            }

            $data = $model->select('l.id', 'l.transaction_no', DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'), 'a.username as agent_username', 'v.name as vendor', 'l.type', 'm.username as member_username', 'm.nickname as member_nickname', 'l.point', 'l.balance', 'l.status');
            //總數
            $total = $data->getCountForPagination();
            // 排序欄位
            $data = $data->orderBy('l.created_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();


            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr07
     * -SR07 平台轉入轉出明細表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam start_date date required 開始日期 Example: 2019-01-01
     * @bodyParam end_date date required 結束日期 Example: 2019-12-31
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "編號,ex:1",
     *            "transaction_no": "單號,ex:1",
     *            "created_at": "時間,ex:2019-01-01 00:00:00",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "vendor": "平台名稱,ex:1",
     *            "type": "狀態1:轉出,2:轉入,ex:1",
     *            "member_username": "會員帳號,ex:abc",
     *            "member_nickname": "會員暱稱,ex:abc",
     *            "point": "點數,ex:100",
     *            "balance": "變更後點數,ex:100",
     *            "status": "狀態,ex:1"
     *        }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function sr07(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'digits_between:0,16',
                'start_date' => 'required|date_format:Y-m-d',
                'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date',
                'page' => 'required|numeric',
                'per_page' => 'required|numeric'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 取客服轉入轉出            
            $model = DB::table('transaction_logs as l')
            ->leftJoin('members as m', 'm.id', 'l.member_id')
            ->leftJoin('agents as a', 'a.id', 'm.parent');
            // 只能統計自己下線的
            $model = $model->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('a.username', $request->agent_username);
            }
            if (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('l.operator',  $request->member_username);
            }
            if (isset($request->member_nickname) && $request->member_nickname != '') {
                $model = $model->where('m.nickname', $request->member_nickname);
            }
            if (isset($request->transaction_no) && $request->transaction_no != '') {
                $model = $model->where('l.transaction_no', $request->transaction_no);
            }
            $model = $model->whereIn('l.type', [6, 7, 8, 9]);
            $model = $model->whereBetween('l.created_at', [date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')), date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour'))]);
            $model = $model->where('l.status', 1000);
            $data = $model->select('l.id', 'l.transaction_no', DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'), 'm.username as username', 'l.type', 'l.status', 'l.point', 'l.balance', 'l.operator');
            //總數
            $total = $data->getCountForPagination();
            // 排序欄位
            $data = $data->orderBy('l.created_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            // Log::info('66666'.$data);
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr11Top
     * -sr11Top 代理商遊戲平台對帳表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:abc",
     *            "win_lose": "總輸贏,ex:abc",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function sr11Top(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'vendor_id' => 'digits_between:0,10',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d',
                'end_time' => 'required|date_format:Y-m-d|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            //以agent為主
            $data = DB::table('summary_bet_records as s')->leftJoin('games as g', 'g.id', 's.game_id')
                ->leftJoin('game_types as t', 't.id', 'g.type_id')->leftJoin('members as m', 'm.id', 's.member_id')
                ->leftJoin('agents as a', 'a.id', 'm.parent')->leftJoin('vendors as v', 'v.id', 's.vendor_id')
                ->whereBetween('s.summary_time', [date('Y-m-d H:i:s', strtotime($request->start_time . ' 00:00:00-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . ' 23:59:59-8hour'))]);

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //只能查詢自己或自己下線的
                    if ($this->isChild($request->ID, $agent->id) ||  $agent->id == $request->ID) {
                        //查詢這個代理商下層的代理
                        $data = $data->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
                    } else {
                        //代理商不存在
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else {
                $agent = Agent::where('id', $request->ID)->first();
                //查登入的這個agent
                $data = $data->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            }

            if (isset($request->vendor_id) && $request->vendor_id > 0) {
                $data = $data->where('s.vendor_id',  $request->vendor_id);
            }

            if (isset($request->game_type) && $request->game_type > 0) {
                $data = $data->where('t.id',  $request->game_type);
            }
            $data = $data->select(DB::raw("'" . $agent->id . "'" . ' as agent_id'), DB::raw("'" . $agent->username . "'" . ' as agent_username'), DB::raw("'" . $agent->nickname . "'" . ' as agent_nickname'), 'v.name as vendor', 'v.id as vendor_id', 't.name as game_type', 't.id as game_type_id', DB::raw('SUM(s.bet) as total_bet'), DB::raw('SUM(s.win_lose) as win_lose'), DB::raw('SUM(s.jackpot) as lottery'), 's.share_amount')
                ->groupBy('v.id')->groupBy('t.id')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr11Agent
     * -sr11Agent 代理商遊戲平台對帳表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:abc",
     *            "win_lose": "總輸贏,ex:abc",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function sr11Agent(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'vendor_id' => 'digits_between:0,10',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d',
                'end_time' => 'required|date_format:Y-m-d|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            //以agent為主
            $data = DB::table('summary_bet_records as s')->leftJoin('games as g', 'g.id', 's.game_id')
                ->leftJoin('game_types as t', 't.id', 'g.type_id')->leftJoin('members as m', 'm.id', 's.member_id')
                ->leftJoin('agents as a', 'a.id', 'm.parent')->leftJoin('vendors as v', 'v.id', 's.vendor_id')
                ->whereBetween('s.summary_time', [date('Y-m-d H:i:s', strtotime($request->start_time . ' 00:00:00-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . ' 23:59:59-8hour'))]);

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //只能查詢自己或自己下線的
                    if ($this->isChild($request->ID, $agent->id) ||  $agent->id == $request->ID) {
                        //查詢這個代理商下層的代理
                        $data = $data->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
                    } else {
                        //代理商不存在
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else {
                //查登入的這個agent
                $data = $data->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            }

            if (isset($request->vendor_id) && $request->vendor_id > 0) {
                $data = $data->where('s.vendor_id',  $request->vendor_id);
            }

            if (isset($request->game_type) && $request->game_type > 0) {
                $data = $data->where('t.id',  $request->game_type);
            }
            $data = $data->select('a.id as agent_id', 'a.username as agent_username', 'a.nickname as agent_nickname', 'v.name as vendor', 'v.id as vendor_id', 't.name as game_type', 't.id as game_type_id', DB::raw('SUM(s.bet) as total_bet'), DB::raw('SUM(s.win_lose) as win_lose'), DB::raw('SUM(s.jackpot) as lottery'), 's.share_amount')
                ->groupBy('a.id')->groupBy('v.id')->groupBy('t.id')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr11Member
     * -sr11Member 會員遊戲平台對帳表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "member_id": "會員編號,ex:1",
     *            "member_username": "會員帳號,ex:abc",
     *            "member_nickname": "會員暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:abc",
     *            "win_lose": "總輸贏,ex:abc",
     *            "lottery": "中彩金,ex:100"
     *        }
     *    ]
     * }
     */
    public function sr11Member(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'vendor_id' => 'digits_between:0,10',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d',
                'end_time' => 'required|date_format:Y-m-d|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $data = DB::table('summary_bet_records as s')->leftJoin('games as g', 'g.id', 's.game_id')
                ->leftJoin('game_types as t', 't.id', 'g.type_id')->leftJoin('members as m', 'm.id', 's.member_id')
                ->leftJoin('agents as a', 'a.id', 'm.parent')->leftJoin('vendors as v', 'v.id', 's.vendor_id')
                ->whereBetween('s.summary_time', [date('Y-m-d H:i:s', strtotime($request->start_time . ' 00:00:00-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . ' 23:59:59-8hour'))]);

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //只能查詢自己或自己下線的
                    if ($this->isChild($request->ID, $agent->id) ||  $agent->id == $request->ID) {
                        //查詢這個代理商下面的所有玩家
                        $data = $data->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
                    } else {
                        //代理商不存在
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else if (isset($request->member_username) && $request->member_username != '') {
                //查詢單一玩家記錄
                $data = $data->where('m.username', $request->member_username)->where('a.id', $request->ID);
            }

            if (isset($request->vendor_id) && $request->vendor_id > 0) {
                $data = $data->where('s.vendor_id',  $request->vendor_id);
            }

            if (isset($request->game_type) && $request->game_type > 0) {
                $data = $data->where('t.id',  $request->game_type);
            }
            $data = $data->select('m.id as member_id', 'm.username as member_username', 'm.nickname as member_nickname', 'v.name as vendor', 'v.id as vendor_id', 't.name as game_type', 't.id as game_type_id', DB::raw('SUM(s.bet) as total_bet'), DB::raw('SUM(s.win_lose) as win_lose'), DB::raw('SUM(s.jackpot) as lottery'), 's.share_amount')
                ->groupBy('m.id')->groupBy('v.id')->groupBy('t.id')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/sr12
     * -SR12 新平台下注記錄
     *
     * @bodyParam vendor_id integer required 遊戲商系統編號 Example: 1 
     * @bodyParam game_type integer required 遊戲類別系統編號 Example: 1 
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam game_no string 遊戲記錄編號 Example: 1
     * @bodyParam game_id integer 遊戲編號 Example: 1
     * @bodyParam sort_by integer required 排序欄位1:押注時間2:押注金額 Example: 1
     * @bodyParam whitch_time integer 搜尋時間1:押注時間2:結算時間 Example: 1
     * @bodyParam start_time time required 開始日期 Example: 2019-01-01 00:00:00
     * @bodyParam end_time time required 結束日期 Example: 2019-12-31 00:00:00
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "game_no": "訂單號,ex:abc",
     *            "member_username": "會員帳號,ex:abc",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "game_name": "遊戲名稱,ex:1",
     *            "bet_amount": "投注額,ex:100",
     *            "lottery": "彩金,ex:100",
     *            "win_lose": "會員暱稱,ex:10000",
     *            "service_fee": "服務費,ex:100",
     *            "bet_time": "投注時間,ex:2019-01-02 00:00:00",
     *            "settlement_time": "結算時間,ex:2019-01-02 00:00:00",
     *            "bet_status": "投注狀態0:未結算1:已結算,ex:1",
     *            "remark": "備註,ex:xxx"
     *        }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function sr12(Request $request)
    {
        try {
            $rules = [
                'vendor_id' => 'required|digits_between:0,10',
                'game_type' => 'required|digits_between:0,10',
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'game_no' => 'r_alpha_num|between:0,30',
                'game_id' => 'required|digits_between:0,10',
                'sort_by' => 'in:1,2',
                'whitch_time' => 'in:1,2',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time',
                'page' => 'required|numeric',
                'per_page' => 'required|numeric'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            // 只能統計自己下線的
            $agent = DB::table('agents as a')->select('a.id', 'a.username')->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            $agent = DB::table('agents as a')->select('a.id', 'a.username');

            //$data = DB::table(DB::raw("({$agent->toSql()}) a"))->mergeBindings($agent)
            $data = DB::table('agents as a')
                ->leftJoin('members as m', 'm.parent', 'a.id')
                ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            switch ($request->vendor_id) {
                case 1: // AG
                    $data = $data->leftJoin('ag_accounts as v', 'v.member_id', 'm.id')
                                ->leftJoin('ag_bet_records as b', 'v.username', 'b.player_name')
                                ->leftJoin('games as g', 'g.game_type', 'b.game_code')
                                ->where('data_type', '!=', 'TR')
                                ->select(
                                    'b.bill_no as game_no',
                                    'm.username as member_username',
                                    'a.username as agent_username',
                                    'g.name as game_name',
                                    'b.bet_amount as bet_amount',
                                    DB::raw('(b.bet_amount+b.net_amount) as lottery'),
                                    'b.net_amount as win_lose',
                                    DB::raw('0 as service_fee'),
                                    DB::raw('DATE_ADD(b.utc_bet_time,INTERVAL 8 HOUR) as bet_time'),
                                    DB::raw('DATE_ADD(b.utc_bet_time,INTERVAL 8 HOUR) as settlement_time'),
                                    DB::raw("1 as bet_status"),
                                    DB::raw("'-' as remark")
                                );
                    //搜尋時間
                    $data = $data->whereBetween('utc_bet_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 2: // Joker
                    $data = $data->leftJoin('joker_accounts as v', 'v.member_id', 'm.id')->leftJoin('joker_bet_records as b', 'v.username', 'b.username')
                        ->leftJoin('games as g', 'g.game_type', 'b.game_code')
                        ->select(
                            'b.code as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.amount as bet_amount',
                            'b.result as lottery',
                            DB::raw('(b.amount-b.result) as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.bet_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.bet_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('bet_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 5: // GW99
                    $data = $data->leftJoin('great_wall_accounts as v', 'v.member_id', 'm.id')->leftJoin('great_wall_bet_records as b', DB::raw("CONCAT('jkl_0701001',v.username)"), 'b.userName')
                        ->leftJoin('games as g', 'g.game_type', DB::raw('LEFT(b.themeId,4)'))
                        ->select(
                            'b.gameSerialId as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.betCoin as bet_amount',
                            'b.winAmount as lottery',
                            DB::raw('(b.betCoin-b.winAmount) as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_created_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_created_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.utc_created_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 6: // ACE333
                    $data = $data->leftJoin('ace333_accounts as v', 'v.member_id', 'm.id')->leftJoin('ace333_bet_records as b', 'v.player_id', 'b.playerID')
                        ->leftJoin('games as g', 'g.game_type', 'b.gameID')
                        ->select(
                            'b.referenceID as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.betAmount as bet_amount',
                            DB::raw('(b.betAmount+b.winAmount) as lottery'),
                            'b.winAmount as win_lose',
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.created,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.updated,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.created', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 8: // VPlay
                    $data = $data->leftJoin('vplay_accounts as v', 'v.member_id', 'm.id')
                        // ->leftJoin('vplay_bet_records as b',  DB::raw("CONCAT(v.username,'@vworld')"), 'b.MemberAccount')
                        ->leftJoin('vplay_bet_records as b', DB::raw("substring_index(b.MemberAccount,'@',1)"), 'v.username')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.gameID')
                                ->where('g.vendor_id', 8);
                        })
                        ->select(
                            'b.WinloseID as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.Bet as bet_amount',
                            DB::raw('(b.Bet+b.WinLose) as lottery'),
                            'b.WinLose as win_lose',
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_bet_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_recalcu_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.utc_bet_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 11: // Xe88
                    $data = $data->leftJoin('xe88_accounts as v', 'v.member_id', 'm.id')->leftJoin('xe88_bet_records as b',  'b.username', 'v.username')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.game_id')
                                ->where('g.vendor_id', 11);
                        })
                        ->where('b.game_name', '!=', 'setscore')
                        ->select(
                            'b.id as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.bet as bet_amount',
                            'b.win as lottery',
                            DB::raw('b.win-b.bet as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_log_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_log_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.utc_log_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 12: // Lucky
                    $data = $data->leftJoin('lucky_accounts as v', 'v.member_id', 'm.id')->leftJoin('lucky_bet_records as b',  'v.player_id', 'b.userId')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.remark', '=', 'b.mid')
                                ->where('g.vendor_id', 12);
                        })
                        ->select(
                            'b.tid as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.bet as bet_amount',
                            'b.win as lottery',
                            DB::raw('b.win-b.bet as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.bet_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.bet_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.bet_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 13: // eBET
                    $data = $data->leftJoin('ebet_accounts as v', 'v.member_id', 'm.id')->leftJoin('ebet_bet_records as b',  'v.username', 'b.username')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.gameType')
                                ->where('g.vendor_id', 13);
                        })
                        ->select(
                            'b.betHistoryId as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.bet as bet_amount',
                            'payoutWithoutholding as lottery',
                            'b.balance as win_lose',
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_create_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_payout_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark"));
                    //搜尋時間
                    $data = $data->whereBetween('b.utc_payout_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 14: // iSB
                    $data = $data->leftJoin('isb_accounts as v', 'v.member_id', 'm.id')->leftJoin('isb_bet_records as b',  'v.player_id', 'b.playerid')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.gameid')
                                ->where('g.vendor_id', 14);
                        })
                        ->select(
                            'b.roundid as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            DB::raw("SUM(if(b.type='BET' , (b.amount/100), 0)) as bet_amount"),
                            DB::raw("SUM(if(b.type='WIN' , (b.amount/100), 0)) as lottery"),
                            DB::raw("SUM(if(b.type='WIN' , (b.amount/100), 0)-if(b.type='BET' , (b.amount/100), 0)) as win_lose"),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        )
                        ->groupBy('b.roundid');
                    //搜尋時間
                    $data = $data->whereBetween('b.time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 15: // Ygg
                    $data = $data->leftJoin('ygg_accounts as v', 'v.member_id', 'm.id')->leftJoin('ygg_bet_records as b', 'v.username', 'b.loginname')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.name', '=', 'b.gameName')
                                ->where('g.vendor_id', 15);
                        })
                        ->select(
                            'b.reference as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            DB::raw("SUM(if(b.type='wager', b.amount, 0)) as bet_amount"),
                            DB::raw("SUM(if(b.type='endWager', b.amount, 0)) as lottery"),
                            DB::raw("(SUM(if(b.type='endWager', b.amount, 0))-SUM(if(b.type='wager', b.amount, 0))) as win_lose"),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.createTime,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.createTime,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('createTime', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))])
                        ->groupBy('b.reference');
                    break;
                case 16: //WM
                    $data = $data->leftJoin('wm_accounts as v', 'v.member_id', 'm.id')
                    	->leftJoin('wm_bet_records as b',  'v.user_id', 'b.external_user_id')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.game_identity')
                                ->where('g.vendor_id', 16);
                        })
                        ->select(
                            'b.round_id as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.bet_amount as bet_amount',
                            'b.win_amount as lottery',
                            DB::raw('b.win_amount-b.bet_amount as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_start_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_end_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                        //搜尋時間
                    $data = $data->whereBetween('b.utc_end_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 17: // PT
                    $data = $data->leftJoin('pt_accounts as v', 'v.member_id', 'm.id')->leftJoin('pt_bet_records as b',  'v.username', 'b.player_name')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.name', '=', 'b.game_name')
                                ->where('g.vendor_id', 17);
                        })
                        ->select(
                            'b.game_code as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'b.game_name as game_name',
                            'b.bet as bet_amount',
                            'b.win as lottery',
                            DB::raw('b.win-b.bet as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_game_date,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_game_date,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("'-' as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.utc_game_date', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 18: // SBO
                    $data = $data->leftJoin('sbo_accounts as v', 'v.member_id', 'm.id')->leftJoin('sbo_bet_records as b',  'v.username', 'b.player_name')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.game_code')
                                ->where('g.vendor_id', 18);
                        })
                        ->select(
                            'b.bet_id as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.bet_amount',
                            'b.win_amount as lottery',
                            DB::raw('b.win_amount-b.bet_amount as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.create_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.create_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("traceId as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.create_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 19: // MT
                    $data = $data->leftJoin('mt_accounts as v', 'v.member_id', 'm.id')->leftJoin('mt_bet_records as b',  'v.username', 'b.playerName')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.gameCode')
                                ->where('g.vendor_id', 19);
                        })
                        ->select(
                            'b.rowID as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.betAmount',
                            'b.winAmount as lottery',
                            DB::raw('b.winAmount-b.betAmount as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_gameDate,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_gameDate,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("traceId as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.utc_gameDate', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 20: // PG
                    $data = $data->leftJoin('pg_accounts as v', 'v.member_id', 'm.id')->leftJoin('pg_round_bet_records as b',  'v.username', 'b.ExternalUserId')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.GameId')
                                ->where('g.vendor_id', 20);
                        })
                        ->select(
                            'b.TransactionId as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.TotalLoss',
                            'b.TotalGain as lottery',
                            DB::raw('b.TotalGain-b.TotalLoss as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.Time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.Time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("traceId as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.Time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    break;
                case 21: // IA
                    $data = $data->leftJoin('ia_accounts as v', 'v.member_id', 'm.id')->leftJoin('ia_bet_records as b',  'v.username', 'b.username')
                        ->leftJoin('games as g', function ($join) {
                            $join->on('g.game_type', '=', 'b.client_type')
                                ->where('g.vendor_id', 21);
                        })
                        ->select(
                            'b.rowID as game_no',
                            'm.username as member_username',
                            'a.username as agent_username',
                            'g.name as game_name',
                            'b.betamount',
                            'b.winamount as lottery',
                            DB::raw('b.winamount-b.betamount as win_lose'),
                            DB::raw('0 as service_fee'),
                            DB::raw('DATE_ADD(b.utc_create_time,INTERVAL 8 HOUR) as bet_time'),
                            DB::raw('DATE_ADD(b.utc_create_time,INTERVAL 8 HOUR) as settlement_time'),
                            DB::raw("1 as bet_status"),
                            DB::raw("traceId as remark")
                        );
                    //搜尋時間
                    $data = $data->whereBetween('b.utc_create_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    $dailydata = DB::table('agents as a')
                    ->leftJoin('members as m', 'm.parent', 'a.id')
                    ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%')
                    ->leftJoin('ia_accounts as v', 'v.member_id', 'm.id')->leftJoin('ia_daily_win_losses as b',  'v.username', 'b.username')
                    ->leftJoin('games as g', function ($join) {
                        $join->on('g.game_type', '=', 'b.client_type')
                            ->where('g.vendor_id', 21);
                    })
                    ->select(
                        'b.rowID as game_no',
                        'm.username as member_username',
                        'a.username as agent_username',
                        'g.name as game_name',
                        'b.amount',
                        'b.bonus as lottery',
                        DB::raw('b.bonus-b.amount as win_lose'),
                        DB::raw('0 as service_fee'),
                        DB::raw('DATE_ADD(b.utc_update_time,INTERVAL 8 HOUR) as bet_time'),
                        DB::raw('DATE_ADD(b.utc_update_time,INTERVAL 8 HOUR) as settlement_time'),
                        DB::raw("1 as bet_status"),
                        DB::raw("traceId as remark")
                    );
                    //搜尋時間
                    $dailydata = $dailydata->whereBetween('b.utc_update_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))]);
                    $data = $data->union($dailydata);
                    break;
            }
            if (isset($request->agent_username) && $request->agent_username != '') {
                $data = $data->where('a.username', $request->agent_username);
            } else if (isset($request->member_username) && $request->member_username != '') {
                $data = $data->where('m.username', $request->member_username);
            }
            if (isset($request->game_no) && $request->game_no != '') {
                switch ($request->vendor_id) {
                    case 1: // AG
                        $data = $data->where('b.bill_no', 'like', '%' . $request->game_no . '%');
                        break;
                    case 2: // Joker
                        $data = $data->where('b.code', 'like', '%' . $request->game_no . '%');
                        break;
                    case 5: // GW99
                        $data = $data->where('b.gameSerialId', 'like', '%' . $request->game_no . '%');
                        break;
                    case 6: // ACE333
                        $data = $data->where('b.referenceID', 'like', '%' . $request->game_no . '%');
                        break;
                    case 8: // Vplay
                        $data = $data->where('b.WinloseID', 'like', '%' . $request->game_no . '%');
                        break;

                    case 11: // Xe88 (沒有遊戲單號，直接拿記錄的ID)
                        $data = $data->where('b.id', 'like', '%' . $request->game_no . '%');
                        break;
                    case 12: // lucky
                        $data = $data->where('b.tid', 'like', '%' . $request->game_no . '%');
                        break;
                    case 13: // eBET
                        $data = $data->where('b.betHistoryId', 'like', '%' . $request->game_no . '%');
                        break;
                    case 15: // ygg
                        $data = $data->where('b.reference', 'like', '%' . $request->game_no . '%');
                        break;
                    case 17: // PT
                        $data = $data->where('b.game_code', 'like', '%' . $request->game_no . '%');
                        break;
                }
            }


            if ($request->game_type > 0) {
            }
            if ($request->game_id > 0) {
            }
            //總數
            $total = $data->getCountForPagination();
            // 排序欄位
            switch ($request->sort_by) {
                case 1: //投注時間
                    $data = $data->orderBy('bet_time', 'desc');
                    break;
                case 2: //投注金額
                    $data = $data->orderBy('bet_amount', 'desc');
                    break;
            }
            $data = $data->orderBy('b.id', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();


            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/transactionTotal
     * -SR02 客服轉入轉出合計
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam source integer required 來源 0:客服1:線上支付 Example: 0
     * @bodyParam filter integer required 篩選 1:代理開洗分2:所有會員 Example: 1
     * @bodyParam deposit_to integer required 開分給 0:全部1:僅代理2:僅會員 Example: 0
     * @bodyParam start_date dateTime required 開始日期 Example: 2019-01-01 00:10
     * @bodyParam end_date dateTime required 結束日期 Example: 2019-12-31 00:10
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "total_in": "總開分金額,ex:100",
     *            "total_out": "總洗分金額,ex:100"
     *        }
     *    ]
     * }
     */
    public function transactionTotal(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'digits_between:0,10',
                'source' => 'in:0,1,2',
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_date'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            //總代理特例，要取所有的儲值記錄
            if ($request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
            } else {
                $agent = Agent::find($request->HOST);
            }
            //這個帳號下所有訂單儲值的記錄,如果只查詢客服的話就不需要訂單儲值記錄
            /*
            if (is_object($agent) && $agent->parent == 1 && $request->source != 1) {
                $payment_model = $this->onlinePaymentModel($request, $agent->id);
                $payment_model = $payment_model->select(DB::raw('a.username as agent_username, (sum(l.point)) as total_in, 0 as total_out'));
            }
            */
            if($request->deposit_to == '0' || $request->deposit_to == '2'){
                $model = $this->transactionMemberModel($request);
                //類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵
                $model = $model->select(DB::raw('a.username as agent_username, (sum(CASE l.type WHEN 3 THEN l.point WHEN 4 THEN l.point WHEN 10 THEN l.point WHEN 11 THEN l.point WHEN 12 THEN l.point ELSE 0 END)) as total_in, sum((CASE l.type WHEN 5 THEN l.point ELSE 0 END)) as total_out'));
            }            
            /*
            if (isset($payment_model)) {
                $model = $model->union($payment_model);
            }
            */
            if($request->deposit_to == '0' || $request->deposit_to == '1'){
                //客服之間互相開洗分
                $agent_model = $this->transactionAgentModel($request);
                //如果不是查詢代理就不需要代理間開洗分的資料
                if ($agent_model) {
                    $agent_model =  $agent_model->select(DB::raw('f.username as agent_username, (sum(CASE la.type WHEN 1 THEN la.amount ELSE 0 END)) as total_in, sum((CASE la.type WHEN 2 THEN la.amount ELSE 0 END)) as total_out'));
                    if($request->deposit_to == '0'){
                        $model = $model->union($agent_model);
                    } else {
                        $model = $agent_model;
                    }
                    
                }
            }         
            $data = $model->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/transactionList
     * -SR02 客服轉入轉出明細表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam source integer required 來源 0:客服1:線上支付 Example: 0
     * @bodyParam filter integer required 篩選 1:代理開洗分2:所有會員 Example: 1
     * @bodyParam deposit_to integer required 開分給 0:全部1:僅代理2:僅會員 Example: 0
     * @bodyParam start_date dateTime required 開始日期 Example: 2019-01-01 00:10
     * @bodyParam end_date dateTime required 結束日期 Example: 2019-12-31 00:10
     * @bodyParam page integer required 頁數 Example: 1
     * @bodyParam per_page integer required 每頁幾筆 Example: 25
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "編號,ex:1",
     *            "transaction_no": "單號,ex:1",
     *            "created_at": "時間,ex:2019-01-01 00:00:00",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "type": "狀態1:轉入,2:轉出,ex:1",
     *            "member_username": "會員帳號,ex:abc",
     *            "member_nickname": "會員暱稱,ex:abc",
     *            "point": "點數,ex:100",
     *            "balance": "變更後點數,ex:100",
     *            "source": "來源1:客服2:線上支付,ex:1",
     *            "operator_username": "執行者帳號,ex:cs1",
     *            "status": "狀態0:處理中1:成功,ex:1",
     *            "remark": "備註,ex:aaa"
     *        }
     *    ],
     *  "pagination": {
     *            "per_page": "單頁筆數,ex:20",
     *            "total":"總筆數,ex:10",
     *            "pages": "總頁數,ex:1",
     *            "previous": "上一頁,ex:",
     *            "next": "下一頁,ex:1",
     *            "current": "目前頁面,ex:"
     *    }
     * }
     */
    public function transactionList(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'digits_between:0,16',
                'source' => 'in:0,1,2',
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_date',
                'page' => 'required|numeric',
                'per_page' => 'required|numeric'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            //總代理特例，要取所有的儲值記錄
            if ($request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
            } else {
                $agent = Agent::find($request->HOST);
            }

            //這個帳號下所有訂單儲值的記錄,如果只查詢客服的話就不需要訂單儲值記錄
            /*不需要這段了, 如果選擇全部時後面自然會有線上支付的條件
            if (is_object($agent) && $agent->parent == 1 && $request->source != 1) {
                $payment_model = $this->onlinePaymentModel($request, $agent->id);
                //類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵
                $type = DB::raw("'開分' as type");
                $source = DB::raw("'線上支付' as source");
                $status = DB::raw("(CASE  WHEN l.status=1000 THEN '成功' ELSE '失敗' END) as status");
                $payment_model = $payment_model->select('l.id', 'l.transaction_no',
                    DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'),
                    'a.username as agent_username', $type, 'm.username as member_username',
                    'm.nickname as member_nickname', 'l.point', 'l.balance', $source,
                    'l.operator as operator_username', $status, 'l.remark', DB::raw("1 as platform"));
            }
            */
            if($request->deposit_to == '0' || $request->deposit_to == '2'){
                //客服幫會員開洗分，或會員線上儲值
                $model = $this->transactionMemberModel($request);
                //類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵
                $type = DB::raw("(CASE l.type WHEN 3 THEN '開分' WHEN 4 THEN '開分' WHEN 5 THEN '洗分' WHEN 10 THEN '開分(贈點)' WHEN 11 THEN '開分(拒絕洗分)' WHEN 12 THEN '開分(系統退還洗分)' END) as type");
                $source = DB::raw("(CASE l.type WHEN 3 THEN '線上支付' WHEN 4 THEN '客服' WHEN 5 THEN '客服' WHEN 10 THEN '客服' WHEN 11 THEN '客服' WHEN 12 THEN '客服' END) as source");
                $status = DB::raw("(CASE  WHEN l.status=1000 THEN '成功' ELSE '失敗' END) as status");
                $model = $model->select(
                    'l.id',
                    'l.transaction_no',
                    DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'),
                    'a.username as agent_username',
                    $type,
                    'm.username as member_username',
                    'm.nickname as member_nickname',
                    'l.point',
                    'l.balance',
                    $source,
                    'l.operator as operator_username',
                    $status,
                    'l.remark',
                    DB::raw("1 as platform"));
            }
            /*不需要這段了, 如果選擇全部時後面自然會有線上支付的條件
            if (isset($payment_model)) {
                $model = $model->union($payment_model);
            }*/
            if($request->deposit_to == '0' || $request->deposit_to == '1'){
                //客服之間互相開洗分
                $agent_model = $this->transactionAgentModel($request);
                //如果不是查詢代理就不需要代理間開洗分的資料
                if ($agent_model) {
                    $type = DB::raw("(CASE la.type WHEN 1 THEN '開分' WHEN 2 THEN '洗分' END) as type");
                    $agent_model =  $agent_model->select('la.id', DB::raw("'' as transaction_no"), DB::raw('DATE_ADD(la.created_at,INTERVAL 8 HOUR) as created_at'), 'f.username as agent_username', $type, 't.username as member_username', 't.nickname as member_nickname', 'la.amount as point',  DB::raw("(to_limit_of_credit-to_used_credit) as balance"),  DB::raw("'客服' as source"),  'la.operator as operator_username', 'la.status', 'la.remark', DB::raw("2 as platform"));
                    //篩選選擇全部就用union，選擇僅代理就直接get
                    if($request->deposit_to == '0'){
                        $model = $model->union($agent_model);
                    } else {
                        $model = $agent_model;
                    }          
                }
            }
            //總數
            $total = count($model->get());
            // 排序欄位
            $data = $model
                ->orderBy('created_at', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/platformReconciliationTotal
     * -sr11 代理商遊戲平台對帳表(上半部統計)
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:abc",
     *            "win_lose": "總輸贏,ex:abc",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function platformReconciliationTotal(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                // 'vendor_id' => 'digits_between:0,10',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            // 代理
            $model = $this->platformReconciliation($request, true);
            $model = $model->groupBy('v.id')->groupBy('t.id');

            // 會員
            $member = $this->platformReconciliation($request, false);
            $member = $member->groupBy('v.id')->groupBy('t.id');

            $model = $model->union($member);
            // //活動獎勵
            if (strpos($request->vendor_id, '-1') !== false) {
                $agent_event_model = $this->platformReconciliationEventTotal($request, true);
                $model = $model->union($agent_event_model);
                $member_event_model = $this->platformReconciliationEventTotal($request, false);
                $model = $model->union($member_event_model);
            }

            $data = $model->orderBy('agent_id', 'asc')
                ->orderBy('vendor_id', 'asc')
                ->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/platformReconciliationDetail
     * -sr11 代理商遊戲平台對帳表(代理)
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:abc",
     *            "win_lose": "總輸贏,ex:abc",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function platformReconciliationDetail(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                // 'vendor_id' => 'digits_between:0,10',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //子帳號權限調整 $request->ID => $request->HOST
                    //只能查詢自己或自己下線的
                    if (!$this->isChild($request->HOST, $agent->id) &&  $agent->id != $request->ID) {
                        //不是下線
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else {
                //查登入的這個agent
                $agent = Agent::where('id', $request->HOST)->first();
            }

            $temp_query = DB::table('agents as ta')
                ->leftJoin('agents as taa', function ($join) {
                    $join->on('taa.hierarchy', 'like', DB::raw("CONCAT('%,',ta.id,',%')"));
                })->where('ta.parent', $agent->id)->select('ta.id as agent_id', 'taa.id as child', 'ta.username', 'ta.nickname')->take(10000);
            // 代理
            $agent_model = $this->platformReconciliationDetailModel($request, true, $temp_query);
            $agent_model = $agent_model->groupBy('temp.agent_id')->groupBy('v.id')->groupBy('t.id');
            $b = $agent_model->getCountForPagination();
            if (!isset($model)) {
                $model = $agent_model;
            } else {
                $model = $model->union($agent_model);
            }
            // 會員
            $member_model = $this->platformReconciliationDetailModel($request, false, $temp_query);
            $member_model = $member_model->groupBy('temp.agent_id')->groupBy('v.id')->groupBy('t.id');
            $a = $member_model->getCountForPagination();
            $model = $model->union($member_model);
            $total = $a + $b;

            //活動獎勵
            if (strpos($request->vendor_id, '-1') !== false) {
                $agent_event_model = $this->platformReconciliationEventModel($request, true, $temp_query);
                $c = $agent_event_model->getCountForPagination();
                $model = $model->union($agent_event_model);
                $member_event_model = $this->platformReconciliationEventModel($request, false, $temp_query);
                $d = $member_event_model->getCountForPagination();
                $model = $model->union($member_event_model);
                $total += $c;
                $total += $d;
            }

            $data = $model
                ->orderBy('agent_id', 'asc')
                ->orderBy('vendor_id', 'asc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            // Schema::drop('temp_agent_group' . $rand);
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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
     * report/platformReconciliationMember
     * -sr11 代理商遊戲平台對帳表(下半部明細-會員)
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
     * @bodyParam game_type integer required 遊戲分類 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "agent_id": "代理商編號,ex:1",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "agent_nickname": "代理商暱稱,ex:abc",
     *            "vendor": "平台名稱,ex:ag",
     *            "vendor_id": "平台系統編號,ex:1",
     *            "game_type": "遊戲類別,ex:slot",
     *            "game_type_id": "遊戲類別系統編號,ex:1",
     *            "total_bet": "總押注,ex:abc",
     *            "win_lose": "總輸贏,ex:abc",
     *            "lottery": "中彩金,ex:100",
     *            "share_amount": "分成,ex:1"
     *        }
     *    ]
     * }
     */
    public function platformReconciliationMember(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                // 'vendor_id' => 'digits_between:0,10',
                'game_type' => 'digits_between:0,10',
                'start_time' => 'required|date_format:Y-m-d H:i:s',
                'end_time' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_time'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $model = $this->platformReconciliationMemberModel($request);

            //活動獎勵
            if (strpos($request->vendor_id, '-1') !== false) {
                $event_model = $this->platformReconciliationEventMember($request);
                $b = $event_model->getCountForPagination();
                $model = $model->union($event_model);
            }
            $data = $model->orderBy('member_username')->orderBy('vendor_id')->get();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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

    private function platformReconciliationMemberModel($request)
    {
        //以agent為主
        $data = DB::table('summary_bet_records as s')->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('game_types as t', 't.id', 'g.type_id')->leftJoin('members as m', 'm.id', 's.member_id')
            ->leftJoin('agents as a', 'a.id', 'm.parent')->leftJoin('vendors as v', 'v.id', 's.vendor_id')
            ->whereBetween('s.summary_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'))]);

        if (isset($request->agent_username) && $request->agent_username != '') {
            $agent = Agent::where('username', $request->agent_username)->first();
            if (is_object($agent)) {
                //只能查詢自己或自己下線的，子帳號修改$request->ID => $request->HOST
                if ($this->isChild($request->HOST, $agent->id) ||  $agent->id == $request->ID) {
                    $data = $data->where('m.parent', $agent->id);
                } else {
                    //不是下線
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            }
        } else if (isset($request->member_username) && $request->member_username != '') {
            $data = $data->where('m.username',  $request->member_username);
        } else {
            // $agent = Agent::where('id', $request->ID)->first();
            //查登入的這個agent
            $data = $data->where('a.hierarchy', $request->HOST)->first();
        }

        if (isset($request->vendor_id) && $request->vendor_id  != '') {
            $data = $data->whereIn('s.vendor_id',  explode(',', $request->vendor_id));
        }

        if (isset($request->game_type) && $request->game_type > 0) {
            $data = $data->where('t.id',  $request->game_type);
        }
        // $data = $data->where('s.vendor_id', '!=',  4); //豪龍沒有會員明細，所以先隱藏
        $model = $data->select('m.username as member_username', 'm.nickname as member_nickname', 'v.name as vendor', 'v.id as vendor_id', 't.name as game_type', 't.id as game_type_id', DB::raw('TRUNCATE(SUM(s.bet),2) as total_bet'), DB::raw('TRUNCATE(SUM(s.win_lose),2) as win_lose'), DB::raw('TRUNCATE(SUM(s.jackpot),2) as lottery'), 's.share_amount');
        $model = $data->groupBy('m.id')->groupBy('v.id')->groupBy('t.id');
        return $model;
    }


    private function platformReconciliationEventMember($request)
    {
        //以agent為主
        $data = DB::table('transaction_logs as s')
            ->leftJoin('members as m', 'm.id', 's.member_id')
            ->leftJoin('agents as a', 'a.id', 'm.parent')
            ->whereIn('s.type', [13, 15]) // 活動獎勵編號為13, 登入活動為15
            ->whereBetween(
                's.created_at',
                [
                    date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')),
                    date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0 ,-2) . '59-8hour'))
                ]
            );

        if (isset($request->agent_username) && $request->agent_username != '') {
            $agent = Agent::where('username', $request->agent_username)->first();
            if (is_object($agent)) {
                //只能查詢自己或自己下線的，子帳號修改$request->ID => $request->HOST
                if ($this->isChild($request->HOST, $agent->id) ||  $agent->id == $request->ID) {
                    $data = $data->where('m.parent', $agent->id);
                } else {
                    //不是下線
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                ]);
            }
        } else if (isset($request->member_username) && $request->member_username != '') {
            $data = $data->where('m.username',  $request->member_username);
        } else {
            // $agent = Agent::where('id', $request->ID)->first();
            //查登入的這個agent
            $data = $data->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%')->first();
        }

        $model = $data->select('m.username as member_username', 'm.nickname as member_nickname',
            DB::raw("'V-world' as vendor"),
            DB::raw("'-1' as vendor_id"),
            DB::raw("'-' as game_type"), DB::raw('0 as game_type_id'), DB::raw('0 as total_bet'), DB::raw('0 as win_lose'), DB::raw('TRUNCATE(SUM(s.point),2) as lottery'), DB::raw('0 as share_amount'));
        $model = $data->groupBy('m.id');
        return $model;
    }

    private function transactionMemberModel($request)
    {
        // 取客服轉入轉出
        $model = DB::table('transaction_logs as l')->leftJoin('members as m', 'm.id', 'l.member_id');      
        $model = $model->leftJoin('agents as a', 'a.id', 'm.parent');
        if (isset($request->member_username) && $request->member_username != '') {
            $model = $model->where('m.username',  $request->member_username)->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
        } else if (isset($request->member_nickname) && $request->member_nickname != '') {
            $model = $model->where('m.nickname',  $request->member_nickname)->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
        } else if (isset($request->transaction_no) && $request->transaction_no != '') {
            $model = $model->where('l.transaction_no', $request->transaction_no)->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
        } else {
            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username',$request->agent_username)->first();
                if(is_object($agent) && $this->isChild($request->HOST, $agent->id)) {
                    if($request->filter == 1){
                        //查詢代理的開分紀錄, 線上充值不需要區分代理
                        $model = $model->where(function ($query) use ($request, $agent) {
                            $query->where('l.agent_id', '=', $request->HOST)
                                ->orWhere(function ($query) use ($request, $agent) {
                                    $query->where('type', 3)->where('a.hierarchy', 'like', '%,'.$agent->id.',%');
                                });
                        });

                    }else{
                        //所有會員
                        $model = $model->where('a.hierarchy', 'like', '%,'.$agent->id.',%');
                    }    
                } else {
                    $model = $model->where('1', '2');
                }
            }
            else {
                if($request->filter == 1){
                    //查詢代理的開分紀錄, 線上充值不需要區分代理
                    $model = $model->where(function ($query) use ($request) {
                        $query->where('l.agent_id', '=', $request->HOST)
                            ->orWhere(function ($query) use ($request) {
                                $query->where('type', 3)->where('a.hierarchy', 'like', '%,'.$request->HOST.',%');
                            });
                    });
                }else{
                    //所有會員
                    $model = $model->where('a.hierarchy', 'like', '%,'.$request->HOST.',%');
                }    
            } 
        }
        // 類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵
        switch ($request->source) {
            case '1': // 客服開洗分
                $model = $model->whereIn('l.type', [4, 5, 10, 11, 12]);
                break;
            case '2': // 線上儲值
                $model = $model->where('l.type', 3);
                break;
            default: // 全部
                $model = $model->whereIn('l.type', [3, 4, 5, 10, 11, 12]);
                break;
        }
        $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour')));
        $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour')));
        $model = $model->where('l.status', 1000);
        return $model;
    }

    /* 統計這個帳號所有的下線 */
    private function onlinePaymentModel($request, $agentId)
    {
        // 取客服轉入轉出            
        $model = DB::table('transaction_logs as l')->leftJoin('members as m', 'm.id', 'l.member_id');
        // 只能統計自己下線的
        $model = $model->leftJoin('agents as a', 'a.id', 'l.agent_id')
            ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
        // $child = new AgentChild($agentId);
        // $in = $child->listChild();
        // $model = $model->whereIn('a.id', $in[$agentId]);
        $model = $model->where('l.type', 3); //只有線上儲值
        $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour')));
        $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour')));
        $model = $model->where('l.status', 1000);
        return $model;
    }

    private function transactionAgentModel($request)
    {
        if ($request->source != 2) { // 線上儲值不需要客服間開洗分的值
            // 取客服之間互相轉           
            $model = DB::table('transaction_log_agents as la')->leftJoin('agents as t', 't.id', 'la.to_agent_id');
            // 只能統計自己下線的
            // $child = new AgentChild($request->ID);
            // $in = $child->listChild();
            // $model = $model->leftJoin('agents  as f', function ($join) use ($request, $in) {
            //     $join->on('f.id', '=', 'la.from_agent_id')->whereIn('f.id', $in[$request->ID]);
            // });
            // $model = $model->whereNotNull('f.id');
            $model = $model->leftJoin('agents  as f', 'f.id', 'la.from_agent_id')
                ->where('f.hierarchy', 'like', '%,' . $request->HOST . ',%');

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = $model->where('f.username', $request->agent_username);
            } else if (isset($request->member_username) && $request->member_username != '') {
                //代理之間跟會員無關
                return false;
            } else if (isset($request->member_nickname) && $request->member_nickname != '') {
                //代理之間跟會員無關
                return false;
            } else if (isset($request->transaction_no) && $request->transaction_no != '') {
                //代理之間互轉沒單號
                return false;
            } else {
                //都沒輸入查詢自己的
                $model = $model->where('f.id', $request->HOST);
            }

            $model = $model->where('la.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour')));
            $model = $model->where('la.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour')));
            $model = $model->where('la.status', 1);
            return $model;
        } else {
            return null;
        }
    }

    private function platformReconciliation($request, $isAgent)
    {
        //以agent為主
        $data = DB::table('summary_bet_records as s')
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('game_types as t', 't.id', 'g.type_id')
            ->leftJoin('members as m', 'm.id', 's.member_id')
            ->leftJoin('agents as a', 'a.id', 'm.parent')
            ->leftJoin('vendors as v', 'v.id', 's.vendor_id')
            ->whereBetween('s.summary_time', [
                date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')),
                date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'))
            ]);

        if (isset($request->agent_username) && $request->agent_username != '') {
            $agent = Agent::where('username', $request->agent_username)->first();
            if (is_object($agent)) {
                //只能查詢自己或自己下線的，修改子帳號權限$request->ID => $request->HOST
                if ($this->isChild($request->HOST, $agent->id) ||  $agent->id == $request->ID) {
                    //查詢這個代理商下層的代理
                    // $child = new AgentChild($agent->id);
                    // $in = $child->listChild();
                    // $data = $data->whereIn('a.id', $in[$agent->id]);
                    $data = $data->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
                } else {
                    //不是下線
                    return false;
                }
            } else {
                //代理商不存在
                return false;
            }
        } else {
            $agent = Agent::where('id', $request->HOST)->first();
            //查登入的這個agent
            // $child = new AgentChild($agent->id);
            // $in = $child->listChild();
            // $data = $data->whereIn('a.id', $in[$agent->id]);
            $data = $data->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
        }

        if (isset($request->vendor_id) && $request->vendor_id  != '') {
            $data = $data->whereIn('s.vendor_id',  explode(',', $request->vendor_id));
        }

        if (isset($request->game_type) && $request->game_type > 0) {
            $data = $data->where('t.id',  $request->game_type);
        }
        if ($isAgent) {
            // 代理
            $data = $data->where('m.parent', '!=', $agent->id);
            $type = DB::raw("'agent' as type");
        } else {
            // 會員
            $data = $data->where('m.parent', $agent->id);
            $type = DB::raw("'member' as type");
        }
        $model = $data->select(
            $type,
            DB::raw("'" . $agent->id . "' as agent_id"),
            DB::raw("'" . $agent->username . "' as agent_username"),
            DB::raw("'" . $agent->nickname . "' as agent_nickname"),
            'v.name as vendor',
            'v.id as vendor_id',
            't.name as game_type',
            't.id as game_type_id',
            DB::raw('TRUNCATE(SUM(s.bet),2) as total_bet'),
            DB::raw('TRUNCATE(SUM(s.win_lose),2) as win_lose'),
            DB::raw('TRUNCATE(SUM(s.jackpot),2) as lottery'),
            's.share_amount'
        );

        return $model;
    }

    private function platformReconciliationEventTotal($request, $isAgent)
    {
        //以agent為主
        $data = DB::table('transaction_logs as s')
            ->leftJoin('members as m', 'm.id', 's.member_id')
            ->leftJoin('agents as a', 'a.id', 'm.parent')
            ->whereIn('s.type', [13, 15]) // 活動獎勵編號為13, 登入活動為15
            ->whereBetween(
                's.created_at',
                [
                    date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')),
                    date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0, -2) . '59-8hour'))
                ]
            );

        if (isset($request->agent_username) && $request->agent_username != '') {
            $agent = Agent::where('username', $request->agent_username)->first();
            if (is_object($agent)) {
                //只能查詢自己或自己下線的，修改子帳號權限，$request->ID => $request->HOST
                if ($this->isChild($request->HOST, $agent->id) ||  $agent->id == $request->ID) {
                    //查詢這個代理商下層的代理
                    // $child = new AgentChild($agent->id);
                    // $in = $child->listChild();
                    // $data = $data->whereIn('a.id', $in[$agent->id]);
                    $data = $data->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
                } else {
                    //不是下線
                    return false;
                }
            } else {
                //代理商不存在
                return false;
            }
        } else {
            $agent = Agent::where('id', $request->HOST)->first();
            //查登入的這個agent
            // $child = new AgentChild($agent->id);
            // $in = $child->listChild();
            // $data = $data->whereIn('a.id', $in[$agent->id]);
            $data = $data->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
        }

        if ($isAgent) {
            // 代理
            $data = $data->where('m.parent', '!=', $agent->id);
            $type = DB::raw("'agent' as type");
        } else {
            // 會員
            $data = $data->where('m.parent', $agent->id);
            $type = DB::raw("'member' as type");
        }
        $model = $data->select($type, DB::raw("'" . $agent->id . "' as agent_id"), DB::raw("'" . $agent->username . "' as agent_username"), DB::raw("'" . $agent->nickname . "' as agent_nickname"), DB::raw("'V-world' as vendor"), DB::raw("'-1' as vendor_id"), DB::raw("'-' as game_type"), DB::raw('0 as game_type_id'), DB::raw('0 as total_bet'), DB::raw('0 as win_lose'), DB::raw('TRUNCATE(SUM(s.point),2) as lottery'), DB::raw('0 as share_amount'))
            ->groupBy('vendor');

        return $model;
    }

    private function platformReconciliationDetailModel($request, $isAgent, $temp)
    {
        $data = DB::table('summary_bet_records as s')
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('vendors as v', 'v.id', 's.vendor_id')
            ->leftJoin('game_types as t', 't.id', 'g.type_id')
            ->leftJoin('members as m', 'm.id', 's.member_id')
            ->leftJoin(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'm.parent')
            ->mergeBindings($temp)
            ->whereBetween('s.summary_time', [date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')), date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'))]);
        if (isset($request->vendor_id) && $request->vendor_id  != '') {
            $data = $data->whereIn('s.vendor_id',  explode(',', $request->vendor_id));
        }

        if (isset($request->game_type) && $request->game_type > 0) {
            $data = $data->where('t.id',  $request->game_type);
        }
        if ($isAgent) {
            // 代理
            $data = $data->where('m.parent', '!=', DB::raw('temp.agent_id'));
            $type = DB::raw("'agent' as type");
        } else {
            // 會員
            $data = $data->where('m.parent', DB::raw('temp.agent_id'));
            $type = DB::raw("'member' as type");
        }
        $model = $data->select($type, 'temp.agent_id as agent_id',  'temp.username  as agent_username', 'temp.nickname  as agent_nickname', 'v.name as vendor', 'v.id as vendor_id', 't.name as game_type', 't.id as game_type_id', DB::raw('TRUNCATE(SUM(s.bet),2) as total_bet'), DB::raw('TRUNCATE(SUM(s.win_lose),2) as win_lose'), DB::raw('TRUNCATE(SUM(s.jackpot),2) as lottery'), 's.share_amount');
        return $model;
    }

    private function platformReconciliationEventModel($request, $isAgent, $temp)
    {
        $data = DB::table('transaction_logs as s')
            ->leftJoin('members as m', 'm.id', 's.member_id')
            ->leftJoin(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 'm.parent')
            ->mergeBindings($temp)
            ->whereIn('s.type', [13, 15]) // 活動獎勵編號為13, 登入活動為15
            ->whereBetween(
                's.created_at',
                [
                    date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')),
                    date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0, -2) . '59-8hour'))
                ]
            );
        if ($isAgent) {
            // 代理
            $data = $data->where('m.parent', '!=', DB::raw('temp.agent_id'));
            $type = DB::raw("'agent' as type");
        } else {
            // 會員
            $data = $data->where('m.parent', DB::raw('temp.agent_id'));
            $type = DB::raw("'member' as type");
        }
        $data = $data->select($type, 'temp.agent_id as agent_id',  'temp.username  as agent_username', 'temp.nickname  as agent_nickname',  DB::raw("'V-world' as vendor"), DB::raw("'-1' as vendor_id"), DB::raw("'-' as game_type"), DB::raw('0 as game_type_id'), DB::raw('0 as total_bet'), DB::raw('0 as win_lose'), DB::raw('TRUNCATE(SUM(s.point),2) as lottery'), DB::raw('0 as share_amount'))
            ->groupBy('temp.agent_id'); //加上group沒記錄的才不會出現0
        return $data;
    }


    /**
     * report/prizeReport
     * -活動獎勵領取記錄
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc 
     * @bodyParam member_username string 會員編號 Example: def
     * @bodyParam event_id integer required 活動編號 Example: 1
     * @bodyParam start_time date required 開始日期 Example: 2019-01-01 00
     * @bodyParam end_time date required 結束日期 Example: 2019-12-31 01
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "updated_at": "兌換時間,ex:1",
     *            "event_title": "活動名稱,ex:abc",
     *            "member_username": "會員帳號,ex:abc",
     *            "agent_username": "上線代理,ex:ag",
     *            "prize_name": "獎項名稱,ex:1",
     *            "prize_point": "點數,ex:slot"
     *        }
     *    ]
     * }
     */
    public function prizeReport(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'event_id' => 'required|digits_between:1,10',
                'page' => 'required|numeric',
                'per_page' => 'required|numeric'
            ];

            // 驗證傳入參數格式是否正確
            $validator = Validator::make($request->all(), $rules, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),

                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $title = DB::raw("(CASE WHEN n.title is NULL THEN e.title ELSE n.title END) as event_title");
            $model = DB::table('prize_members as pm')->leftJoin('prize_contents as c', 'c.id', 'pm.content_id')
                ->leftJoin('prizes as p', 'p.id', 'pm.prize_id')
                ->leftJoin('events as e', 'e.id', 'p.event_id')
                ->leftJoin('members as m', 'm.id', 'pm.member_id')
                ->leftJoin('agents as a', 'a.id', 'm.parent')
                ->leftJoin('event_names as n', function ($join) use ($request) {
                    $join->on('n.event_id', '=', 'e.id')
                        ->where('n.language_id', '=', $request->LANGUAGE);
                })
                ->where('pm.status', 1)
                ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%')
                ->select('pm.updated_at', $title, 'm.username as member_username', 'a.username as agent_username', 'c.name as prize_name', 'c.point as prize_point');
            if (isset($request->event_id) && $request->event_id != '') {
                $model = $model->where('e.id', $request->event_id);
            } else if ($request->event_id == 0) {
                $model = $model->where('e.id', 0);
            }
            //總數
            $total = $model->getCountForPagination();
            // 排序欄位
            $data = $model
                ->orderBy('pm.updated_at', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page)
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
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


    private function rule()
    {
        return [
            'agent_id' => 'digits_between:0,10',
            'agent_username' => 'r_alpha_num|between:0,30',
            'member_id' => 'digits_between:0,10',
            'member_username' => 'r_alpha_num|between:0,30',
            'start_date' => 'required|date_format:Y-m-d',
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date'
        ];
    }

    private function messages()
    {
        return [

            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'member_id.digits_between' => __('error_message.1067'),

            'agent_username.r_alpha_num' => __('error_message.1025'),
            'agent_username.between' => __('error_message.1024'),

            'member_username.r_alpha_num' => __('error_message.1025'),
            'member_username.between' => __('error_message.1024'),

            'member_nickname.between' => __('error_message.2024'),

            'transaction_no.digits_between' => __('error_message.1310'),

            'event_id.required' => __('error_message.1902'),
            'event_id.digits_between' => __('error_message.1903'),

            'source.required' => __('error_message.2035'),
            'source.in' => __('error_message.2035'),

            'start_date.required' => __('error_message.2011'),
            'start_date.date_format' => __('error_message.2013'),

            'end_date.required' => __('error_message.2012'),
            'end_date.date_format' => __('error_message.2014'),
            'end_date.after' => __('error_message.2015'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}