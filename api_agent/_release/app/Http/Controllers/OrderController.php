<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use target\support\Services\CashFlowService;
use target\support\Models\Order as SupportOrder;
use App\Repositories\AgentOperationLogRepository;
use App\Models\Agent;
use App\Models\Order;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Helpers\Common;
use App\Helpers\SQLHelper;
use App\Helpers\RandomHelper;
use App\REDIS;
use Validator;
use DB;
use log;

class OrderController extends Controller
{

    /**
     * order/list
     * -訂單列表(只能查看自己的會員)
     *
     * @bodyParam ordery_type_id integer 訂單系統編號 Example: 1
     * @bodyParam currency_id integer 幣別系統編號 Example: 1
     * @bodyParam username string 會員帳號 Example: abc
     * @bodyParam order_no string 訂單編號 Example: 123456
     * @bodyParam status integer 狀態 Example: 1
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "order_no": "訂單編號,ex:16431681",
     *            "username": "會員帳號,ex:test",
     *            "order_type_id": "訂單類別系統編號,ex:2",
     *            "currency_id": "幣種系統編號,ex:1",
     *            "member_id": "會員系統編號,ex:1",
     *            "amount": "金額,ex:100",
     *            "point": "平台點數,ex:100",
     *            "status": "狀態0建立1成功2失敗,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *        },
     *        {
     *            "id": 2,
     *            "order_no": "x16431681",
     *            "username": "test",
     *            "order_type_id": 2,
     *            "currency_id": 1,
     *            "member_id": 3,
     *            "amount": 300,
     *            "point": 300,
     *            "status": 1,
     *            "created_at": "2019-03-06 16:55:22",
     *            "updated_at": "2019-03-08 17:43:28"
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
    public function list(Request $request)
    {
        try {
            $model = new Order();
            $validator = Validator::make($request->all(), $model->rules_search, $this->messages());

            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }

            $start_time = date('Y-m-d H:i:00.000', strtotime($request->start_time . '-8hour'));
            $end_time = date('Y-m-d H:i:59.999', strtotime($request->end_time . '-8hour'));

            $model = DB::table('orders as o')
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.id', 'o.member_id');
                    if (Common::IsNotNullOrEmptyString($request->member_username)) {
                        $join->where('m.username',  $request->member_username );
                    }
                })
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('m.parent', 'a.id')
                        //只能查自己樹狀的
                        ->where('a.hierarchy', 'like', '%,' . $request->HOST . ',%');
                    if (Common::IsNotNullOrEmptyString($request->agent_username)) {
                        $join->where('a.username',  $request->agent_username );
                    }
                })
                ->join('agent_cash_flows as acf', 'o.agent_cash_flow_id', 'acf.id')
                ->join('cash_flows as cf', function ($join) use ($request) {
                    $join->on('acf.cash_flow_id', 'cf.id');
                    if (Common::IsNotNullOrEmptyString(trim($request->cash_flow_id))) {
                        $join->where('cf.id', trim($request->cash_flow_id));
                    }
                })
                ->leftJoin('order_types as ot', 'o.order_type_id', 'ot.id')
                ->whereBetween('o.created_at', [$start_time, $end_time]);

            if (Common::IsNotNullOrEmptyString($request->id)) {
                $model = $model->where('o.id', $request->id);
            }
            if (Common::IsNotNullOrEmptyString($request->order_type_id)) {
                $model = $model->where('o.order_type_id', $request->order_type_id);
            }
            if (Common::IsNotNullOrEmptyString($request->currency_id)) {
                $model = $model->where('o.currency_id', $request->currency_id);
            }
            if (Common::IsNotNullOrEmptyString($request->order_no)) {
                $model = $model->where('o.order_no',  $request->order_no);
            }
            if (Common::IsNotNullOrEmptyString($request->third_party_order_no)) {
                $model = $model->where('o.third_party_order_no',  $request->third_party_order_no);
            }
            if (Common::IsNotNullOrEmptyString(trim($request->status))) {
                $model = $model->where('o.status', trim($request->status));
            }

            $total = $model->count();
                
            $total_summary = $model
                ->select
                (
                    DB::raw('count(*) as total_count'), 
                    DB::raw('sum(o.amount) as total_amount'),
                    DB::raw('sum(o.point) as total_point')
                )
                ->first();  
                
            //DB::enableQueryLog();

            $data = $model->groupBy('o.id')
                ->orderBy('o.created_at', 'desc')
                ->orderBy('o.id', 'desc')
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->select(
                    'o.*',
                    'm.username as member_username',
                    'a.id as agent_id',
                    'a.username as agent_username',
                    'cf.name as cf_name',
                    'ot.name as order_type_name'
                )
                ->get();

            //$sql_cmd = SQLHelper::ConvertSQLLogToSQLBindingCommand(DB::getQueryLog()[0]);

            foreach ($data as $item) {
                $item->status_name = __('param.orders_status.' . $item->status);
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data,
                'pagination' => $this->getPagination($total, $request->per_page, $request->page),
                'total_summary' => $total_summary
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2],
                'data' => 'QueryException'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
                'data' => 'Exception'
            ]);
        }
    }

    /**
     * order/manual-deposit
     * - 手動到帳上分 *
     */
    public function manualDeposit(Request $request)
    {
        $is_begin_transaction = false;
        try {
            $order_id = $request->order_id;

            $order = new Order();
            $agentOperationLogRepository = new AgentOperationLogRepository();
            $service = new CashFlowService();

            DB::beginTransaction();
            $is_begin_transaction = true;
            $queryOrder = $order->where('id', $order_id)->lockForUpdate()->first();
            if (!is_object($queryOrder)) {
                DB::rollback();
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                ]);
            } elseif ($queryOrder->status == 3) {
                DB::rollback();
                return response()->json([
                    'code' => 4501,
                    'message' => __('error_message.4501'),
                ]);
            }
            $member = Member::find($queryOrder->member_id);
            if ($member->event_mode > 0) {
                //鎖分模式中禁止上分
                DB::rollback();
                return response()->json([
                    'code' => 2043,
                    'message' => __('error_message.2043')
                ]);
            }
          
            // Step 1. 更新 orders狀態            
            $queryOrder->update([
                'status' => 1,  //updateMemberBalance 裡面的限制
                'remark' => 'manualDeposit by' . $request->ID
            ]);

            // Step 2. 寫入一筆 agent_operate_logs
            $agentOperationLogRepository->insert(
                $request->ID,
                $queryOrder->id,
                'order_manual_deposit',
                $request->memo . ''
            );

            $supportOrder = SupportOrder::find($order_id);

            // Step 3. 更新 member錢包
            // Step 4. 寫入一筆 transaction_log
            // Step 5. 寫稽核流水
            $service->updateMemberBalance($supportOrder);
            DB::commit();
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            if ($is_begin_transaction == true) DB::rollback();
            $info = $e->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $e) {
            if ($is_begin_transaction == true) DB::rollback();
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
    
    /**
     * order/reset-status
     * - 重置狀態到 pending(訂單建立: 0)
     */
    public function resetStatus(Request $request)
    {
        try {
            $order_id = $request->order_id;

            $order = new Order();
            $agentOperationLogRepository = new AgentOperationLogRepository();

            DB::beginTransaction();

            $queryOrder = $order->where("id" , $order_id)->lockForUpdate()->first();

            if(!is_object($queryOrder))
            {                 
                DB::rollback();
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                ]);
            }

            if($queryOrder->status == 3 || $queryOrder->status == 0)
            {           
                DB::rollback();     
                return response()->json([
                    'code' => 4501,
                    'message' => __('error_message.4501'),
                ]);
            }
          
            // Step 1. 更新 orders狀態            
            $queryOrder->update([
                'status' => 0,  //updateMemberBalance 裡面的限制
                'remark' => 'resetStatus by'.$request->ID
            ]);

            // Step 2. 寫入一筆 agent_operate_logs
            $agentOperationLogRepository->insert($request->ID ,  $queryOrder->id , 'order_reset_status' , $request->memo.'');
                      
            DB::commit();       
            
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
            ]);

        } catch (\Illuminate\Database\QueryException $e) {
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

    /**
     * order/view
     * -訂單詳細資料(只能查看自己的會員)
     *
     * @bodyParam id integer required 訂單系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "id": "系統編號,ex:1",
     *          "order_type_id": "訂單類別系統編號,ex:2",
     *          "currency_id": "幣種系統編號,ex:1",
     *          "member_id": "會員系統編號,ex:1",
     *          "amount": "金額,ex:100",
     *          "point": "平台點數,ex:100",
     *          "status": "狀態0建立1成功2失敗,ex:1",
     *          "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *          "updated_at": "更新時間,ex:2019-03-08 17:43:28"
     *    }
     * }
     */
    public function show(Request $request)
    {
        try {
            $model = new Order();
            // 驗證POST資料
            $validator = Validator::make($request->all(), $model->rules_show, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                    'data' => $validator->errors()
                        ->all()
                ]);
            }
            $key = array(
                'order',
                $request->id
            );
            $redis = new REDIS();
            if (!$redis->exists($key)) {
                $data = Order::select('orders.id', 'orders.order_type_id', 'orders.currency_id', 'orders.member_id', 'orders.amount', 'orders.point', 'orders.status', 'orders.created_at', 'orders.updated_at')->leftJoin('members as m', 'm.id', 'orders.member_id')
                    ->where('m.parent', $request->ID)
                    ->where('orders.id', $request->id)
                    ->First();
                if (!$data) {
                    return response()->json([
                        'code' => 1307,
                        'message' => __('error_message.1307')
                    ]);
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
     * order/getAutoDeposit
     * -訂單詳細資料(只能查看自己的會員)
     *
     * @bodyParam order_no integer 訂單編號 Example: 202009048839710568
     * @bodyParam member_username string 會員帳號 Example: abc
     * @bodyParam account_no integer 銀行帳號 Example: 123456789
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "order_id": "訂單系統編號,ex:1",
     *          "order_no": "訂單編號,ex:202009048839710568",
     *          "bank_name": "銀行名稱,ex:XX銀行",
     *          "bank_code": "銀行代碼,ex:001",
     *          "account_name": "帳戶名稱,ex:abc",
     *          "account_no": "銀行帳戶,ex:123456789",
     *          "username": "會員帳號,ex:abc",
     *          "amount": "訂單金額,ex:100",
     *          "status": "狀態0建立1待審核2失敗3成功,ex:1",
     *    }
     * }
     */
    public function getAutoDeposit(Request $request)
    {
        try {
            if($request->HOST != 1) {
                $agent = Agent::find($request->HOST);
                $agentGroupId = $agent->cash_flow_group_id;
            } else {
                $agentGroupId = 0;
            }
            $model = DB::table('orders as o')
                ->join('agent_cash_flows as acf', function ($join) use ($agentGroupId) {
                    $join->on('acf.id', 'o.agent_cash_flow_id')
                        ->where('acf.cash_flow_id', 5);
                    if($agentGroupId > 0){
                        $join->where('agent_group_id', $agentGroupId);
                    }
                })
                ->join('members as m', 'm.id', 'o.member_id')
                ->join('order_images as oi', 'oi.order_id', 'o.id')
                ->join('bank_accounts as ba', 'ba.id', 'oi.bank_id')
                ->join('order_types as ot', 'ot.id', 'ba.order_type_id');

            $start_time = date('Y-m-d H:i:s', strtotime($request->start_time . '-8hours'));
            $end_time = date('Y-m-d H:i:s', strtotime($request->end_time . '-8hours'));

            if (isset($request->order_no) && $request->order_no != '') {
                $model = $model->where('o.order_no', $request->order_no);
            } elseif (isset($request->member_username) && $request->member_username != '') {
                $model = $model->where('m.username', $request->member_username);
            } elseif (isset($request->account_no) && $request->account_no != '') {
                $model = $model->where('ba.account_name', $request->account_no);
            }

            if (isset($request->status) && $request->status != '' && $request->status != "-1") {
                $model = $model->where('o.status', $request->status);
            }

            $model = $model->whereBetween('o.created_at', [$start_time, $end_time]);

            $total = $model->count();

            $data = $model->orderBy('o.id', 'desc')
                ->select(
                    DB::raw('DATE_ADD(o.created_at,INTERVAL 8 HOUR) as create_at'),
                    'o.id as order_id',
                    'o.order_no as order_no',
                    'ot.name as bank_name',
                    'ot.key as bank_code',
                    'ba.account_name as account_name',
                    'ba.account_no as account_no',
                    'm.username as username',
                    'o.amount as amount',
                    'o.remark as remark',
                    'o.status as status'
                )
                ->skip(($request->page - 1) * $request->per_page)
                ->take($request->per_page)
                ->get();

            if (!$data) {
                return response()->json([
                    'code' => 1307,
                    'message' => __('error_message.1307')
                ]);
            }
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
     * order/getImage
     * -訂單詳細資料(只能查看自己的會員)
     *
     * @bodyParam order_id integer required 訂單系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *          "id": "訂單系統編號,ex:1",
     *          "bank_name": "銀行名稱,ex:XX銀行",
     *          "bank_code": "銀行代碼,ex:001",
     *          "account_name": "帳戶名稱,ex:abc",
     *          "account_no": "銀行帳戶,ex:123456789",
     *          "image": "圖片,ex:",
     *          "remark": "備註,ex:aaa",
     *    }
     * }
     */
    public function getImage(Request $request)
    {
        try {
            $data = DB::table('order_images as oi')
                ->join('orders as o', 'o.id', 'oi.order_id')
                ->join('bank_accounts as ba', 'ba.id', 'oi.bank_id')
                ->join('order_types as ot', 'ot.id', 'ba.order_type_id')
                ->join('members as m', 'm.id', 'o.member_id')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('o.id', $request->order_id)
                ->select(
                    'o.id as id',
                    'ot.name as bank_name',
                    'ot.key as bank_code',
                    'ba.account_name as account_name',
                    'ba.account_no as account_no',
                    'o.amount as amount',
                    'm.username as username',
                    'oi.image as image',
                    'o.remark as remark',
                    'o.status as status',
                    DB::raw('DATE_ADD(o.created_at,INTERVAL 8 HOUR) as create_at'),
                    DB::raw('DATE_ADD(o.pay_time,INTERVAL 8 HOUR) as pay_time'),
                    'm.first_name',
                    'a.username as agent_username'
                )
                ->first();
            if ($data->status == 2 || $data->status == 3) {
                $remark = json_decode($data->remark, true);
                if (is_array($remark)) {
                    $agent = Agent::find($remark['operator']);
                    $data->operator = $agent['username'];
                } else {
                    $data->operator = '-';
                }
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
     * order/reviewOrder
     * -審核訂單
     *
     * @bodyParam id integer required 訂單系統編號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *    }
     * }
     */
    public function reviewOrder(Request $request)
    {
        try {
            $order = SupportOrder::find($request->id);
            if ($order->status == 1) {
                //審核前要先確定訂單是待審核的狀態
                $member = Member::find($order->member_id);
                if ($member->event_mode > 0) {
                    //鎖分模式中禁止上分
                    return response()->json([
                        'code' => 2043,
                        'message' => __('error_message.2043')
                    ]);
                }

                $order->pay_time = date('Y-m-d H:i:s');
                $data = array();
                $data['message'] = $request->remark;
                $data['operator'] = $request->ID;
                $data = json_encode($data);
                log::info('review_order_' . $request->id, $request->all());
                if ($request->status == 3) {
                    $service = new CashFlowService();
                    $service->updateMemberBalance($order);
                    log::info('get_order_info_' . $request->id, (array)$order);
                    $order->remark = $data;
                    $order->save();

                    //SR02部分開分項目需要新增操作者的紀錄
                    TransactionLog::where('order_id', $order->id)
                        ->where('type', 3)
                        ->update(
                            [
                                'operator' => $request->USERNAME,
                                'admin' => $request->ID
                            ]
                        );
                } else {
                    $order->remark = $data;
                    $order->status = $request->status;
                    $order->save();
                }

                if ($order) {
                    //審核完畢,redis減少未處理的訂單數
                    $this->reduceDepositRedis($order->agent_group_id);
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                } else {
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                    ]);
                }
            } else {
                return response()->json([
                    'code' => 200203,
                    'message' => __('error_message.200203'),
                ]);
            }
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

    private function reduceDepositRedis($agent_cash_flow_id)
    {
        $redis = new REDIS();
        $key = array(
            'notice',
            'deposit',
            $agent_cash_flow_id
        );
        //如果沒有資料,就查DB做寫入
        if (!$redis->exists($key)) {
            $model = Order::where('agent_cash_flow_id', $agent_cash_flow_id)
                ->where('status', 1);
            $amount = $model->count();
            $last_order_id = $model->orderBy('id', 'DESC')->pluck('id')->first();
            $data = array(
                'amount' => $amount,
                'last_order_id' => $last_order_id
            );
            $redis->setCommon($key, $data);
        } else {
            $data = $redis->get($key);
            $data = array(
                'amount' => $data->amount--,
                'last_order_id' => $data->last_order_id
            );
            $redis->setCommon($key, $data);
        }
    }

    private function messages()
    {
        return [
            'id.required' => __('error_message.1309'),
            'id.numeric' => __('error_message.1310'),

            'order_type_id.numeric' => __('error_message.1301'),

            'currency_id.numeric' => __('error_message.1302'),

            'member_id.numeric' => __('error_message.1303'),

            'amount.numeric' => __('error_message.1304'),

            'status.in' => __('error_message.1306'),

            'page.required' => __('error_message.2005'),
            'page.numeric' => __('error_message.2006'),

            'per_page.required' => __('error_message.2007'),
            'per_page.numeric' => __('error_message.2008')
        ];
    }
}