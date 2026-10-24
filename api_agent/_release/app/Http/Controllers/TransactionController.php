<?php

namespace App\Http\Controllers;

use App\Enums\TransactionEnum;
use Illuminate\Http\Request;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Models\Member;
use App\Models\Agent;
use App\Models\TransactionLog;
use App\Models\TransactionLogAgent;
use App\Models\FlowLimit;
use App\Services\TelegramService;
use App\Repositories\AgentRepository;
use App\REDIS;
use App\SmsApi;
use App\Helpers\Common;
use Validator;
use DB;

class TransactionController extends Controller
{

    protected $tgService;

    public function __construct()
    {
        $this->tgService = New TelegramService();
    }

    /**
     * agentTransaction
     * -代理轉帳
     *
     * @bodyParam agent_id integer required 代理帳號 Example: 30
     * @bodyParam type integer required 類型1:開分2:洗分
     * @bodyParam point integer required 點數 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "balance": 100
     * }
     */
    public function agent(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'agent_id' => 'required|digits_between:0,10',
                'type' => 'required|in:1,2',
                'point' => array(
                    'required',
                    'regex:/(^[1-9][0-9]*$)/u'
                ),
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
            DB::beginTransaction();
            // 取發送者代理資料
            $from_agent = Agent::find($request->ID);
            // 取接收者代理資料
            $to_agent = Agent::find($request->agent_id);
            // 代理不存在
            if (is_object($from_agent) && is_object($to_agent)) {
                if ($request->type == 1) {
                    if (number_format($to_agent->balance) == -1) {
                        //如果接收者本身是無限制的餘額，不可轉帳
                        $status = 2004;
                    } else {
                        // 轉入驗證餘額是否足夠
                        if ($from_agent->balance >= $request->point || number_format($from_agent->balance) == -1) {
                            //先扣執行者餘額(無限制就不用扣除)
                            if ($from_agent->balance != -1) {
                                $result = $from_agent->decrement('balance', $request->point);
                            } else {
                                $result = true;
                            }
                            if ($result) {
                                // 重新取最後的餘額
                                $from_agent = Agent::find($request->ID);
                                if (number_format($from_agent->balance) < 0 && number_format($from_agent->balance) != -1) {
                                    // 餘額如果小於0，加回扣除的金額
                                    $result = $from_agent->increment('balance', $request->point);
                                    $from_agent = Agent::find($request->ID);
                                    // 餘額小於轉出金額
                                    $status = number_format($from_agent->balance);
                                    $status = 1022;
                                } else {
                                    //執行者成功扣除後加上接收者的餘額
                                    $result = $to_agent->increment('balance', $request->point);
                                    // 重新取最後的餘額
                                    $to_agent = Agent::find($request->agent_id);
                                    $status = 1000;
                                }
                            } else {
                                // 資料庫操作失敗
                                $status = 2001;
                            }
                        } else {
                            // 代理餘額小於轉入金額，餘額不足(-1等於無限制)
                            $status = 1022;
                        }
                    }
                } else if ($request->type == 2) {
                    // 轉出
                    if (number_format($to_agent->balance) == -1) {
                        //如果接收者本身是無限制的餘額，不可轉帳
                        $status = 2004;
                    } else {
                        if ($to_agent->balance >= $request->point) {
                            //先扣除接收者餘額
                            $result = $to_agent->decrement('balance', $request->point);
                            if ($result) {
                                // 重新取最後的餘額
                                $to_agent = Agent::find($request->agent_id);
                                if ($to_agent->balance < 0) {
                                    // 轉出後如果小於0，加回扣除的金額
                                    $result = $to_agent->increment('balance', $request->point);
                                    $to_agent = Agent::find($request->agent_id);
                                    // 餘額小於轉出金額
                                    $status = 1022;
                                } else {
                                    //成功後加上執行者的餘額(無限制就不用處理)
                                    if ($from_agent->balance != -1) {
                                        $result = $from_agent->increment('balance', $request->point);
                                        $from_agent = Agent::find($request->ID);
                                    }
                                    $status = 1000;
                                }
                            } else {
                                // 資料庫操作失敗
                                $status = 2001;
                            }
                        } else {
                            // 餘額小於轉出金額，餘額不足
                            $status = 1022;
                        }
                    }
                }

                $result_data = ['from_balance' => $from_agent->balance, 'to_balance' => $to_agent->balance];

                // 寫入轉點LOG
                $log = array();
                $log['from_agent_id'] = $from_agent->id;
                $log['to_agent_id'] = $to_agent->id;
                $log['type'] = $request->type;
                $log['amount'] = $request->point;
                $log['from_limit_of_credit'] = $from_agent->balance;
                $log['from_used_credit'] = 0;
                $log['to_limit_of_credit'] = $to_agent->balance;
                $log['to_used_credit'] = 0;
                $log['status'] = ($status == 1000) ? 1 : $status;
                $log['platform'] = 2;
                $log['operator'] =  $from_agent->username;
                if (!TransactionLogAgent::create($log)) {
                    // 資料庫操作失敗
                    $status = 2001;
                } else {
                    $redis = new REDIS();
                    $redis->delete(['agent']);
                    DB::commit();
                }
                return response()->json([
                    'code' => $status,
                    'message' => __('error_message.' . $status),
                    'data' => $result_data,
                ]);
            } else {
                // 代理不存在
                return response()->json([
                    'code' => 1005,
                    'message' => __('error_message.1005')
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

    /**
     * memberTransaction
     * - 玩家開洗分
     *
     * @bodyParam member_id integer required 玩家帳號 Example: 30
     * @bodyParam type integer required 類型1:開分2:洗分
     * @bodyParam point integer required 點數 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "balance": 100
     * }
     */
    public function member(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'member_id' => 'required|digits_between:0,10',
                'type' => 'required|in:1,2',
                'point' => 'required|numeric|min:0.01'
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
            DB::beginTransaction();
            // 取會員資料
            $member = Member::find($request->member_id);
            // 取代理資料
            $agent = Agent::find($request->ID);
            if (is_object($member) && is_object($agent)) {
                if ($member->event_mode > 0) {
                    //鎖分模式中禁止上分
                    DB::rollback();
                    return response()->json([
                        'code' => 2043,
                        'message' => __('error_message.2043')
                    ]);
                }

                $parent = Agent::find($member->parent);
                //稽核流水開關
                $settings = DB::table('agent_customized_settings')
                    ->where('agent_group_id', $parent->customized_setting_group_id)
                    ->where('setting_key', AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE)
                    ->select(
                        'agent_group_id',
                        'setting_key',
                        'value'
                    )
                    ->first();
                if (is_object($settings) && $settings->value == 1) {
                    //查玩家稽核流水
                    $flow_limit = FlowLimit::where('member_id', $request->member_id)
                        ->where('status', 0)
                        ->groupBy('member_id')
                        ->select(
                            DB::raw('SUM(flow_limit) AS total_flow_limit'),
                            DB::raw('SUM(reach) AS total_reach')
                        )
                        ->first();
                    if (is_object($flow_limit) && ($flow_limit->total_flow_limit - $flow_limit->total_reach) > 0 && $request->type == 2) {
                        // 會員號不存在
                        //稽核流水開啟且未達稽核流水限制時, 禁止被開洗分
                        return response()->json([
                            'code' => 1501,
                            'message' => __('error_message.1501')
                        ]);
                    }
                }

                $before_balance = $member->balance;
                //開分
                if ($request->type == 1) {
                    // 轉入驗證餘額是否足夠
                    if ($agent->balance >= $request->point || number_format($agent->balance) == -1) {
                        //先扣代理餘額(無限制就不用扣除)
                        if ($agent->balance != -1) {
                            $result = $agent->decrement('balance', $request->point);
                        } else {
                            $result = true;
                        }

                        if ($result) {
                            // 重新取最後的餘額
                            $agent = Agent::find($request->ID);
                            if (number_format($agent->balance) < 0 && number_format($agent->balance) != -1) {
                                // 餘額如果小於0，加回扣除的金額
                                $agent->increment('balance', $request->point);
                                $agent = Agent::find($request->ID);
                                // 餘額小於轉出金額
                                $status = number_format($agent->balance);
                                $status = 1022;
                            } else {
                                //代理成功扣除後加上會員的餘額
                                $member->increment('balance', $request->point);
                                // 重新取最後的餘額
                                $member =  Member::find($request->member_id);
                                $status = 1000;
                            }
                        } else {
                            // 資料庫操作失敗
                            $status = 2001;
                        }
                    } else {
                        // 代理餘額小於轉入金額，餘額不足(-1等於無限制)
                        $status = 1022;
                    }
                } else if ($request->type == 2) {
                    // 轉出
                    if ($member->balance >= $request->point) {
                        $result = $member->decrement('balance', $request->point);
                        if ($result) {
                            // 重新取最後的餘額
                            $member =  Member::find($request->member_id);
                            if ($member->balance < 0) {
                                // 轉出後如果小於0，加回扣除的金額
                                $result = $member->increment('balance', $request->point);
                                $member =  Member::find($request->member_id);
                                // 餘額小於轉出金額
                                $status = 1022;
                            } else {
                                //成功後加上代理的餘額(無限制就不用處理)
                                if ($agent->balance != -1) {
                                    $result = $agent->increment('balance', $request->point);
                                    $agent = Agent::find($request->ID);
                                }
                                $status = 1000;
                            }
                        } else {
                            // 資料庫操作失敗
                            $status = 2001;
                        }
                    } else {
                        // 餘額小於轉出金額，餘額不足
                        $status = 1022;
                    }
                }

                $result_data = [
                    'agent_balance' => $agent->balance,
                    'member_balance' => $member->balance
                ];

                // 寫入轉點LOG
                $log = array();
                $log['transaction_no'] = $this->getTransactionNo();
                $log['agent_id'] = $agent->id;
                $log['member_id'] = $member->id;
                $log['vendor_id'] = 0;
                $log['type'] = (($request->type == 1) ? 4 : 5); //4:開分,5:洗分
                $log['point'] = $request->point;
                $log['vendor_point'] = 0;
                $log['rate'] = 1;
                $log['before_balance'] = $before_balance;
                $log['balance'] = $member->balance;
                $log['vendor_balance'] = 0;
                $log['status'] = $status;
                $log['result'] = json_encode($result_data);
                $log['operator'] = $agent->username;
                $log['platform'] = 2;
                $log['admin'] = $agent->id;
                $transaction_log = TransactionLog::create($log);
                if (!$transaction_log) {
                    // 資料庫操作失敗
                    $status = 2001;
                } else {
                    if ($request->type == 1) {
                        //稽核流水
                        $flow_limit = array();
                        $flow_limit['member_id'] = $member->id;
                        $flow_limit['type'] = 4; //開分
                        $flow_limit['reference_id'] = isset($transaction_log) ? $transaction_log->id : null;
                        $flow_limit['flow_limit'] = isset($request->audit_amount) ? $request->audit_amount : 0;
                        $flow_limit['admin'] = 0;
                        FlowLimit::create($flow_limit);
                    } else {
                        //issue 1136: 取消充值獎勵資格
                        DB::update("CALL SP_cancel_login_event(" . $request->member_id . ")");
                    }

                    $redis = new REDIS();
                    $redis->delete(['member']);
                    $redis->delete(['agent']);
                    DB::commit();
                }
                return response()->json([
                    'code' => $status,
                    'message' => __('error_message.' . $status),
                    'data' => $result_data
                ]);
            } else {
                // 會員號不存在
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007'),
                ]);
            }
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
     * agent/cashFlows
     * - 代收代付清單
     *
     * @bodyParam member_id integer required 玩家帳號 Example: 30
     * @bodyParam type integer required 類型1:開分2:洗分
     * @bodyParam point integer required 點數 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *      {
     *          "cash_flow_id": "4",
     *          "name": "USDT",
     *          "type": "1",
     *      },
     *      {
     *          "cash_flow_id": "13",
     *          "name": "Touch'n Go",
     *          "type": "2",
     *      }
     * ]
     * }
     */
    public function agentCashFlows(Request $request)
    {      
        try {
            $myAgentID = $request->ID;
            $rep = new AgentRepository();
    
            $isSuperAdmin = $rep->isSuperAdminOrSubAccount($myAgentID);
    
            $queryAgent = Agent::find($myAgentID);
    
            $query_sql = DB::table('agent_cash_flows as agc')
                ->join('cash_flows as cf', function ($join) use ($request) {
                    $join->on('agc.cash_flow_id', 'cf.id')
                        ->where('cf.status', 1);
                })
                ->leftJoin('cash_flow_names as cfn', function ($join) use ($request, $queryAgent) {
                    $join->on('cfn.cash_flow_id', 'cf.id')
                        ->where('cfn.language_id', $request->LANGUAGE)
                        ->where('cfn.agent_group_id', $queryAgent->customized_setting_group_id);
                });
    
            if (!$isSuperAdmin) {
                $query_sql = $query_sql->where('agc.agent_group_id', $queryAgent->cash_flow_group_id);
            }
    
            $result = $query_sql->orderBy('cf.type', 'ASC')
                ->select(
                    'cf.id as cash_flow_id',
                    DB::raw('IFNULL(cfn.name, cf.name) AS name'),
                    'cf.type'
                )
                ->distinct()
                ->get();
    
            return response()->json([
                'code' => '1000',
                'message' => __('error_message.1000'),
                'data' =>  $result
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
     * agent/eventReward
     * - 獎勵清單
     *
     * @bodyParam member_id integer required 玩家帳號 Example: 30
     * @bodyParam type integer required 類型1:開分2:洗分
     * @bodyParam point integer required 點數 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *      {
     *          "cash_flow_id": "4",
     *          "name": "USDT",
     *          "type": "1",
     *      },
     *      {
     *          "cash_flow_id": "13",
     *          "name": "Touch'n Go",
     *          "type": "2",
     *      }
     * ]
     * }
     */
    public function eventReward(Request $request)
    {
        try {
            $result = DB::table('params')
                ->whereRaw("type = 'transaction_type'")
                ->whereIn(
                    'value',
                    [
                        TransactionEnum::TYPE_ACTIVITY_AWARD, //13
                        TransactionEnum::TYPE_LOGIN_EVENT_REWARDS,  //15
                        TransactionEnum::TYPE_RECYCLE_LOGIN_ACTIVITY_REWARDS,   //16
                        TransactionEnum::TYPE_FIRST_DEPOSIT_REWARDS,    //19
                        TransactionEnum::TYPE_REGISTRATION_GIFT,    //20
                        TransactionEnum::TYPE_BIND_MOBILE_PHONE_GIFT,   //21
                        TransactionEnum::TYPE_DEPOSIT_REWARDS,  //24
                        TransactionEnum::TYPE_PICK_UP_ONCE_A_DAY,   //25
                        TransactionEnum::TYPE_INVITE_PLAYER_OFFERS,  //26
                        TransactionEnum::TYPE_W_JACKPOT     //30
                    ]
                )
                ->orderBy('value', 'ASC')
                ->select(
                    'value as event_reward_id',
                    'parameter AS name',
                    DB::raw('5 as type')
                )
                ->get();

            return response()->json([
                'code' => '1000',
                'message' => __('error_message.1000'),
                'data' =>  $result
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
     * refundTransaction
     * -退還遊戲商轉點失敗時多扣的點數
     *
     * @bodyParam transaction_id integer required 轉點編號 Example: 30
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "balance": 100
     * }
     */
    public function refund(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'transaction_id' => 'required|digits_between:0,10',
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
            DB::beginTransaction();

            //確認這筆記錄存在，沒補過點
            $row = TransactionLog::find($request->transaction_id);
            if (is_object($row)) {
                //驗證今日加上這筆是否超過10000點了(一天只能補10000點)
                $limit = 100000;
                $total = TransactionLog::where('type', 14)
                    ->whereBetween(
                        'created_at',
                        [
                            date('Y-m-d 00:00:00', strtotime('+8hour')),
                            date('Y-m-d 23:59:59', strtotime('+8hour'))
                        ]
                    )
                    ->select(DB::raw("SUM(point) as total"))
                    ->first();
                if (is_object($total) and ($total->total + $row->point) <= $limit) {
                    //更新此記錄為補過點
                    $update = $row->update(['status' => 9000]);
                    if ($update) {
                        //補點
                        $member = Member::lockForUpdate()->find($row->member_id);
                        $before_balance = $member->balance;
                        $update_balance = $member->increment('balance', $row->point);
                        if ($update_balance) {
                            //新增記錄
                            $log = array();
                            $log['transaction_no'] = $this->getTransactionNo();
                            $log['agent_id'] = $row->agent_id;
                            $log['member_id'] = $row->member_id;
                            $log['vendor_id'] = 0;
                            $log['type'] = 14; //14:補點
                            $log['point'] = $row->point;
                            $log['vendor_point'] = 0;
                            $log['rate'] = 1;
                            $log['before_balance'] = $before_balance;
                            $log['balance'] = ($before_balance + $row->point);
                            $log['vendor_balance'] = 0;
                            $log['status'] = 1000;
                            $log['remark'] = $row->id;
                            $log['result'] = '補點';
                            $log['operator'] = 'system';
                            $log['platform'] = 2;
                            $log['admin'] = $request->ID;
                            if (!TransactionLog::create($log)) {
                                // 資料庫操作失敗
                                $status = 2001;
                            } else {
                                $message = "[營運商後台手動補點]" . PHP_EOL .
                                    "點數:" . $row->point . "點" . PHP_EOL .
                                    "備註: TransactionLog ID - " . $row->id . PHP_EOL .
                                    "操作者:" . $request->ID;
                                //發TG告知有補帳
                                $this->tgService->sendMessage($message, 'info');
                                $redis = new REDIS();
                                $redis->delete(['member']);
                                DB::commit();

                                $status = 1000;
                            }
                        } else {
                            // 點數更新失敗
                            $status = 2004;
                        }
                    } else {
                        // 狀態更新失敗
                        $status = 2001;
                    }
                } else {
                    // 單日金額超過上限
                    return response()->json([
                        'code' => 9990,
                        'message' => __('error_message.9990', ['limit' => $limit]),
                    ]);
                }
            } else {
                // 訂單不存在
                $status = 1307;
            }

            return response()->json([
                'code' => $status,
                'message' => __('error_message.' . $status),
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

    private function getTransactionNo()
    {
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $is_exist = TransactionLog::whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->where('transaction_no', $number)->count();
        // 驗證不重覆
        if ($is_exist) {
            return $this->getTransactionNo();
        } else {
            return $number;
        }
    }

    private function messages()
    {
        return [
            'member_id.required' => __('error_message.1066'),
            'member_id.digits_between' => __('error_message.1067'),

            'agent_id.required' => __('error_message.2017'),
            'agent_id.digits_between' => __('error_message.2018'),

            'type.required' => __('error_message.2026'),
            'type.in' => __('error_message.2026'),

            'point.required' => __('error_message.1305'),
            'point.regex' => __('error_message.1305'),

            'audit_amount.required' => __('error_message.1305'),
            'audit_amount.regex' => __('error_message.1305'),

            'transaction_id.required' => __('error_message.1309'),
            'transaction_id.digits_between' => __('error_message.1310'),
        ];
    }
}