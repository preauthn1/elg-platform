<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use App\Helpers\Common;
use App\Helpers\ArrayHelper;
use App\Helpers\SQLHelper;
use App\Models\Order;
use App\Models\TransactionLog;
use App\Models\Agent;
use App\Models\Member;
use App\Models\SummaryBetRecord;
use App\Models\AgentCustomizedSetting;
use App\Services\UnlimitedService;
use App\Services\ReportService;
use App\Repositories\AgentRepository;
use App\Repositories\AgentCustomizedSettingRepository;
use App\REDIS;
use App\AgentChild;
use Validator;
use DB;
use Log;

class ReportController extends Controller
{
    protected $unlimitedService;
    protected $reportService;
    protected $agentCustomizedSettingRepository;

    /**
     * ReportController constructor.
     */
    public function __construct()
    {
        $this->unlimitedService = new UnlimitedService();
        $this->reportService = new ReportService();
        $this->agentCustomizedSettingRepository = new AgentCustomizedSettingRepository();
    }

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
            $model = DB::table('transaction_logs as l')
                ->leftJoin('members as m', 'm.id', 'l.member_id')
                ->leftJoin('orders as o', 'l.order_id', 'o.id')
                ->leftJoin('agent_cash_flows as acf', 'o.agent_cash_flow_id', 'acf.id')
                ->leftJoin('cash_flows as cf', 'acf.cash_flow_id', 'cf.id');

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
                    case 3: // 線上儲值
                        $model = $model->where('type', 3);
                        break;
                }
            } else {
                $model = $model->whereIn('type', [3, 4, 5, 10, 11, 12]);
            }

            //篩選cash_flow_id
            if (isset($request->cash_flow_id) && $request->cash_flow_id != '') {
                if ($request->cash_flow_id == 2) {
                    $model = $model->where(function ($query) use ($request) {
                        $query->where('acf.cash_flow_id', $request->cash_flow_id)->orWhere('o.agent_cash_flow_id', 0);
                    });
                } else {
                    $model->where('acf.cash_flow_id', $request->cash_flow_id);
                }
            }

            $model = $model->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')));
            $model = $model->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour')));
            $model = $model->where('l.status', 1000);
            $data = $model->groupBy(DB::raw('a.id'))
                ->select(
                    'a.username as agent_username',
                    DB::raw('(sum((CASE l.type WHEN 3 THEN l.point ELSE 0 END))+sum((CASE l.type WHEN 4 THEN l.point ELSE 0 END))) as total_in'),
                    DB::raw('sum((CASE l.type WHEN 5 THEN l.point ELSE 0 END)) as total_out')
                )
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
            $model = DB::table('transaction_logs as l')
                ->join('members as m', 'm.id', 'l.member_id')
                ->join('agents as a', 'a.id', 'm.parent');
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
            $model = DB::table('transaction_logs as l')
                ->leftJoin('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'l.member_id');
                    if (isset($request->member_username) && $request->member_username != '') {
                        $join->where('m.username',  $request->member_username);
                    }
                    if (isset($request->member_nickname) && $request->member_nickname != '') {
                        $join->where('m.nickname',  $request->member_nickname);
                    }
                })
                ->leftJoin('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    if (isset($request->agent_username) && $request->agent_username != '') {
                        $join->where('a.username', $request->agent_username);
                    }
                })
                ->whereBetween(
                    'l.created_at',
                    [
                        date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')),
                        date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour'))
                    ]
                )
                ->where('l.status', 1000)
                ->whereIn('l.type', [4, 5]);
            if (isset($request->transaction_no) && $request->transaction_no != '') {
                $model = $model->where('l.id', $request->transaction_no);
            }
            $data = $model->orderBy('l.created_at', 'DESC')
                ->select(
                    'l.id',
                    'l.transaction_no',
                    DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'),
                    'a.username as agent_username',
                    DB::raw('(CASE l.type WHEN 3 THEN 1 WHEN 4 THEN 1 WHEN 5 THEN 2 END) as type'),
                    'm.username as member_username',
                    'm.nickname as member_nickname',
                    'l.point',
                    'l.balance',
                    DB::raw('(CASE l.type WHEN 3 THEN 0 WHEN 4 THEN 1 WHEN 5 THEN 1 END) as source'),
                    'l.operator as operator_username',
                    DB::raw('(CASE  WHEN l.status=1000 THEN 1 ELSE 0 END) as status'),
                    'remark'
                )
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
     * @bodyParam vendor_id integer required 遊戲商編號 Example: 1
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
            $model = DB::table('transaction_logs as l')
                ->join('vendors as v', function ($join) use ($request) {
                    $join->on('v.id', 'l.vendor_id');
                    if (isset($request->vendor_id)) {
                        $vendors = (is_array($request->vendor_id))
                            ? $request->vendor_id
                            : explode(',', $request->vendor_id);
                        $join->whereIn('l.vendor_id', $vendors);
                    }
                })
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'l.member_id');
                    if (isset($request->member_username) && $request->member_username != '') {
                        $join->where('m.username',  $request->member_username);
                    }
                    if (isset($request->member_nickname) && $request->member_nickname != '') {
                        $join->where('m.nickname', $request->member_nickname);
                    }
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        // 只能統計自己下線的
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');

                    if (isset($request->agent_username) && $request->agent_username != '') {
                        $join->where('a.username', $request->agent_username);
                    }
                })
                //取得代理要顯示的遊戲商名稱
                ->leftJoin('vendor_customized_items as vi', function ($join) {
                    $join->on('vi.agent_group_id', 'a.vendor_customize_group_id')
                        ->on('vi.vendor_id', 'v.id')
                        ->on('vi.item_type', DB::raw(1));
                })
                ->whereBetween(
                    'l.created_at',
                    [
                        date('Y-m-d H:i:s', strtotime($request->start_date . ' 00:00:00-8hour')),
                        date('Y-m-d H:i:s', strtotime($request->end_date . ' 23:59:59-8hour'))
                    ]
                )
                ->whereIn('l.type', [1, 2]);

            if (isset($request->transaction_no) && $request->transaction_no != '') {
                $model = $model->where('l.id', $request->transaction_no);
            }

            if ($request->status == 1) {
                $model = $model->where('l.status', 1000);
            } else if ($request->status == 2) {
                $model = $model->where('l.status', '!=', 1000);
            }

            //總數
            $total = $model->getCountForPagination();
            // 排序欄位
            $data = $model->orderBy('l.created_at', 'desc')
                ->select(
                    'l.id',
                    'l.transaction_no',
                    'l.vendor_id', //另一頁要用到的
                    'l.created_at as created_at_utc', //另一頁要用到的
                    DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'),
                    'a.username as agent_username',
                    DB::raw('IFNULL(vi.name, v.name) as vendor'),
                    'l.type',
                    'm.username as member_username',
                    'm.nickname as member_nickname',
                    'l.point',
                    'l.balance',
                    'l.status'
                )
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
     * report/sr08
     * -SR08 補點報表
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
     *            "created_at": "建立日期,ex:2020-01-01 00:00:00",
     *            "member_username": "會員名稱,ex:aaa"
     *            "status": "狀態,ex:1000"
     *            "point": "補點金額,ex:100"
     *            "balance": "補點後錢包金額,ex:1100"
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
    public function sr08(Request $request)
    {
        try {
            $rules = [
                'username' => 'r_alpha_num|between:0,30',
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

            $model = DB::table('transaction_logs as l')
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'l.member_id');
                    if (isset($request->username) && $request->username != '') {
                        $join->where('m.username', $request->username);
                    }
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                })
                ->where('l.type', 14)
                ->whereBetween(
                    'l.created_at',
                    [
                        date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')),
                        date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'))
                    ]
                )
                ->where('l.status', 1000);
            //總數
            $total = $model->getCountForPagination();
            // 排序欄位
            $data = $model->orderBy('l.created_at', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select('l.id as id',
                    DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'),
                    'l.operator as operator',
                    'a.username as agent_username',
                    'm.username as member_username',
                    'l.status as status',
                    'l.point as point',
                    'l.remark as remark',
                    'l.balance as balance'
                )
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
            $data = DB::table('summary_bet_records as s')
                ->leftJoin('games as g', 'g.id', 's.game_id')
                ->leftJoin('game_types as t', 't.id', 'g.type_id')
                ->leftJoin('members as m', 'm.id', 's.member_id')
                ->leftJoin('agents as a', 'a.id', 'm.parent')
                ->leftJoin('vendors as v', 'v.id', 's.vendor_id')
                ->whereBetween(
                    's.summary_time',
                    [
                        date('Y-m-d H:i:s', strtotime($request->start_time . ' 00:00:00-8hour')),
                        date('Y-m-d H:i:s', strtotime($request->end_time . ' 23:59:59-8hour'))
                    ]
                );

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
            $data = $data
                ->select(
                    DB::raw("'" . $agent->id . "'" . ' as agent_id'),
                    DB::raw("'" . $agent->username . "'" . ' as agent_username'),
                    DB::raw("'" . $agent->nickname . "'" . ' as agent_nickname'),
                    'v.name as vendor',
                    'v.id as vendor_id',
                    't.name as game_type',
                    't.id as game_type_id',
                    DB::raw('SUM(s.bet) as total_bet'),
                    DB::raw('SUM(s.win_lose) as win_lose'),
                    DB::raw('SUM(s.jackpot) as lottery'),
                    's.share_amount'
                )
                ->groupBy('v.id')
                ->groupBy('t.id')
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
            $data = DB::table('summary_bet_records as s')
                ->leftJoin('games as g', 'g.id', 's.game_id')
                ->leftJoin('game_types as t', 't.id', 'g.type_id')
                ->leftJoin('members as m', 'm.id', 's.member_id')
                ->leftJoin('agents as a', 'a.id', 'm.parent')
                ->leftJoin('vendors as v', 'v.id', 's.vendor_id')
                ->whereBetween(
                    's.summary_time',
                    [
                        date('Y-m-d H:i:s', strtotime($request->start_time . ' 00:00:00-8hour')),
                        date('Y-m-d H:i:s', strtotime($request->end_time . ' 23:59:59-8hour'))
                    ]
                );

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

            $data = DB::table('summary_bet_records as s')
                ->leftJoin('games as g', 'g.id', 's.game_id')
                ->leftJoin('game_types as t', 't.id', 'g.type_id')
                ->leftJoin('members as m', 'm.id', 's.member_id')
                ->leftJoin('agents as a', 'a.id', 'm.parent')
                ->leftJoin('vendors as v', 'v.id', 's.vendor_id')
                ->whereBetween(
                    's.summary_time',
                    [
                        date('Y-m-d H:i:s', strtotime($request->start_time . ' 00:00:00-8hour')),
                        date('Y-m-d H:i:s', strtotime($request->end_time . ' 23:59:59-8hour'))
                    ]
                );

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
     * @bodyParam game_type integer 遊戲類別 Example: 1
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
     *            "valid_bet_amount": "有效投注額,ex:100",
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
                'game_no' => 'r_alpha_dash|between:0,50',
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

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'));

            $data = DB::table('total_bet_records as b')
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'b.member_id');
                    if (isset($request->member_username) && $request->member_username != '') {
                        $join->where('m.username', $request->member_username);
                    }
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy_path', 'like', $request->HIERARCHY_PATH . '%');
                    if (isset($request->agent_username) && $request->agent_username != '') {
                        $join->where('a.username', $request->agent_username);
                    }
                })
                ->join('vendors as v', function ($join) use ($request) {
                    $join->on('v.id', 'b.vendor_id');
                })
                ->leftJoin('games as g', function ($join) use ($request) {
                    $join->on('g.id', 'b.game_id');

                    //單號
                    if (isset($request->game_type) && $request->game_type != '') {
                        $join->where('g.type_id', $request->game_type);
                    }
                })
                //取得代理要顯示的遊戲商名稱
                ->leftJoin('vendor_customized_items as vi', function ($join) {
                    $join->on('vi.agent_group_id', 'a.vendor_customize_group_id')
                        ->on('vi.vendor_id', 'v.id')
                        ->on('vi.item_type', DB::raw(1));
                })
                ->where('b.vendor_id', ((isset($request->vendor_id) && $request->vendor_id != '') ? $request->vendor_id : 0))
                //搜尋時間1:押注時間2:結算時間
                ->where(($request->whitch_time == 2) ? 'b.payout_time' : 'b.bet_time', '>=', $start_time)
                ->where(($request->whitch_time == 2) ? 'b.payout_time' : 'b.bet_time', '<', $end_time);

            //單號
            if (isset($request->game_no) && $request->game_no != '') {
                $data = $data->where('b.reference_no', $request->game_no);
            } elseif ($request->member_username == '' && $request->agent_username == '') {
                $data = $data->where('a.id', $request->HOST);
            }

            /*
            //如果是小艾，把bet_records 和 daily_win union後在算頁數
            if ($request->vendor_id == 21) {
                $data = $data->union($dailydata);
                $dataSql = $data->toSql();
                $data = DB::table(DB::raw("($dataSql) as b"))->mergeBindings($data);
            }
            */

            //總數
            $summary_data = $data->select(
                    DB::raw('sum(b.bet) as bet_amount'),
                    DB::raw('sum(b.valid_bet) as valid_bet_amount'),
                    DB::raw('sum(b.valid_bet + b.win_lose) as payout'),
                    DB::raw('sum(b.win_lose) as win_lose'),
                    DB::raw('sum(b.commission) as commission'),
                    DB::raw('count(*) as cnt')
                )
                ->first();
            //$total = $data->getCountForPagination();
            $total = $summary_data->cnt;

            // 排序欄位
            switch ($request->sort_by) {
                case 1: //投注時間
                    $data = $data->orderBy('b.bet_time', 'desc');
                    break;
                case 2: //投注金額
                    $data = $data->orderBy('b.bet', 'desc');
            }

            $data = $data->orderBy('b.id', 'desc')
                ->select(
                    'b.reference_no as game_no',
                    'm.username as member_username',
                    'a.username as agent_username',
                    DB::raw('IFNULL(vi.name, v.name) as vendor_name'),
                    'g.name as game_name',
                    'b.bet as bet_amount',
                    'b.valid_bet as valid_bet_amount',
                    DB::raw('b.valid_bet + b.win_lose as lottery'),
                    'b.win_lose as win_lose',
                    'b.commission as commission',
                    DB::raw('0 as service_fee'),
                    DB::raw('DATE_ADD(b.bet_time,INTERVAL 8 HOUR) as bet_time'),
                    DB::raw('DATE_ADD(b.payout_time,INTERVAL 8 HOUR) as settlement_time'),
                    DB::raw("1 as bet_status"),
                    DB::raw("'-' as remark")
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'summary_data' => $summary_data,
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
     * report/sr14
     * -SR14 現金紀錄表
     *
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam start_time time required 開始日期 Example: 2019-01-01 00:00:00
     * @bodyParam end_time time required 結束日期 Example: 2019-12-31 00:00:00
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "created_at": "時間,ex:2019-01-02 00:00:00",
     *            "member_username": "會員帳號,ex:abc",
     *            "agent_username": "代理商帳號,ex:abc",
     *            "before_balance": "原始点数,ex:100",
     *            "point": "变更点数,ex:100",
     *            "balance": "当下点数,ex:100",
     *            "type": "類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,...,ex:1",
     *            "status": "狀態編號,ex:1000",
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
    public function sr14(Request $request)
    {
        try {
            $data = $this->reportService->getSr14Report($request);
            return response()->json($data);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }




    /**
     * report/sr15
     * -SR15 代理對帳報表
     *
     * @bodyParam username string 代理帳號 Example: abc
     * @bodyParam start_time time required 開始日期 Example: 2019-01-01 00:00:00
     * @bodyParam end_time time required 結束日期 Example: 2019-12-31 00:00:00
     * @bodyParam hidden_zero_data boolean 是否顯示總投注為零的資料 Example: 1
     * @bodyParam cost_visible boolean 成本顯示 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *
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
    public function sr15(Request $request)
    {
        try {
            $rep = new AgentRepository();
            $queryCurrentAgent = $rep->getById($request->HOST);
            $hierarchy_path = $queryCurrentAgent->hierarchy_path;

            $request->expired_time = $this->getExpiredTime();

            //先查出目前搜尋條件的 指定的root是誰
            $queryTargetParent = DB::table('agents as a');
            $isQueryAgentSelf = false;

            if (Common::IsNullOrEmptyString($request->username) &&
                Common::IsNullOrEmptyString($request->parent_username)) {
                return response()->json([
                    'code' => 1026,
                    'message' => __('error_message.1026')
                ]);
            }

            if (!Common::IsNullOrEmptyString($request->username)) {
                $isQueryAgentSelf = true;
                $queryTargetParent->where('a.username', $request->username);
            }

            if (!Common::IsNullOrEmptyString($request->parent_username)) {
                $queryTargetParent->where('a.username', $request->parent_username);
            }

            $queryTargetParent = $queryTargetParent->first();

            if (!is_object($queryTargetParent)) {
                return response()->json([
                    'code' => 1050,
                    'message' => __('error_message.1050')
                ]);
            }

            //丟過來的vendorIDs 會決定是否要額外查某些table
            $vendorIDs = explode(',', $request->vendor_id);
            $isIncludeEventReport = in_array(-1,  $vendorIDs);
            $isIncludeUnlimitAgentReport = in_array(-2,  $vendorIDs);
            $isIncludePaymentReport = in_array(-3,  $vendorIDs);
            $isIncludeSmsReport = in_array(-4,  $vendorIDs);

            //會根據 isQueryAgentSelf 決定要走哪一種SQL語法
            $sql_typeOfSelf = DB::raw($queryTargetParent->id." AS agent_id"); //查到的代理都歸類於自己底下
            $sql_typeOfChild = DB::raw("SUBSTRING_INDEX(SUBSTR(hierarchy, 1, LOCATE('," . $queryTargetParent->id . ",', hierarchy) - 1), ',', -1) AS agent_id");

            $join_hierarchy_SQL = DB::table('agents as ta')
                ->whereRaw("hierarchy LIKE '%," . $queryTargetParent->id . ",%'")
                ->select(
                    DB::raw('id AS child'),
                    $isQueryAgentSelf ? $sql_typeOfSelf : $sql_typeOfChild
                );

            //主要兩個大join在這裡
            $sub_query_bet_summary = $this->sr15_betRecords_subQuery($request, $join_hierarchy_SQL, $isIncludePaymentReport, $isIncludeSmsReport);
            $sub_query_event_bonus = $this->sr15_eventAndBonus_subQuery($request, $join_hierarchy_SQL);
            $query_unlimit_bonus = $this->sr15_query_unlimit_bonus($request, $join_hierarchy_SQL);

            DB::enableQueryLog();

            $query = DB::table('agents as main_a')
                ->join('agents as p', function ($join) use ($request) {
                    $join->on('main_a.parent', 'p.id');
                    if (!Common::IsNullOrEmptyString($request->parent_username)) {
                        $join->where('p.username', $request->parent_username);
                    }
                })
                ->leftJoin(DB::raw('(' . $sub_query_bet_summary->toSql() . ') as bet_summary' ), 'main_a.id', 'bet_summary.agent_id')
                ->mergeBindings($sub_query_bet_summary)
                ->leftJoin(DB::raw('(' . $sub_query_event_bonus->toSql() . ') as event_bonus' ), 'main_a.id', 'event_bonus.agent_id')
                ->mergeBindings($sub_query_event_bonus)
                ->leftJoin(DB::raw('(' . $query_unlimit_bonus->toSql() . ') as unlimited' ), 'main_a.id', 'unlimited.agent_id')
                ->mergeBindings($query_unlimit_bonus);

            $query = $query->where('main_a.hierarchy_path', 'like', $hierarchy_path.'%');

            if (!Common::IsNullOrEmptyString($request->username)) {
                $query->where("main_a.username", $request->username);
            }

            if ($request->hidden_zero_data == 1) {
                $query = $query->where(function ($q) use ($isIncludeEventReport, $isIncludeUnlimitAgentReport, $request) {
                    $q->where('bet_summary.total_bet', '>', 0)
                        ->orWhere('bet_summary.win_lose', '>', 0)
                        ->orWhere('bet_summary.commission', '>', 0)
                        ->orWhere('bet_summary.jackpot', '>', 0)
                        ->orWhere('bet_summary.v_jackpot', '>', 0);
                    if ($isIncludeUnlimitAgentReport) {
                        $q->orWhere('unlimited.bonus_direct', '>', 0)
                            ->orWhere('unlimited.bonus_team', '>', 0);
                    }
                    if ($request->cost_visible) {
                        $q->orWhere('bet_summary.cost', '>', 0);
                    }
                    if ($isIncludeEventReport) {
                        $q->orWhere('event_bonus.point', '>', 0);
                    }
                });
            }

            $total = $query->count();

            $queryResult = $query->orderBy('main_a.username', 'ASC')
                ->select(
                    'main_a.id as agent_id',
                    'main_a.username',
                    'main_a.parent',
                    'p.username as parent_username',
                    'bet_summary.total_bet as total_bet',
                    'bet_summary.win_lose as win_lose',
                    'bet_summary.commission as commission',
                    'bet_summary.jackpot as jackpot',
                    'bet_summary.v_jackpot as v_jackpot',
                    'bet_summary.cost as cost',
                    'event_bonus.point as point',
                    'unlimited.bonus_direct as bonus_direct',
                    'unlimited.bonus_team as bonus_team'
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            //因為這個有union語法 導致mergeBindings 怪怪的 要獨立用查的
            $query_unlimit_bonus = [];

            if ($isIncludeUnlimitAgentReport) {
                $query_unlimit_bonus = $this->sr15_query_unlimit_bonus($request, $join_hierarchy_SQL);
            }

            foreach ($queryResult as $item) {
                /*
                $match = ArrayHelper::isNullOrEmptyArray($query_unlimit_bonus)
                    ? null
                    : $query_unlimit_bonus->where('agent_id', $item->agent_id)->first();
                */
                //把null設成0
                $item->total_bet = $this->setNullToZeroAmount($item->total_bet);
                $item->win_lose = $this->setNullToZeroAmount($item->win_lose);
                $item->commission = $this->setNullToZeroAmount($item->commission);
                $item->jackpot = $this->setNullToZeroAmount($item->jackpot);
                $item->v_jackpot = $this->setNullToZeroAmount($item->v_jackpot);
                $item->point = $this->setNullToZeroAmount($item->point);
                $item->cost = $this->setNullToZeroAmount($item->cost);
                //$item->bonus_direct = 0;
                $item->bonus_direct = ($isIncludeUnlimitAgentReport)
                    ? $this->setNullToZeroAmount($item->bonus_direct)
                    : 0 ;
                //$item->bonus_team = 0;
                $item->bonus_team = ($isIncludeUnlimitAgentReport)
                    ? $this->setNullToZeroAmount($item->bonus_team)
                    : 0 ;

                /*
                if (is_object($match)) {
                    $item->bonus_direct = $match->bonus_direct;
                    $item->bonus_team = $match->bonus_team;
                }
                */

                //如果指定不含 vworld活動類的數據 強制讓此變數歸0
                if (!$isIncludeEventReport) {
                    $item->point = 0;
                }
                if ($request->cost_visible == 0) {
                    $item->cost = 0;
                }

                /*
                $item->jackpot_event = ($match == null)
                    ? round($item->point + $item->bonus_direct + $item->bonus_team + $item->jackpot, 2)
                    : ($item->point + $item->bonus_direct + $item->bonus_team + $item->jackpot);
                */
                $item->jackpot_event = round($item->point + $item->bonus_direct + $item->bonus_team + $item->jackpot, 2);
                $item->total_score = $item->win_lose + $item->commission + $item->jackpot_event + $item->v_jackpot + $item->cost;
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $queryResult,
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


    private function sr15_betRecords_subQuery($request, $join_hierarchy_SQL, $isIncludePaymentReport = 0, $isIncludeSmsReport = 0)
    {
        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'));
        //如果查詢的開始/結束時間大於結帳時間, 就用結帳時間當開始/結束時間
        if ($start_time > $request->expired_time)  $start_time = $request->expired_time;
        if ($end_time > $request->expired_time) $end_time = $request->expired_time;

        $data = DB::table('summary_bet_records as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join('agents as a', 'a.id', 'm.parent')
            ->join('vendors as v', function ($join) use ($request) {
                $join->on('v.id', 's.vendor_id');
                if (!Common::IsNullOrEmptyString($request->vendor_id)) {
                    $join->whereIn('v.id',  explode(',', $request->vendor_id));
                }
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time);

        $debug = $request->vendor_id;

        $groupBySQL = $data->groupBy('a.id')
            ->select(
                'a.id as agent_id',
                DB::raw('IFNULL(SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.bet END), 0) as total_bet'),
                DB::raw('IFNULL(SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.win_lose END), 0) as win_lose'),
                DB::raw('IFNULL(SUM(s.commission), 0) as commission'),
                DB::raw('IFNULL(SUM(s.jackpot), 0) as jackpot'),
                DB::raw('IFNULL(SUM(s.v_jackpot), 0) as v_jackpot'),
                DB::raw('IFNULL(SUM(TRUNCATE(s.cost, 4)), 0) as cost')
            );

        $finalSubQuery = DB::table(DB::raw('(' . $join_hierarchy_SQL->toSql() . ') as report_onwer'))
            ->leftJoin(DB::raw("({$groupBySQL->toSql()}) as bet_total"), 'bet_total.agent_id', 'report_onwer.child')
            ->mergeBindings($groupBySQL);
        if ($request->cost_visible && ($isIncludePaymentReport || $isIncludeSmsReport)) {
            $event_ids = array();
            if ($isIncludePaymentReport) {
                $event_ids[] = 3;
            }
            if ($isIncludeSmsReport) {
                $event_ids[] = 4;
            }
            $eventSubQuery = DB::table('summary_event_records as e')
                ->join('members as m', 'm.id', 'e.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('e.summary_time', '>=', $start_time)
                ->where('e.summary_time', '<', $end_time)
                ->whereIn('e.event_id', $event_ids)
                ->groupBy('a.id')
                ->select(
                    'a.id as agent_id',
                    DB::raw('IFNULL(SUM(TRUNCATE(e.point, 4)), 0) as cost')
                );
            $finalSubQuery = $finalSubQuery
                ->leftJoin(DB::raw("({$eventSubQuery->toSql()}) as event_total"), 'event_total.agent_id', 'report_onwer.child')
                ->mergeBindings($eventSubQuery);
        }
        $finalSubQuery = $finalSubQuery->groupBy('report_onwer.agent_id')
            ->select(
                'report_onwer.agent_id as agent_id',
                DB::raw('SUM(IFNULL(bet_total.total_bet, 0)) as total_bet'),
                DB::raw('SUM(IFNULL(bet_total.win_lose, 0))  as win_lose'),
                DB::raw('SUM(IFNULL(bet_total.commission, 0))  as commission'),
                DB::raw('SUM(IFNULL(bet_total.jackpot, 0)) as jackpot'),
                DB::raw('SUM(IFNULL(bet_total.v_jackpot, 0)) as v_jackpot'),
                ($isIncludePaymentReport || $isIncludeSmsReport)
                    ? DB::raw('SUM(IFNULL(bet_total.cost, 0) + IFNULL(event_total.cost, 0)) as cost')
                    : DB::raw('SUM(IFNULL(bet_total.cost, 0)) as cost')
            );

        return $finalSubQuery;
    }

    private function sr15_eventAndBonus_subQuery($request, $join_hierarchy_SQL)
    {
        $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
        $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour'));
        //如果查詢的開始/結束時間大於結帳時間, 就用結帳時間當開始/結束時間
        if ($start_time > $request->expired_time)  $start_time = $request->expired_time;
        if ($end_time > $request->expired_time) $end_time = $request->expired_time;

        $data = DB::table('transaction_logs as s')
            ->join('members as m', 'm.id', 's.member_id')
            ->join('agents as a', 'a.id', 'm.parent')
            ->where('s.created_at', '>=', $start_time)
            ->where('s.created_at', '<', $end_time)
            // 13:活動獎勵, 15:登入活動, 19:首充活動返利, 20:註冊送, 21:綁定送, 24:充值活動返利, 25:每日充值活動返利, 30:W-jackpot
            ->whereIn('s.type', [13, 15, 19, 20, 21, 24, 25, 26, 30, 101]);

        $groupByResult = $data
            ->groupBy('a.id')
            ->select(
                'a.id as agent_id',
                DB::raw('SUM(TRUNCATE(s.point, 4)) as point')
            );

        $finalSubQuery = DB::table( DB::raw("({$groupByResult->toSql()}) as tran_log") )
            ->mergeBindings($groupByResult)
            ->join(DB::raw('(' . $join_hierarchy_SQL->toSql() . ') as report_onwer'), 'report_onwer.child', 'tran_log.agent_id')
            ->groupBy('report_onwer.agent_id')
            ->select(
                'report_onwer.agent_id as agent_id',
                DB::raw('SUM(tran_log.point) as point')
            );

        return $finalSubQuery;
    }

    private function sr15_query_unlimit_bonus($request, $join_hierarchy_SQL)
    {
        $start_time = $request->start_time;
        $end_time = $request->end_time;
        //如果查詢的開始/結束時間大於結帳時間, 就用結帳時間當開始/結束時間
        if ($start_time > $request->expired_time) $start_time = $request->expired_time;
        if ($end_time > $request->expired_time) $end_time = $request->expired_time;

        // 代理
        $model = DB::table('unlimit_bonusreport_agents as ua')
            ->join(DB::raw('(' . $join_hierarchy_SQL->toSql() . ') as report_onwer'), 'report_onwer.child', 'ua.agent_id')
            ->whereRaw("ua.created_at >= '" . $start_time . "'")
            ->whereRaw("ua.created_at < '" . $end_time . "'")
            ->where(function ($query) {
                $query->whereRaw('TRUNCATE(ua.bonus_direct, 2) > 0')
                    ->orWhereRaw('TRUNCATE(ua.bonus_team, 2) > 0');
            })
            ->select(
                DB::raw('report_onwer.agent_id as agent_id'),
                DB::raw('TRUNCATE(ua.bonus_direct, 2) as bonus_direct'),
                DB::raw('TRUNCATE(ua.bonus_team, 2) as bonus_team')
            );

        // 會員
        $member_model = DB::table('unlimit_bonusreport as um')
            ->join('members as m', 'm.id', 'um.member_id')
            ->join('agents as a',  'm.parent', 'a.id' )
            ->join(DB::raw('(' . $join_hierarchy_SQL->toSql() . ') as report_onwer'), 'report_onwer.child', 'm.parent')
            ->whereRaw("um.created_at >= '" . $start_time . "'")
            ->whereRaw("um.created_at < '" . $end_time . "'")
            ->where(function ($query) {
                $query->whereRaw('TRUNCATE(um.bonus_direct, 2) > 0')
                    ->orWhereRaw('TRUNCATE(um.bonus_team, 2) > 0');
            })
            ->select(
                DB::raw('report_onwer.agent_id as agent_id'),
                DB::raw('TRUNCATE(um.bonus_direct, 2) as bonus_direct'),
                DB::raw('TRUNCATE(um.bonus_team, 2) as bonus_team')
            );

        $model = $model->unionAll($member_model);

        $model = DB::table(DB::raw("({$model->toSql()}) AS s"))
            ->mergeBindings($model);

        $queryGroupByResult = $model
            ->groupBy('s.agent_id')
            ->select(
                's.agent_id',
                DB::raw('TRUNCATE(SUM(s.bonus_direct),2) as bonus_direct'),
                DB::raw('TRUNCATE(SUM(s.bonus_team),2) as bonus_team')
            );

        //只有這個是直接查詢結果出來 (因為union會導致某些問題)
        return $queryGroupByResult;
    }

    /**
     * report/transactionTotal
     * -SR02 客服轉入轉出合計
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam source integer required 來源 0:ALL, 1:客服,3:線上支付, 4:線上代付 Example: 0
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
                'transaction_no' => 'between:0,30',
                'source' => 'in:0,1,2,3,4',
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_date',
                'is_created_period' => 'in:0,1',
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

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour'));
            $is_union = false;
            if (($request->deposit_to == '0' || $request->deposit_to == '2') && $request->filter != 3) {
                $is_union = true;
                $model = $this->transactionMemberModel($request, $start_time, $end_time);
                //類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵,19:首充活動獎勵,24:充值活動獎勵
                $model = $model->select(
                    'a.username AS agent_username',
                    DB::raw('SUM(CASE l.type WHEN 3 THEN l.point WHEN 4 THEN l.point WHEN 10 THEN l.point WHEN 11 THEN l.point WHEN 12 THEN l.point ELSE 0 END) AS total_in'),
                    DB::raw('SUM(CASE l.type WHEN 5 THEN l.point WHEN 22 THEN l.point ELSE 0 END) as total_out')
                );
            }

            $is_query_deposit = $request->source == 3;
            $is_query_withdrawal = $request->source == 4;
            if (!$is_query_deposit && !$is_query_withdrawal && ($request->deposit_to == '0' || $request->deposit_to == '1')) {
                //客服之間互相開洗分
                $agent_model = $this->transactionAgentModel($request, $start_time, $end_time);
                //如果不是查詢代理就不需要代理間開洗分的資料
                if ($agent_model) {
                    $agent_model = $agent_model->select(
                        'f.username as agent_username',
                        DB::raw('SUM(CASE la.type WHEN 1 THEN la.amount ELSE 0 END) as total_in'),
                        DB::raw('SUM(CASE la.type WHEN 2 THEN la.amount ELSE 0 END) as total_out')
                    );
                    if ($is_union) {
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
     * @bodyParam filter integer required 篩選 1:代理開洗分, 2:所有會員, 3:客服對代理開洗分 Example: 1
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
        ini_set('max_execution_time', 60);
        try {
            Common::log($request->ID, $request->all(), 'transactionList start');
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'between:0,30',
                'source' => 'in:0,1,2,3,4',
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_date',
                'is_created_period' => 'in:0,1',
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

            $is_query_deposit = $request->source == 3;
            $is_query_withdrawal = $request->source == 4;

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour'));
            $is_union = false;
            if (($request->deposit_to == '0' || $request->deposit_to == '2') && $request->filter != 3) {
                $is_union = true;
                //客服幫會員開洗分，或會員線上儲值
                $model = $this->transactionMemberModel($request, $start_time, $end_time);
                $model = $model->select(
                    'l.id',
                    DB::raw("CASE l.type WHEN 3 THEN o.order_no ELSE l.transaction_no END as transaction_no"),
                    DB::raw("CASE l.type WHEN 5 THEN w.third_party_order_no ELSE o.third_party_order_no END as third_party_order_no"),
                    DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'),
                    'a.username as agent_username',
                    //類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵
                    //DB::raw("(CASE l.type WHEN 3 THEN '開分' WHEN 4 THEN '開分' WHEN 5 THEN '洗分' WHEN 10 THEN '開分(贈點)' WHEN 11 THEN '開分(拒絕洗分)' WHEN 12 THEN '開分(系統退還洗分)' END) as type"),
                    DB::raw("CASE l.type WHEN 3 THEN 4 ELSE l.type END as type"),
                    DB::raw("'' as to_agent_username"),
                    'm.username as member_username',
                    'm.nickname as member_nickname',
                    'l.point',
                    'l.balance',
                    DB::raw("(CASE l.type WHEN 3 THEN IFNULL(cfn.name, o.name) WHEN 19 THEN '" . __('param.system') . "' WHEN 24 THEN '" . __('param.system') . "' WHEN 22 THEN '" . __('param.ota') . "' ELSE '" . __('param.customer_service') . "' END) as source"),
                    'l.operator as operator_username',
                    DB::raw("(CASE WHEN l.status=1000 THEN '" . __('param.success') . "' ELSE '" . __('param.fail') . "' END) as status"),
                    'l.remark',
                    DB::raw("1 as platform")
                );
            }

            if (!$is_query_deposit && !$is_query_withdrawal && ($request->deposit_to == '0' || $request->deposit_to == '1')) {
                //客服之間互相開洗分
                $agent_model = $this->transactionAgentModel($request, $start_time, $end_time);
                //如果不是查詢代理就不需要代理間開洗分的資料
                if ($agent_model) {
                    $agent_model = $agent_model->select(
                        'la.id',
                        DB::raw("'' as transaction_no"),
                        DB::raw("'' as third_party_order_no"),
                        DB::raw('DATE_ADD(la.created_at,INTERVAL 8 HOUR) as created_at'),
                        'f.username as agent_username',
                        DB::raw("(CASE la.type WHEN 1 THEN 4 WHEN 2 THEN 5 END) as type"),
                        't.username as to_agent_username',
                        DB::raw("'' as member_username"),
                        't.nickname as member_nickname',
                        'la.amount as point',
                        DB::raw("(to_limit_of_credit-to_used_credit) as balance"),
                        DB::raw("'" . __('param.customer_service') . "' as source"),
                        'la.operator as operator_username',
                        DB::raw("(CASE WHEN la.status=1 THEN '" . __('param.success') . "' ELSE '" . __('param.fail') . "' END) as status"),
                        'la.remark',
                        DB::raw("2 as platform")
                    );
                    //篩選選擇全部就用union，選擇僅代理就直接get
                    if ($is_union) {
                        $model = $model->union($agent_model);
                    } else {
                        $model = $agent_model;
                    }
                }
            }
            //總數
            $total = count($model->get());
            // 排序欄位
            $data = $model->orderBy('created_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            $debugInfo = [
                'page' => $request->page,
                'page_size' => $request->per_page,
                'count' => $total,
                'data_size' => strlen($data)
            ];
            Common::log($request->ID, $debugInfo, 'transactionList end');
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'source' => $request->source,
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
     * report/transactionTotalNew
     * -SSR02 客服轉入轉出合計
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam source integer required 來源 0:ALL,1:客服,3:線上支付,4:線上代付,5:活動獎勵 Example: 0
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
     *            "total_out": "總洗分金額,ex:100",
     *            "total_reward": "總獎勵金額,ex:100"
     *        }
     *    ]
     * }
     */
    public function transactionTotalNew(Request $request)
    {
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'between:0,30',
                'source' => 'in:0,1,2,3,4,5',
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_date',
                'is_created_period' => 'in:0,1',
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

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour'));
            $is_union = false;
            if (($request->deposit_to == '0' || $request->deposit_to == '2') && $request->filter != 3) {
                $is_union = true;
                $model = $this->transactionMemberNewModel($request, $start_time, $end_time);
                //類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵,19:首充活動獎勵,24:充值活動獎勵, 30:W-jackpot獎勵
                $model = $model->select(
                    'a.username AS agent_username',
                    DB::raw('SUM(CASE l.type WHEN 3 THEN l.point WHEN 4 THEN l.point WHEN 10 THEN l.point WHEN 11 THEN l.point WHEN 12 THEN l.point ELSE 0 END) AS total_in'),
                    DB::raw('SUM(CASE l.type WHEN 5 THEN l.point WHEN 22 THEN l.point ELSE 0 END) as total_out'),
                    DB::raw('SUM(CASE l.type WHEN 13 THEN l.point WHEN 15 THEN l.point WHEN 16 THEN l.point WHEN 19 THEN l.point WHEN 20 THEN l.point WHEN 21 THEN l.point WHEN 24 THEN l.point WHEN 25 THEN l.point WHEN 26 THEN l.point WHEN 30 THEN l.point ELSE 0 END) as total_reward')
                );
            }

            $is_query_deposit = $request->source == 3;
            $is_query_withdrawal = $request->source == 4;
            $is_query_event_reward = $request->source == 5;
            if (!$is_query_deposit && !$is_query_withdrawal && ($request->deposit_to == '0' || $request->deposit_to == '1')) {
                //客服之間互相開洗分
                $agent_model = $this->transactionAgentModel($request, $start_time, $end_time);
                //如果不是查詢代理就不需要代理間開洗分的資料
                if ($agent_model) {
                    $agent_model = $agent_model->select(
                        'f.username as agent_username',
                        DB::raw('SUM(CASE la.type WHEN 1 THEN la.amount ELSE 0 END) as total_in'),
                        DB::raw('SUM(CASE la.type WHEN 2 THEN la.amount ELSE 0 END) as total_out'),
                        DB::raw('0 as total_reward')
                    );
                    if ($is_union) {
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
     * report/transactionListNew
     * -SSR02 客服轉入轉出明細表
     *
     * @bodyParam agent_username string 營運商帳號 Example: abc
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam member_nickname string 會員帳號 Example: abc
     * @bodyParam transaction_no integer 單號 Example: 1
     * @bodyParam source integer required 來源 0:客服1:線上支付,3:線上支付,4:線上代付,5:活動獎勵 Example: 0
     * @bodyParam filter integer required 篩選 1:代理開洗分, 2:所有會員, 3:客服對代理開洗分 Example: 1
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
    public function transactionListNew(Request $request)
    {
        ini_set('max_execution_time', 60);
        try {
            $rules = [
                'agent_username' => 'r_alpha_num|between:0,30',
                'member_username' => 'r_alpha_num|between:0,30',
                'member_nickname' => 'between:0,30',
                'transaction_no' => 'between:0,30',
                'source' => 'in:0,1,2,3,4,5',
                'start_date' => 'required|date_format:Y-m-d H:i:s',
                'end_date' => 'required|date_format:Y-m-d H:i:s|after_or_equal:start_date',
                'is_created_period' => 'in:0,1',
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

            $is_query_deposit = $request->source == 3;
            $is_query_withdrawal = $request->source == 4;
            $is_query_event_reward = $request->source == 5;

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour'));
            $is_union = false;
            if (($request->deposit_to == '0' || $request->deposit_to == '2') && $request->filter != 3) {
                $is_union = true;
                //客服幫會員開洗分，或會員線上儲值
                $model = $this->transactionMemberNewModel($request, $start_time, $end_time);
                $model = $model->select(
                    'l.id',
                    DB::raw("CASE l.type WHEN 3 THEN o.order_no ELSE l.transaction_no END as transaction_no"),
                    DB::raw("CASE l.type WHEN 5 THEN w.third_party_order_no ELSE o.third_party_order_no END as third_party_order_no"),
                    DB::raw('DATE_ADD(l.created_at,INTERVAL 8 HOUR) as created_at'),
                    'a.username as agent_username',
                    //類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,13:活動獎勵
                    //DB::raw("(CASE l.type WHEN 3 THEN '開分' WHEN 4 THEN '開分' WHEN 5 THEN '洗分' WHEN 10 THEN '開分(贈點)' WHEN 11 THEN '開分(拒絕洗分)' WHEN 12 THEN '開分(系統退還洗分)' END) as type"),
                    DB::raw("CASE l.type WHEN 3 THEN 4 ELSE l.type END as type"),
                    DB::raw("'' as to_agent_username"),
                    'm.username as member_username',
                    'm.nickname as member_nickname',
                    'l.point',
                    'l.balance',
                    DB::raw("(CASE l.type WHEN 3 THEN IFNULL(cfn.name, o.name) WHEN 13 THEN '" . __('param.system') . "' WHEN 15 THEN '" . __('param.system') . "' WHEN 16 THEN '" . __('param.system') . "' WHEN 19 THEN '" . __('param.system') . "' WHEN 20 THEN '" . __('param.system') . "' WHEN 21 THEN '" . __('param.system') . "' WHEN 24 THEN '" . __('param.system') . "' WHEN 25 THEN '" . __('param.system') . "' WHEN 26 THEN '" . __('param.system') . "' WHEN 30 THEN '" . __('param.system') . "' WHEN 22 THEN '" . __('param.ota') . "' ELSE '" . __('param.customer_service') . "' END) as source"),
                    'l.operator as operator_username',
                    DB::raw("(CASE WHEN l.status=1000 THEN '" . __('param.success') . "' ELSE '" . __('param.fail') . "' END) as status"),
                    'l.remark',
                    DB::raw("1 as platform")
                );
            }

            if (!$is_query_deposit && !$is_query_withdrawal && !$is_query_event_reward && ($request->deposit_to == '0' || $request->deposit_to == '1')) {
                //客服之間互相開洗分
                $agent_model = $this->transactionAgentModel($request, $start_time, $end_time);
                //如果不是查詢代理就不需要代理間開洗分的資料
                if ($agent_model) {
                    $agent_model = $agent_model->select(
                        'la.id',
                        DB::raw("'' as transaction_no"),
                        DB::raw("'' as third_party_order_no"),
                        DB::raw('DATE_ADD(la.created_at,INTERVAL 8 HOUR) as created_at'),
                        'f.username as agent_username',
                        DB::raw("(CASE la.type WHEN 1 THEN 4 WHEN 2 THEN 5 END) as type"),
                        't.username as to_agent_username',
                        DB::raw("'' as member_username"),
                        't.nickname as member_nickname',
                        'la.amount as point',
                        DB::raw("(to_limit_of_credit-to_used_credit) as balance"),
                        DB::raw("'" . __('param.customer_service') . "' as source"),
                        'la.operator as operator_username',
                        DB::raw("(CASE WHEN la.status=1 THEN '" . __('param.success') . "' ELSE '" . __('param.fail') . "' END) as status"),
                        'la.remark',
                        DB::raw("2 as platform")
                    );
                    //篩選選擇全部就用union，選擇僅代理就直接get
                    if ($is_union) {
                        $model = $model->union($agent_model);
                    } else {
                        $model = $agent_model;
                    }
                }
            }

            //總數
            $total = count($model->get());
            // 排序欄位
            $data = $model->orderBy('created_at', 'DESC')
                ->orderBy('id', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'source' => $request->source,
                'is_union' => $is_union,
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

            if (isset($request->agent_username) && $request->agent_username != '') {
                $agent = Agent::where('username', $request->agent_username)->first();
                if (is_object($agent)) {
                    //子帳號權限調整 $request->ID => $request->HOST
                    //只能查詢自己或自己下線的
                    if (!$this->isChild($request->HOST, $agent->id) &&  $agent->id != $request->ID) {
                        //不是下線
                        return response()->json([
                            'code' => 1050,
                            'message' => __('error_message.1050'),
                        ]);
                    }
                } else {
                    //代理商不存在
                    return response()->json([
                        'code' => 1050,
                        'message' => __('error_message.1050'),
                    ]);
                }
            } else {
                //查登入的這個agent
                $agent = Agent::where('id', $request->HOST)->first();
            }

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'));

            // 代理 + 會員
            $model = $this->platformReconciliation($request, $agent, $start_time, $end_time);

            // 活動獎勵
            if (strpos($request->vendor_id, '-1') !== false) {
                $event_model = $this->platformReconciliationEventTotal($agent, $start_time, $end_time);
                $model = $model->union($event_model);
            }

            // 無限代獎勵
            if (strpos($request->vendor_id, '-2') !== false) {
                //取得各站個性化翻譯
                $translates = app('App\Http\Controllers\LanguageController')->getTranslate($request);
                $promotion = (is_array($translates)) ? $translates['3003'] : 'V-Reward';
                $unlimited_model = $this->platformReconciliationUnlimitedTotal($agent, $promotion, $start_time, $end_time);
                $model = $model->union($unlimited_model);
            }
            //支付手續費&簡訊費
            if (strpos($request->vendor_id, '-3') !== false || strpos($request->vendor_id, '-4') !== false) {
                $fee_model = $this->platformReconciliationFeeTotal($request, $agent, $start_time, $end_time);
                $model = $model->union($fee_model);
            }

            $data = $model->orderBy('agent_id', 'asc')
                ->orderBy('sort', 'asc')
                ->orderBy('game_type_id', 'asc')
                ->orderBy('type', 'asc')
                ->get();

            //6004 vjackpot比例
            $query_setting_vjackpot = $this->agentCustomizedSettingRepository->getSettingByGroupID($agent->customized_setting_group_id , 6004);

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'show_v_jackpot' => (($query_setting_vjackpot->setting_value > 0) ? true : false)
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
     *    ],
     *  "summary": {
     *      "total_bet": "總押注,ex:100.00"總押注
     *      "total_win_lose": "總輸贏,ex:100.00"
     *      "total_lottery": "總彩金與活動,ex:100.00"
     * }
     * }
     */
    public function platformReconciliationDetail(Request $request)
    {
        ini_set('max_execution_time', 60);
        try {
            DB::enableQueryLog();
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

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime(substr($request->end_time, 0, -2) . '59-8hour'));

            $temp_query = DB::table('agents as ta')
                ->whereRaw("hierarchy LIKE '%," . $agent->id . ",%'")
                ->whereRaw('id != '. $agent->id)
                ->select(
                    DB::raw('id AS child'),
                    DB::raw("SUBSTRING_INDEX(SUBSTR(hierarchy, 1, LOCATE('," . $agent->id . ",', hierarchy) - 1), ',', -1) AS agent_id"),
                    'a.vendor_customize_group_id'
                );

            // 代理
            $model = $this->platformReconciliationDetailModel($request, $temp_query, $temp_query, $start_time, $end_time);
            $total = $model->getCountForPagination();
            // 獎勵
            if (strpos($request->vendor_id, '-1') !== false) {
                $event_model = $this->platformReconciliationEventModel($request, $temp_query, $agent, $start_time, $end_time);
                $total += $event_model->getCountForPagination();
                $model = $model->union($event_model);
            }
            // 無限代獎勵
            if (strpos($request->vendor_id, '-2') !== false) {
                //取得各站個性化翻譯
                $translates = app('App\Http\Controllers\LanguageController')->getTranslate($request);
                $promotion = (is_array($translates)) ? $translates['3003'] : 'V-Reward';

                $unlimited_model = $this->platformReconciliationUnlimitedModel($request, $temp_query, $agent, $promotion, $start_time, $end_time);
                $total += $unlimited_model->getCountForPagination();
                $model = $model->union($unlimited_model);
            }
            //支付手續費&簡訊費
            if (strpos($request->vendor_id, '-3') !== false || strpos($request->vendor_id, '-4') !== false) {
                $fee_model = $this->platformReconciliationFeeModel($request, $temp_query, $start_time, $end_time);
                $total += $fee_model->getCountForPagination();
                $model = $model->union($fee_model);
            }

            $summary = DB::table(DB::raw('(' . $model->toSql(). ') as z'))
                ->mergeBindings($model)
                ->select(
                    DB::raw('SUM(z.total_bet) AS total_bet'),
                    DB::raw('SUM(z.win_lose) AS total_win_lose'),
                    DB::raw('SUM(z.cost) AS total_cost'),
                    DB::raw('SUM(z.commission) AS total_commission'),
                    DB::raw('SUM(z.lottery) AS total_lottery'),
                    DB::raw('SUM(z.v_jackpot) AS total_v_jackpot')
                )
                ->first();

            $data = $model->orderBy('agent_id', 'asc')
                ->orderBy('sort', 'asc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();
            $queries = DB::getQueryLog();
            $debugInfo = [
                'queries'=>$queries,
                'user_id'=>$request->ID,
                'params'=>$request->all(),
                'data'=>$data
            ];
            Common::log($request->ID, $debugInfo, 'platformReconciliationDetail');

            $show_vjackpot = AgentCustomizedSetting::where('agent_group_id', $agent->customized_setting_group_id)
                ->where('setting_key', 6004)
                ->select('value as v_jackpot_rate')
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'summary' => $summary,
                'show_v_jackpot' => (($show_vjackpot->v_jackpot_rate > 0) ? true : false),
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
            } else if (isset($request->member_username) && $request->member_username != '') {
                $agent = DB::table('agents as a')
                    ->join('members as m', function ($join) use ($request) {
                        $join->on('m.parent', 'a.id')
                            ->where('m.username', $request->member_username);
                    })
                    ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%')
                    ->select(
                        'a.id',
                        'a.username',
                        'a.nickname',
                        'a.customized_setting_group_id'
                    )
                    ->first();
            } else {
                //查登入的這個agent
                $agent = Agent::where('id', $request->HOST)->first();
            }

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour -1second'));

            $model = $this->platformReconciliationMemberModel($request, $agent);

            //活動獎勵 V-World
            if (strpos($request->vendor_id, '-1') !== false) {
                $event_model = $this->platformReconciliationEventMember($request, $agent);
                $model = $model->union($event_model);
            }
            // 無限代獎勵 V-Reward
            if (strpos($request->vendor_id, '-2') !== false) {
                //取得各站個性化翻譯
                $translates = app('App\Http\Controllers\LanguageController')->getTranslate($request);
                $promotion = (is_array($translates)) ? $translates['3003'] : 'V-Reward';

                $unlimited_model = $this->unlimitedService->newPlatformReconciliationMember($request, $agent, $promotion);
                $model = $model->union($unlimited_model);
            }
            //支付手續費&簡訊費
            if (strpos($request->vendor_id, '-3') !== false || strpos($request->vendor_id, '-4') !== false) {
                $fee_model = $this->platformReconciliationFeeMember($request, $agent, $start_time, $end_time);
                $model = $model->union($fee_model);
            }
            $data = $model->orderBy('member_username')
                ->orderBy('sort')
                ->get();

            $show_vjackpot = AgentCustomizedSetting::where('agent_group_id', $agent->customized_setting_group_id)
                ->where('setting_key', 6004)
                ->select('value as v_jackpot_rate')
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'show_v_jackpot' => (($show_vjackpot->v_jackpot_rate > 0) ? true : false)
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

    private function platformReconciliationMemberModel($request, $agent)
    {
        //以agent為主
        $data = DB::table('summary_bet_records as s')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 's.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
            })
            ->join('vendors as v', function ($join) use ($request) {
                $join->on('v.id', 's.vendor_id');
                if (isset($request->vendor_id) && $request->vendor_id  != '') {
                    $join->whereIn('v.id', explode(',', $request->vendor_id));
                }
            })
            ->leftJoin('games as g', 'g.id', 's.game_id')
            ->leftJoin('game_types as t', 't.id', 'g.type_id')
            ->where('s.summary_time', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.summary_time', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')));

        if (isset($request->game_type) && $request->game_type > 0) {
            $data = $data->where('t.id', $request->game_type);
        }
        // $data = $data->where('s.vendor_id', '!=', 4); //豪龍沒有會員明細，所以先隱藏
        $model = $data->groupBy('m.id')
            ->groupBy('v.id')
            ->groupBy('t.id')
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                'v.name as vendor',
                'v.id as vendor_id',
                't.name as game_type',
                't.id as game_type_id',
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.bet END) as total_bet'),
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.win_lose END) as win_lose'),
                DB::raw('SUM(s.cost) as cost'),
                DB::raw('SUM(s.commission) as commission'),
                DB::raw('SUM(s.jackpot) as lottery'),
                DB::raw('SUM(s.share_amount) as share_amount'),
                DB::raw('SUM(s.v_jackpot) as v_jackpot'),
                DB::raw('(s.vendor_id + 1000) AS sort')
            );
        return $model;
    }

    private function platformReconciliationFeeMember($request, $agent, $start_time, $end_time)
    {
        $ar_fee = [];
        if (strpos($request->vendor_id, '-3') !== false) {
            $ar_fee[] = 3;
        }
        if (strpos($request->vendor_id, '-4') !== false) {
            $ar_fee[] = 4;
        }
        //以agent為主
        $data = DB::table('summary_event_records as s')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 's.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time)
            ->whereIn('s.event_id', $ar_fee);

        $model = $data->groupBy('m.id', 's.event_id')
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw("CASE s.event_id WHEN 3 THEN 'payfee' WHEN 4 THEN 'smsfee' END AS vendor"),
                DB::raw('(s.event_id * -1) as vendor_id'),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('SUM(s.point) as cost'),
                DB::raw('0 as commission'),
                DB::raw('0 as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                's.event_id as sort'
            );
        return $model;
    }

    private function platformReconciliationEventMember($request, $agent)
    {
        //以agent為主
        $data = DB::table('transaction_logs as s')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 's.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                }
            })
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 'm.parent')
                    ->where('a.id', $agent->id);
                //->where('a.hierarchy', 'like', '%,' . $agent->id . ',%')->first();
            })
            ->where('s.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_time . '-8hour')))
            ->where('s.created_at', '<', date('Y-m-d H:i:s', strtotime($request->end_time . '-8hour 1minute')))
            // 13:活動獎勵, 15:登入活動, 19:首充活動返利, 20:註冊送, 21:綁定送, 24:充值活動返利, 25:每日充值活動返利
            ->whereIn('s.type', [13, 15, 19, 20, 21, 24, 25, 26, 30, 101]);

        $model = $data->groupBy('m.id')
            ->select(
                'm.username as member_username',
                'm.nickname as member_nickname',
                DB::raw("'V-world' as vendor"),
                DB::raw("'-1' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as cost'),
                DB::raw('0 as commission'),
                DB::raw('SUM(TRUNCATE(s.point, 4)) as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                DB::raw('1 as sort')
            );
        return $model;
    }

    private function transactionMemberModel($request, $start_time, $end_time)
    {
        $sub = DB::table('orders as o')
            ->join('agent_cash_flows as acf', function ($join) use ($request) {
                $join->on('o.agent_cash_flow_id', 'acf.id');
                //篩選cash_flow_id
                if (isset($request->cash_flow_id) && $request->cash_flow_id != '') {
                    $join->where('acf.cash_flow_id', $request->cash_flow_id);
                }
            })
            ->join('cash_flows as cf', 'acf.cash_flow_id', 'cf.id')
            ->whereBetween('o.created_at', [date('Y-m-d H:i:s', strtotime($start_time . '-30day')), $end_time])
            ->select(
                'o.id',
                'o.order_no',
                'o.third_party_order_no',
                'o.created_at',
                'cf.name',
                'cf.id as cash_flow_id'
            );

        $model = DB::table('transaction_logs as l')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 'l.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                } else if (isset($request->member_nickname) && $request->member_nickname != '') {
                    $join->where('m.nickname', $request->member_nickname);
                }
            })
            ->join('agents as a', function ($join) use ($request) {
                $join->on('a.id', 'm.parent');
                $agent = (isset($request->agent_username) && $request->agent_username != '')
                    ? Agent::where('username', $request->agent_username)->first()
                    : Agent::where('id', $request->HOST)->first();
                if (is_object($agent) && $this->isChild($request->HOST, $agent->id)) {
                    $join->where('a.hierarchy_path', 'like', $agent->hierarchy_path . '%');
                } else {
                    $join->where('1', '2');
                }
            })
            ->leftJoin(DB::raw('(' . $sub->toSql() . ') as o'), function ($join) use ($request) {
                $join->on('l.order_id', 'o.id')
                    ->whereRaw('l.type = 3');
            })
            ->mergeBindings($sub)
            ->leftJoin('cash_flow_names as cfn', function ($join) use ($request) {
                $join->on('cfn.agent_group_id', 'a.customized_setting_group_id')
                    ->on('cfn.cash_flow_id', 'o.cash_flow_id')
                    ->whereRaw('cfn.language_id = ' . $request->LANGUAGE);
            })
            ->leftJoin('withdrawal_orders as w', function ($join) use ($request) {
                $join->on('w.id', 'l.order_id')
                    //->where('acf.cash_flow_id', $request->cash_flow_id)
                    ->whereRaw('l.type = 5');
            })
            ->leftJoin('withdrawal_payout_records AS wpr', function ($join) {
                $join->on('wpr.withdrawal_order_id', 'w.id')
                    ->whereRaw('wpr.is_success_payout = 1');
            })
            ->leftJoin('ota_orders as oo', function ($join) use ($request) {
                $join->on('oo.id', 'l.order_id')
                    ->whereRaw('l.type = 22');
            })
            ->whereBetween((($request->is_created_period) ? 'o.created_at' : 'l.created_at'), [$start_time, $end_time]);

        if (isset($request->execution_username) && $request->execution_username != '') {
            $model = $model->where('l.operator', $request->execution_username);
        }
        // 類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,
        //      6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,
        //      13:活動獎勵,19:首充活動獎勵
        switch ($request->source) {
            case 1: // 客服開洗分
                $model = $model->whereIn('l.type', [4, 5, 10, 11, 12])
                    ->whereNull('o.id')
                    ->whereNull('w.id');
                break;
            case 3: // 線上儲值
                $model = $model->whereIn('l.type', [3, 19, 24, 25])
                    ->whereNotNull('o.id');
                break;
            case 4: // 線上代付
                if ($request->cash_flow_id == 18) {
                    $model = $model->where('l.type', 22)
                        ->whereNotNull('oo.id');
                } else {
                    $model = $model->where('l.type', 5)
                        ->whereNotNull('w.id');
                    //auto withdrawal 為 6
                    if ($request->cash_flow_id != 6) {
                        $model = $model->where('wpr.cash_flow_id', $request->cash_flow_id);
                    }
                }
                break;
            default: // 全部
                $model = $model->whereIn('l.type', [3, 4, 5, 10, 11, 12, 19, 22, 24, 25, 30, 101]);
                break;
        }

        if ($request->filter == 1) {
            $model = $model->where(function ($query) use ($request) {
                $query->where('l.agent_id', $request->HOST)
                    ->orWhereIn('l.type', [3, 19, 24, 25, 30, 101]);
            });
        }
        $model = $model->where('l.status', 1000);

        //我方單號 or 三方單號
        if (isset($request->transaction_no) && $request->transaction_no != '') {
            $model = $model->where(function ($query) use ($request) {
                $query->where('l.transaction_no', $request->transaction_no);
                if ($request->source == 3) {
                    $query->orWhere('o.third_party_order_no', $request->transaction_no);
                }
                if ($request->source == 4) {
                    $query->orWhere('w.third_party_order_no', $request->transaction_no);
                }
            });
        }

        return $model;
    }

    private function transactionMemberNewModel($request, $start_time, $end_time)
    {
        $sub = DB::table('orders as o')
            ->join('agent_cash_flows as acf', function ($join) use ($request) {
                $join->on('o.agent_cash_flow_id', 'acf.id');
                //篩選cash_flow_id
                if (isset($request->cash_flow_id) && $request->cash_flow_id != '') {
                    $join->where('acf.cash_flow_id', $request->cash_flow_id);
                }
            })
            ->join('cash_flows as cf', 'acf.cash_flow_id', 'cf.id')
            ->whereBetween('o.created_at', [date('Y-m-d H:i:s', strtotime($start_time . '-30day')), $end_time])
            ->select(
                'o.id',
                'o.order_no',
                'o.third_party_order_no',
                'o.created_at',
                'cf.name',
                'cf.id as cash_flow_id'
            );

        $model = DB::table('transaction_logs as l')
            ->join('members as m', function ($join) use ($request) {
                $join->on('m.id', 'l.member_id');
                if (isset($request->member_username) && $request->member_username != '') {
                    $join->where('m.username', $request->member_username);
                } else if (isset($request->member_nickname) && $request->member_nickname != '') {
                    $join->where('m.nickname', $request->member_nickname);
                }
            })
            ->join('agents as a', function ($join) use ($request) {
                $join->on('a.id', 'm.parent');
                $agent = (isset($request->agent_username) && $request->agent_username != '')
                    ? Agent::where('username', $request->agent_username)->first()
                    : Agent::where('id', $request->HOST)->first();
                if (is_object($agent) && $this->isChild($request->HOST, $agent->id)) {
                    $join->where('a.hierarchy_path', 'like', $agent->hierarchy_path . '%');
                } else {
                    $join->where('1', '2');
                }
            })
            ->leftJoin(DB::raw('(' . $sub->toSql() . ') as o'), function ($join) use ($request) {
                $join->on('l.order_id', 'o.id')
                    ->whereRaw('l.type = 3');
            })
            ->mergeBindings($sub)
            ->leftJoin('cash_flow_names as cfn', function ($join) use ($request) {
                $join->on('cfn.agent_group_id', 'a.customized_setting_group_id')
                    ->on('cfn.cash_flow_id', 'o.cash_flow_id')
                    ->whereRaw('cfn.language_id = ' . $request->LANGUAGE);
            })
            ->leftJoin('withdrawal_orders as w', function ($join) use ($request) {
                $join->on('w.id', 'l.order_id')
                    //->where('acf.cash_flow_id', $request->cash_flow_id)
                    ->whereRaw('l.type = 5');
            })
            ->leftJoin('withdrawal_payout_records AS wpr', function ($join) {
                $join->on('wpr.withdrawal_order_id', 'w.id')
                    ->whereRaw('wpr.is_success_payout = 1');
            })
            ->leftJoin('ota_orders as oo', function ($join) use ($request) {
                $join->on('oo.id', 'l.order_id')
                    ->whereRaw('l.type = 22');
            })
            ->whereBetween((($request->is_created_period) ? 'o.created_at' : 'l.created_at'), [$start_time, $end_time]);

        if (isset($request->execution_username) && $request->execution_username != '') {
            $model = $model->where('l.operator', $request->execution_username);
        }
        // 類型1:平台轉入遊戲商,2:遊戲商轉入平台,3:訂單儲值,4:開分,5:洗分,
        //      6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分,
        //      13:活動獎勵,19:首充活動獎勵
        switch ($request->source) {
            case 1: // 客服開洗分
                $model = $model->whereIn('l.type', [4, 5, 10, 11, 12])
                    ->whereNull('o.id')
                    ->whereNull('w.id');
                break;
            case 3: // 線上儲值
                //$model = $model->whereIn('l.type', [3, 19, 24, 25])
                $model = $model->where('l.type', 3)
                    ->whereNotNull('o.id');
                break;
            case 4: // 線上代付
                if ($request->cash_flow_id == 18) {
                    $model = $model->where('l.type', 22)
                        ->whereNotNull('oo.id');
                } else {
                    $model = $model->where('l.type', 5)
                        ->whereNotNull('w.id');
                    //auto withdrawal 為 6
                    if ($request->cash_flow_id != 6) {
                        $model = $model->where('wpr.cash_flow_id', $request->cash_flow_id);
                    }
                }
                break;
            case 5: // 活動獎勵; 13, 15, 16, 19, 20, 21, 24, 25, 26, 30
                $model = $model->where('l.type', $request->cash_flow_id);
                break;
            default: // 全部
                $model = $model->whereIn(
                    'l.type',
                    [
                        3, 4, 5, 10, 11, 12, 13, 15, 16, 19, 20, 21, 22, 24, 25, 26, 30, 101
                    ]);
                break;
        }

        if ($request->filter == 1) {
            $model = $model->where(function ($query) use ($request) {
                $query->where('l.agent_id', $request->HOST)
                    ->orWhereIn('l.type', [3, 19, 24, 25]);
            });
        }
        $model = $model->where('l.status', 1000);

        //我方單號 or 三方單號
        if (isset($request->transaction_no) && $request->transaction_no != '') {
            $model = $model->where(function ($query) use ($request) {
                $query->where('l.transaction_no', $request->transaction_no);
                if ($request->source == 3) {
                    $query->orWhere('o.third_party_order_no', $request->transaction_no);
                }
                if ($request->source == 4) {
                    $query->orWhere('w.third_party_order_no', $request->transaction_no);
                }
            });
        }

        return $model;
    }

    /* 統計這個帳號所有的下線 */
    private function onlinePaymentModel($request, $agentId)
    {
        // 取客服轉入轉出
        $model = DB::table('transaction_logs as l')
            ->leftJoin('members as m', 'm.id', 'l.member_id')
            ->leftJoin('agents as a', function ($join) use ($request) {
                $join->on('a.id', 'l.agent_id')
                    ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
            })
            ->whereIn('l.type', [3, 19, 24, 25]) //只有線上儲值/充值獎勵
            ->where('l.created_at', '>=', date('Y-m-d H:i:s', strtotime($request->start_date . '-8hour')))
            ->where('l.created_at', '<=', date('Y-m-d H:i:s', strtotime($request->end_date . '-8hour')))
            ->where('l.status', 1000);
        return $model;
    }

    private function transactionAgentModel($request, $start_time, $end_time)
    {
        if (isset($request->member_username) && $request->member_username != '') {
            //代理之間跟會員無關
            return false;
        } else if (isset($request->member_nickname) && $request->member_nickname != '') {
            //代理之間跟會員無關
            return false;
        } else if (isset($request->transaction_no) && $request->transaction_no != '') {
            //代理之間互轉沒單號
            return false;
        }
        
        if ($request->source != 2) { // 線上儲值不需要客服間開洗分的值
            // 取客服之間互相轉
            $agent = Agent::where('id', $request->HOST)->first();
            $model = DB::table('transaction_log_agents as la')
                ->join('agents as t', 't.id', 'la.to_agent_id')
                ->join('agents as f', function ($join) use ($agent) {
                    $join->on('f.id', 'la.from_agent_id')
                        ->where('f.hierarchy_path', 'like', $agent->hierarchy_path . '%');
                });

            if (isset($request->agent_username) && $request->agent_username != '') {
                $model = ($request->filter == 3)
                    ? $model->where('t.username', $request->agent_username)
                    : $model->where('f.username', $request->agent_username);
            } else {
                //都沒輸入查詢自己的
                $model = ($request->filter == 3)
                    ? $model->where('t.id', $request->HOST)
                    : $model->where('f.id', $request->HOST);
            }

            $model = $model->where('la.created_at', '>=', $start_time)
                ->where('la.created_at', '<=',$end_time)
                ->where('la.status', 1);
            return $model;
        } else {
            return null;
        }
    }

    private function platformReconciliation($request, $agent, $start_time, $end_time)
    {
        $main = DB::table('summary_bet_record_agents as s')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 's.agent_id')
                    ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
            })
            ->join('vendors as v', function ($join) use ($request) {
                $join->on('v.id', 's.vendor_id');
                if (isset($request->vendor_id) && $request->vendor_id  != '') {
                    $join->whereIn('v.id',  explode(',', $request->vendor_id));
                }
            })
            ->leftJoin('vendor_customized_items as vi', function ($join) {
                $join->on('vi.agent_group_id', 'a.vendor_customize_group_id')
                    ->on('vi.vendor_id', 'v.id')
                    ->on('vi.item_type', DB::raw(1));
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time)
            ->groupBy('s.vendor_id', 's.game_type_id')
            ->select(
                DB::raw("(CASE WHEN a.id = 33893 THEN 'member' ELSE 'agent' END) as type"),
                's.vendor_id',
                DB::raw('IFNULL(vi.name, v.name) as vendor'),
                's.game_type_id AS type_id',
                DB::raw('SUM(s.total_bet) AS total_bet'),
                DB::raw('SUM(s.total_win_lose) AS win_lose'),
                DB::raw('SUM(s.total_cost) AS cost'),
                DB::raw('SUM(s.total_commission) AS commission'),
                DB::raw('SUM(s.total_jackpot) AS lottery'),
                DB::raw('SUM(s.total_share_amount) AS share_amount'),
                DB::raw('SUM(s.total_v_jackpot) AS v_jackpot'),
                DB::raw('(s.vendor_id + 1000) AS sort')
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as s'))
            ->mergeBindings($main)
            ->leftJoin('game_types as t', 't.id', 's.type_id')
            ->groupBy('s.vendor_id', 's.type', 't.id');

        if (isset($request->game_type) && $request->game_type > 0) {
            $model = $model->where('t.id',  $request->game_type);
        }

        $model = $model->select(
            's.type',
            DB::raw("'" . $agent->id . "' as agent_id"),
            DB::raw("'" . $agent->username . "' as agent_username"),
            DB::raw("'" . $agent->nickname . "' as agent_nickname"),
            's.vendor as vendor',
            's.vendor_id as vendor_id',
            't.name as game_type',
            't.id as game_type_id',
            DB::raw('CASE WHEN s.vendor_id = 36 THEN 0 ELSE s.total_bet END as total_bet'),
            DB::raw('CASE WHEN s.vendor_id = 36 THEN 0 ELSE s.win_lose END as win_lose'),
            's.cost',
            's.commission',
            's.lottery',
            's.share_amount',
            's.v_jackpot',
            's.sort'
        );
        return $model;
    }

    private function platformReconciliationEventTotal($agent, $start_time, $end_time)
    {
        $main = DB::table('summary_event_record_agents as s')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 's.agent_id')
                    ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<=', $end_time)
            ->where('s.event_id', 1)
            ->groupBy('s.agent_id', 's.event_id')
            ->select(
                DB::raw("(CASE WHEN s.agent_id = " . $agent->id . " THEN 2 ELSE 1 END) as type"),
                DB::raw('SUM(s.point) as point'),
                DB::raw('(s.event_id * -1) as vendor_id'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as z'))
            ->mergeBindings($main)
            ->groupBy('z.type', 'z.vendor_id')
            ->orderBy('z.type', 'asc')
            ->select(
                DB::raw("(CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END) as type"),
                DB::raw($agent->id ." as agent_id"),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("'V-world' as vendor"),
                DB::raw("-1 as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("0 as cost"),
                DB::raw("0 as commission"),
                DB::raw("SUM(z.point) as lottery"),
                DB::raw("0 as share_amount"),
                DB::raw("0 as v_jackpot"),
                'z.sort'
            );

        return $model;
    }

    private function platformReconciliationFeeTotal($request, $agent, $start_time, $end_time)
    {
        $ar_fee = [];
        if (strpos($request->vendor_id, '-3') !== false) {
            $ar_fee[] = 3;
        }
        if (strpos($request->vendor_id, '-4') !== false) {
            $ar_fee[] = 4;
        }

        $main = DB::table('summary_event_record_agents as s')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 's.agent_id')
                    ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<=', $end_time)
            ->whereIn('s.event_id', $ar_fee)
            ->groupBy('s.agent_id', 's.event_id')
            ->select(
                DB::raw("(CASE WHEN s.agent_id = " . $agent->id . " THEN 2 ELSE 1 END) as type"),
                DB::raw('SUM(s.point) as point'),
                DB::raw('(s.event_id * -1) as vendor_id'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as z'))
            ->mergeBindings($main)
            ->groupBy('z.type', 'z.vendor_id')
            ->orderBy('z.type', 'asc')
            ->select(
                DB::raw("(CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END) as type"),
                DB::raw($agent->id ." as agent_id"),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("CASE z.vendor_id WHEN -3 THEN 'payfee' WHEN -4 THEN 'smsfee' END AS vendor"),
                'vendor_id',
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("SUM(z.point) as cost"),
                DB::raw("0 as commission"),
                DB::raw("0 as lottery"),
                DB::raw("0 as share_amount"),
                DB::raw("0 as v_jackpot"),
                'z.sort'
            );

        return $model;
    }

    private function platformReconciliationUnlimitedTotal($agent, $promotion, $start_time, $end_time)
    {
        $main = DB::table('summary_event_record_agents as s')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 's.agent_id')
                    ->where('a.hierarchy', 'like', '%,' . $agent->id . ',%');
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<=', $end_time)
            ->where('s.event_id', 2)
            ->groupBy('s.agent_id')
            ->select(
                DB::raw("(CASE WHEN s.agent_id = " . $agent->id . " THEN 'self' ELSE 'agent' END) as type"),
                DB::raw("CASE WHEN s.agent_id = " . $agent->id . " THEN SUM(s.self_point) ELSE SUM(s.point) + SUM(s.self_point) END as point"),
                DB::raw('(s.event_id * -1) as vendor_id'),
                's.event_id as sort'
            );
        $main = DB::table(DB::raw('(' . $main->toSql(). ') as z'))
            ->mergeBindings($main)
            ->groupBy('z.type')
            ->select(
                'z.type',
                DB::raw("SUM(point) AS point"),
                'z.sort'
            );

        $sub = DB::table('summary_event_record_agents as s')
            ->join('agents as a', function ($join) use ($agent) {
                $join->on('a.id', 's.agent_id')
                    ->where('a.id', $agent->id);
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<=', $end_time)
            ->where('s.event_id', 2)
            ->where('s.point', '>', 0)
            ->groupBy('s.agent_id')
            ->select(
                DB::raw("'member' as type"),
                DB::raw('SUM(s.point) AS point'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as y'))
            ->mergeBindings($main)
            ->union($sub)
            ->select(
                'y.type',
                'y.point AS point',
                'y.sort'
            );

        $model = DB::table(DB::raw('(' . $model->toSql(). ') as x'))
            ->mergeBindings($model)
            ->select(
                'x.type',
                DB::raw($agent->id ." as agent_id"),
                DB::raw("'" . $agent->username . "' as agent_username"),
                DB::raw("'" . $agent->nickname . "' as agent_nickname"),
                DB::raw("'" . $promotion . "' as vendor"),
                DB::raw("-2 as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("0 as cost"),
                DB::raw("0 as commission"),
                'x.point',
                DB::raw("0 as share_amount"),
                DB::raw("0 as v_jackpot"),
                'x.sort'
            );
        return $model;
    }

    private function platformReconciliationDetailModel($request, $isAgent, $temp, $start_time, $end_time)
    {
        $main = DB::table('summary_bet_record_agents as s')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 's.agent_id')
            ->mergeBindings($temp)
            ->join('vendors as v', function($join) use ($request) {
                $join->on('v.id', 's.vendor_id');
                if (isset($request->vendor_id) && $request->vendor_id  != '') {
                    $join->whereIn('v.id', explode(',', $request->vendor_id));
                }
            })
            ->leftJoin('vendor_customized_items as vi', function ($join) {
                $join->on('vi.agent_group_id', 'temp.vendor_customize_group_id')
                    ->on('vi.vendor_id', 'v.id')
                    ->on('vi.item_type', DB::raw(1));
            })
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time);

        if (isset($request->game_type) && $request->game_type > 0) {
            $main = $main->where('s.game_type_id', $request->game_type);
        }

        $main = $main->groupBy('s.agent_id')
            ->groupBy('v.id')
            ->groupBy('s.game_type_id')
            ->select(
                DB::raw("CASE WHEN s.agent_id = temp.agent_id THEN 2 ELSE 1 END as type"),
                DB::raw('temp.child as child'),
                DB::raw('temp.agent_id as agent_id'),
                DB::raw('IFNULL(vi.name, v.name) as vendor'),
                'v.id as vendor_id',
                's.game_type_id as type_id',
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.total_bet END) as bet'),
                DB::raw('SUM(CASE WHEN v.id = 36 THEN 0 ELSE s.total_win_lose END) as win_lose'),
                DB::raw('SUM(s.total_cost) as cost'),
                DB::raw('SUM(s.total_commission) as commission'),
                DB::raw('SUM(s.total_jackpot) as jackpot'),
                DB::raw('SUM(s.total_share_amount) as share_amount'),
                DB::raw('SUM(s.total_v_jackpot) as v_jackpot'),
                DB::raw('(s.vendor_id + 1000) as sort')
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as z'))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'z.agent_id')
            ->leftJoin('game_types as t', 't.id', 'z.type_id')
            ->groupBy('z.type', 'z.agent_id', 'z.vendor_id', 't.id')
            ->orderBy('z.agent_id', 'asc')
            ->orderBy('z.vendor_id', 'asc')
            ->orderBy('z.type', 'asc')
            ->select(
                DB::raw("CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END AS type"),
                'z.agent_id',
                'a.username as agent_username',
                'a.nickname as agent_nickname',
                'z.vendor',
                'z.vendor_id',
                't.name as game_type',
                't.id as game_type_id',
                DB::raw('SUM(z.bet) as total_bet'),
                DB::raw('SUM(z.win_lose) as win_lose'),
                DB::raw('SUM(z.cost) as cost'),
                DB::raw('SUM(z.commission) as commission'),
                DB::raw('SUM(z.jackpot) as lottery'),
                DB::raw('SUM(z.share_amount) as share_amount'),
                DB::raw('SUM(z.v_jackpot) as v_jackpot'),
                'z.sort'
            );
        return $model;
    }

    private function platformReconciliationEventModel($request, $temp, $agent, $start_time, $end_time)
    {
        $main = DB::table('summary_event_record_agents as s')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 's.agent_id')
            ->mergeBindings($temp)
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time)
            ->where('s.event_id', 1)
            ->groupBy('s.agent_id')
            ->select(
                DB::raw("CASE WHEN s.agent_id = temp.agent_id THEN 2 ELSE 1 END as type"),
                DB::raw('temp.agent_id as agent_id'),
                DB::raw('temp.child as child'),
                DB::raw('(s.event_id * -1) as vendor_id'),
                DB::raw('SUM(s.point) AS point'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as z'))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'z.agent_id')
            ->groupBy('z.agent_id', 'z.type')
            ->orderBy('z.agent_id', 'asc')
            ->orderBy('z.type', 'asc')
            ->select(
                DB::raw("CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END AS type"),
                'z.agent_id',
                'a.username as agent_username',
                'a.nickname as agent_nickname',
                DB::raw("'V-world' as vendor"),
                DB::raw("'-1' as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('0 as cost'),
                DB::raw('0 as commission'),
                DB::raw('SUM(TRUNCATE(z.point, 4)) as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                'z.sort'
            );

        return $model;
    }

    private function platformReconciliationFeeModel($request, $temp, $start_time, $end_time)
    {
        $ar_fee = [];
        if (strpos($request->vendor_id, '-3') !== false) {
            $ar_fee[] = 3;
        }
        if (strpos($request->vendor_id, '-4') !== false) {
            $ar_fee[] = 4;
        }
        $main = DB::table('summary_event_record_agents as s')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 's.agent_id')
            ->mergeBindings($temp)
            ->whereIn('s.event_id', $ar_fee)
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time)
            ->groupBy('s.agent_id', 's.event_id')
            ->select(
                DB::raw("CASE WHEN s.agent_id = temp.agent_id THEN 2 ELSE 1 END AS type"),
                DB::raw("temp.agent_id AS agent_id"),
                DB::raw("temp.child AS child"),
                DB::raw("SUM(s.point) AS point"),
                DB::raw('(s.event_id * -1) as vendor_id'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw("({$main->toSql()}) as z"))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'z.agent_id')
            ->groupBy('z.agent_id', 'z.type', 'z.vendor_id')
            ->orderBy('z.agent_id', 'asc')
            ->orderBy('z.type', 'asc')
            ->orderBy('z.sort', 'asc')
            ->select(
                DB::raw("CASE WHEN z.type = 2 THEN 'member' ELSE 'agent' END AS type"),
                'z.agent_id',
                'a.username as agent_username',
                'a.nickname as agent_nickname',
                DB::raw("CASE z.vendor_id WHEN -3 THEN 'payfee' WHEN -4 THEN 'smsfee' END AS vendor"),
                'z.vendor_id',
                DB::raw("'-' as game_type"),
                DB::raw('0 as game_type_id'),
                DB::raw('0 as total_bet'),
                DB::raw('0 as win_lose'),
                DB::raw('SUM(z.point) as cost'),
                DB::raw('0 as commission'),
                DB::raw('0 as lottery'),
                DB::raw('0 as share_amount'),
                DB::raw('0 as v_jackpot'),
                'z.sort'
            );
        return $model;
    }

    private function platformReconciliationUnlimitedModel($request, $temp, $agent, $promotion, $start_time, $end_time)
    {
        $main = DB::table('summary_event_record_agents as s')
            ->join(DB::raw('(' . $temp->toSql() . ') as temp'), 'temp.child', 's.agent_id')
            ->mergeBindings($temp)
            ->where('s.summary_time', '>=', $start_time)
            ->where('s.summary_time', '<', $end_time)
            ->where('s.event_id', 2)
            ->groupBy('temp.agent_id')
            ->select(
                DB::raw("'agent' as type"),
                'temp.agent_id as agent_id',
                DB::raw('SUM(s.point) + SUM(s.self_point) AS point'),
                's.event_id as sort'
            );

        $model = DB::table(DB::raw('(' . $main->toSql(). ') as x'))
            ->mergeBindings($main)
            ->join('agents as a', 'a.id', 'x.agent_id')
            ->select(
                'x.type',
                'x.agent_id',
                'a.username as agent_username',
                'a.nickname as agent_nickname',
                DB::raw("'" . $promotion . "' as vendor"),
                DB::raw("-2 as vendor_id"),
                DB::raw("'-' as game_type"),
                DB::raw("0 as game_type_id"),
                DB::raw("0 as total_bet"),
                DB::raw("0 as win_lose"),
                DB::raw("0 as cost"),
                DB::raw("0 as commission"),
                'x.point',
                DB::raw("0 as share_amount"),
                DB::raw("0 as v_jackpot"),
                'x.sort'
            );

        return $model;
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

            $model = DB::table('prize_members as pm')
                ->join('prize_contents as c', 'c.id', 'pm.content_id')
                ->join('prizes as p', 'p.id', 'pm.prize_id')
                ->join('events as e', function ($join) use ($request) {
                    $join->on('e.id', 'p.event_id')
                        ->where('e.id', ((isset($request->event_id) && $request->event_id != '') ? $request->event_id : 0));
                })
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'pm.member_id');
                    if ($request->member_username != '') {
                        $join->where('m.username', $request->member_username);
                    }
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.id', 'm.parent')
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    if ($request->agent_username != '') {
                        $join->where('a.username', $request->agent_username);
                    }
                })
                ->leftJoin('event_names as n', function ($join) use ($request) {
                    $join->on('n.event_id', 'e.id')
                        ->where('n.language_id', $request->LANGUAGE);
                })
                ->where('pm.status', 1);
            //總數
            $total = $model->getCountForPagination();
            // 排序欄位
            $data = $model
                ->orderBy('pm.updated_at', 'DESC')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    DB::raw('DATE_ADD(pm.updated_at,INTERVAL 8 HOUR) as updated_at'),
                    DB::raw("(CASE WHEN n.title is NULL THEN e.title ELSE n.title END) as event_title"),
                    'm.username as member_username',
                    'a.username as agent_username',
                    'c.name as prize_name',
                    'c.point as prize_point',
                    DB::raw('CASE WHEN c.type = 1 THEN TRUNCATE(pm.lottery_point, 2) ELSE c.point END as prize_point')
                )
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

    private function setNullToZeroAmount($value)
    {
        if (is_null($value)) {
            return 0;
        }

        return $value;
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
