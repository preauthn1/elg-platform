<?php

namespace App\Http\Controllers;

use App\Enums\AgentCustomizedSettingKeyEnum;
use Illuminate\Http\Request;
use App\Models\MemberBankAccount;
use App\Models\Member;
use App\Models\Agent;
use App\Models\WithdrawalOrder;
use App\Models\TransactionLog;
use App\Models\Bank;
use App\REDIS;
use Validator;
use DB;
use log;
use App\Services\TelegramService;

class WithdrawalController extends Controller
{
    /**
     * withdrawal/check
     * - 檢查是否有綁定銀行帳號
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "bank_name": "銀行名稱,ex:XX銀行",
     *            "account_name": "帳戶名稱,ex:abc111",
     *            "account_no": "銀行帳戶,ex:123456789"
     *        }
     *    ]
     * }
     */
    public function check(Request $request)
    {
        try {
            $member = Member::find($request->ID);
            $agent_setting = $this->getAgentAdvanceSetting($member->parent);
//            $account = $this->getMemberAccount($request->ID);
            $model = MemberBankAccount::where('member_id', $request->ID)
                ->join('banks as b', 'b.id', 'member_bank_accounts.bank_id')
                ->select(
                    'b.name as bank_name',
                    'b.code as bank_code',
                    'member_bank_accounts.account_name as account_name',
                    'member_bank_accounts.account_no as account_no'
                )
                ->where('member_bank_accounts.status', 1)
                ->orderBy('member_bank_accounts.sort', 'DESC');
            $amount_limit = $this->getWithdrawalAmountLimit($request->ID);

            $data = array();
            $data['has_bank_card_setting'] = $agent_setting[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING];
            $data['has_bank_card'] = 0;
            $data['bank_card_amount'] = $agent_setting[AgentCustomizedSettingKeyEnum::BANK_CARD_AMOUNT];
            $data['mobile'] = null;
            $data['account_name'] = $member->first_name;
            $data['account'] = null;
            $data['min_amount'] = $amount_limit['MinAmount'];
            $data['max_amount'] = $amount_limit['MaxAmount'];
            $data['is_first'] = ($member->first_name == '') ? 1 : 0;
            $data['account_name_modify'] = $agent_setting[AgentCustomizedSettingKeyEnum::MODIFY_AUTO_WITHDRAWAL_ACCOUNT_NAME_VISIBLE];

            //issue 1136: 充值獎勵未領取前，洗分與發紅包後應該要被取消資格
            $param_count = '@o_total_count';
            DB::select('CALL SP_check_login_event(?, ' . $param_count . ')', array($request->ID));
            $output = DB::select(DB::raw('select ' . $param_count . ' as count'));
            $count = (is_null($output[0]->count)) ? 0 : $output[0]->count;
            $data['has_login_event_reward'] = ($count > 0) ? 1 : 0;

            if ($member->mobile_validate == 1) {
                $data['mobile'] = $member->mobile;
            }
            if (isset($request->account_no) && $request->account_no != '') {
                //如果有傳account_no就是來取帳號資訊的
                $account = $model->where('member_bank_accounts.account_no', $request->account_no)->first();
                if (is_object($account)) {
                    $data['has_bank_card'] = 1;
                    $data['account_name'] = ($member->first_name != '') ? $member->first_name : $account->account_name;
                    $data['account'] = $account;
                }
            } else {
                //如果沒有就是要判斷有無銀行卡或有無綁定銀行卡
                if ($agent_setting[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING] == 0) {
                    $account = $model->first();
                    if (is_object($account)) {
                        $data['account'] = array('0' => $account);
                    }
                } else {
                    //一目前設定幾組就顯示幾組
                    $account = $model->take($agent_setting[AgentCustomizedSettingKeyEnum::BANK_CARD_AMOUNT])->get();
                    if (is_object($account) && (count($account) > 0)) {
                        $data['has_bank_card'] = 1;
                        $data['account'] = $account;
                    }
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
     * withdrawal/getBankList
     * - 取得銀行清單
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "bank_name": "銀行名稱,ex:XX銀行",
     *            "account_name": "帳戶名稱,ex:abc111",
     *            "account_no": "銀行帳戶,ex:123456789"
     *        }
     *    ]
     * }
     */
    public function getBankList(Request $request)
    {
        try {
            $bank = Bank::join('agent_groups as ag', 'ag.id', 'banks.agent_group_id')
                ->join('agents as a', 'a.cash_flow_group_id', 'ag.id')
                ->join('members as m', function ($join) use ($request) {
                    $join->on('m.parent', 'a.id')
                        ->where('m.id', $request->ID);
                })
                ->leftjoin('bank_names as bn', function ($join) use ($request) {
                    $join->on('bn.bank_id', 'banks.id')
                        ->where('bn.language_id', $request->LANGUAGE);
                })
                ->where('banks.status', 1)
                ->orderBy('banks.sort', 'DESC')
                ->select(
                    'banks.id as bank_id',
                    DB::raw("(CASE WHEN bn.name is NULL THEN banks.name ELSE bn.name END) as bank_name"),
                    'banks.code as bank_code'
                )
                ->get();
            if (is_object($bank)) {
                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $bank
                ]);
            } else {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
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
     * withdrawal/account
     * - 綁定銀行帳號
     *
     * @bodyParam bank_id integer required 銀行系統編號
     * @bodyParam account_name string required 帳戶名稱
     * @bodyParam account_no integer required 銀行帳戶
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     *    ]
     * }
     */
    public function account(Request $request)
    {
        try {
            $data = array();
            $model = new MemberBankAccount();

            $validator = Validator::make($request->all(), $model->rules_update, $this->messages());
            if (!$validator->passes()) {
                return response()->json([
                    'code' => 2002,
                    'message' => $validator->errors()
                        ->all()
                ]);
            }

            $member = Member::where('id', $request->ID)->first();
            $agent_setting = $this->getAgentAdvanceSetting($member->parent);

            $account = MemberBankAccount::where('member_id', $request->ID)
                ->where('status', 1)
                ->orderBy('sort', 'desc')
                ->first();


            if (is_object($account)) {
                foreach ($request->account as $row) {
                    $data['bank_id'] = $row['bank_id'];
                    $data['account_name'] = $row['account_name'];
                    $data['account_no'] = $row['account_no'];
                }

                //issues_1130 因為新增了銀行卡 所以不再改動真實姓名
//                if ($member->first_name == '' || $agent_setting[AgentCustomizedSettingKeyEnum::MODIFY_AUTO_WITHDRAWAL_ACCOUNT_NAME_VISIBLE] == 1) {
//                    $member->first_name = $account_name;
//                    $member->save();
//                }

                if ($agent_setting[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING] == 0) {
                    if ($account->update($data)) {
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
                        'code' => 2004,
                        'message' => __('error_message.2004'),
                    ]);
                }
            } else {
                $create_member = false;
                $data['member_id'] = $request->ID;
                $data['operator_cs'] = 0;
                DB::beginTransaction();
                foreach ($request->account as $row) {
                    if ($agent_setting[AgentCustomizedSettingKeyEnum::HAS_BANK_CARD_SETTING] == 1) {
                        //如果是開啟銀行卡的要檢查不重復卡號
                        $checkAccountNo = DB::table('member_bank_accounts')
                            ->where('bank_id', $row['bank_id'])
                            ->where('account_no', $row['account_no'])
                            ->first();
                        if (is_object($checkAccountNo)) {
                            DB::rollback();
                            return response()->json([
                                'code' => 9017,
                                'message' => __('error_message.9017') . ' : ' . $row['account_no'],
                            ]);
                        }
                    }

                    $data['bank_id'] = $row['bank_id'];
                    $data['account_name'] = $row['account_name'];
                    $data['account_no'] = $row['account_no'];
                    $data['status'] = 1;

                    $create_member = MemberBankAccount::create($data);

                }
                if ($create_member) {
                    DB::commit();
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                } else {
                    DB::rollback();
                    return response()->json([
                        'code' => 2002,
                        'message' => __('error_message.2002'),
                    ]);
                }
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
     * withdrawal
     * - 新建自助洗分訂單
     *
     * @bodyParam amount integer 金額 Example: 100
     * @bodyParam amount integer 金額 Example: 100
     * @bodyParam amount integer 金額 Example: 100
     * @bodyParam amount integer 金額 Example: 100
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     *    ]
     * }
     */
    public function withdrawal(Request $request)
    {
        try {
            //增加稽核判斷
            if (app('App\Http\Controllers\FlowLimitController')->checkFlowLimit($request->ID)) {
                return response()->json([
                    'code' => 1980,
                    'message' => __('error_message.1980'),
                ]);
            }
            $member = Member::find($request->ID);
            $agent_setting = $this->getAgentAdvanceSetting($member->parent);
            //先檢查餘額有沒有足夠
            if ($request->amount > $member->balance) {
                return response()->json([
                    'code' => 1022,
                    'message' => __('error_message.1022'),
                ]);
            }

            $amount_limit = $this->getWithdrawalAmountLimit($request->ID);
            if ($request->amount > $amount_limit['MaxAmount'] || $request->amount < $amount_limit['MinAmount']) {
                return response()->json([
                    'code' => 9997,
                    'message' => __('error_message.9997'),
                ]);
            }

            DB::beginTransaction();
            $before_balance = $member->balance;
            $balance = ($member->balance - $request->amount);
            $member->update([
                'balance' => DB::raw('balance-' . $request->amount)
            ]);
            $agent = Agent::find($member->parent);

            $data = array();
            $data['bank_name'] = $request->bank_name;
            $data['bank_code'] = $request->bank_code;
            $data['account_name'] = $request->account_name;
            $data['account_no'] = $request->account_no;
            $data['agent_group_id'] = $agent->cash_flow_group_id;
            $data['member_id'] = $request->ID;
            $data['amount'] = $request->amount;
            $data['third_party_order_no'] = ' ';
            $data['status'] = 1;

            $order = WithdrawalOrder::create($data);

            $log = array();
            $log['transaction_no'] = $this->getTransactionNo();
            $log['agent_id'] = $member->parent;
            $log['member_id'] = $request->ID;
            $log['order_id'] = $order->id;
            $log['point'] = $order->amount;
            $log['before_balance'] = $before_balance;
            $log['balance'] = $balance;
            $log['type'] = 5;
            $log['operator'] = $member->username;
            $log['status'] = 9; //未審核前 狀態先保持在9

            $transaction_log = TransactionLog::create($log);

            //issue 1136: 取消充值獎勵資格
            DB::update("CALL SP_cancel_login_event(" . $request->ID . ")");

            DB::commit();

            $today = date("Y-m-d");
            $member_total_amount = DB::table('transaction_logs')
                ->where('member_id', $request->ID)
                ->where('type', 5)
                ->whereBetween('created_at', [$today . ' 00:00:00', $today . ' 23:59:59'])
                ->whereIn('status', [9, 1000])
                ->sum('point');
            if($member_total_amount >= $agent_setting[AgentCustomizedSettingKeyEnum::OVER_LIMIT_NOTICE_AMOUNT]) {
                $message = '【大額洗分通知】 ' . PHP_EOL .
                    '帳號:' . $member->username . PHP_EOL .
                    '洗分累積數目：' . $member_total_amount .  PHP_EOL;
                $tgService = new TelegramService();
                $tgService->sendMessage(
                    $message,
                    $agent_setting[AgentCustomizedSettingKeyEnum::RISK_CONTROL_TG_CHAT_ID],
                    '',
                    false
                );

                //找出會員的總代
                $general_agent = DB::table('members as m')
                    ->join('agents as a', 'a.id', 'm.parent')
                    ->where('m.id', $request->ID)
                    ->select(
                        DB::raw("REPLACE(SUBSTRING_INDEX(hierarchy_path, '/', 3), '/1/', '') as agent_id")
                    )
                    ->first();
                $agent_name = Agent::find($general_agent->agent_id);
                $params = DB::table('params')
                    ->where('type', 'tg_chat_id')
                    ->where('parameter', 'technology')
                    ->first();
                $message .= '站點 : ' . $agent_name->username;
                $tgService->sendMessage(
                    $message,
                    $params->value,
                    '',
                    false
                );
            }

            if ($transaction_log) {
                //成功建立訂單後,redis增加未處理的訂單數
                $this->addWithdrawRedis($agent->cash_flow_group_id, $order->id);
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
        } catch (\Illuminate\Database\QueryException $e) {
            $info = json_encode($e->errorInfo);
            Log::error($info);
            DB::rollback();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollback();
        }
    }

    /**
     * withdrawal/list
     * - 自助洗分清單
     *
     * @bodyParam page integer required 第幾頁 Example: 1
     * @bodyParam per_page integer required 每頁筆數 Example: 30
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": [
     *        {
     *            "id": "系統編號,ex:1",
     *            "created_at": "建立時間,ex:2019-03-06 16:55:22",
     *            "amount": "金額,ex:100",
     *            "status": "狀態0建立1成功2失敗,ex:1"
     *        },
     *        {
     *            "id": 2,
     *            "created_at": "2019-03-06 16:55:22",
     *            "amount": 300,
     *            "status": 2,
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
    public function withdrawalList(Request $request)
    {
        try {
            $request->page = (isset($request->page)) ? $request->page : 1;
            $request->per_page = (isset($request->per_page)) ? $request->per_page : 10;
            $withdrawal_orders_type = DB::table('cash_flows')->where('id', 6)->value('name');

            $autoWithdrawal = DB::table('withdrawal_orders')->where('member_id', $request->ID)
                ->select(
                    'id',
                    DB::raw('date_add(created_at, interval 8 hour) as created_at'),
                    'amount',
                    DB::raw("CASE status WHEN 1 THEN '" . __('param.pending_review') . "' " .
                        "WHEN 21 THEN '" . __('param.withdrawal_status_21') . "' " .
                        "WHEN 22 THEN '" . __('param.withdrawal_status_22') . "' " .
                        "WHEN 24 THEN '" . __('param.withdrawal_status_24') . "' " .
                        "WHEN 2 THEN '" . __('param.withdrawal_status_2') . "' " .
                        "WHEN 29 THEN '" . __('param.withdrawal_status_29') . "' " .
                        "WHEN 3 THEN '" . __('param.withdrawal_status_3') . "' " .
                        "WHEN 4 THEN '" . __('param.withdrawal_status_4') . "' " .
                        "WHEN 9 THEN '" . __('param.withdrawal_status_9') . "' " .
                        "ELSE '" . __('param.order_creation') . "' END AS status"),
                    DB::raw("'" . $withdrawal_orders_type . "' as remark")
                );

            $ota_type = DB::table('cash_flows')->where('id', 18)->value('name');

            $ota = DB::table('ota_orders')->where('member_id', $request->ID)
                ->select(
                    'id',
                    DB::raw('date_add(created_at, interval 8 hour) as created_at'),
                    'amount',
                    DB::raw("CASE status WHEN 1 THEN '" . __('param.pending_review') . "' " .
                        "WHEN 1 THEN '" . __('param.withdrawal_status_1') . "' " .
                        "WHEN 2 THEN '" . __('param.withdrawal_status_2') . "' " .
                        "WHEN 3 THEN '" . __('param.withdrawal_status_3') . "' " .
                        "ELSE '" . __('param.order_creation') . "' END AS status"),
                    DB::raw("'" . $ota_type . "' as remark")
                );
            $data = $autoWithdrawal->union($ota);

            $model = DB::table(DB::raw("({$data->toSql()}) AS s"))
                ->mergeBindings($data);

            $total = $model->count();

            $data = $model->orderBy('created_at', 'DESC')
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
            $info = json_encode($e->errorInfo);
            Log::error($info);
        } catch (\Exception $e) {
            Log::error($e->getMessage());
        }
    }

    public function getOtaUrl(Request $request)
    {
        try {
            $AgentCashFlow = DB::table('agent_cash_flows as acf')
                ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
                ->join('agents as a', function ($join) use ($request) {
                    $join->on('a.cash_flow_group_id', 'ag.id')
                        ->where('a.id', $request->MY_PARENT_AGENT);
                })
                ->where('acf.cash_flow_id', 18)
                ->select('acf.*')
                ->first();

            if (is_object($AgentCashFlow)) {
                $info = json_decode($AgentCashFlow->info);
                $url = $info->api_url . '&code=' . $info->referral_code . '&uid=' . $request->TOKEN;

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $url
                ]);
            } else {
                return response()->json([
                    'code' => 2002,
                    'message' => __('error_message.2002'),
                ]);
            }
        } catch (\Illuminate\Database\QueryException $e) {
            $info = json_encode($e->errorInfo);
            Log::error($info);
            DB::rollback();
        } catch (\Exception $e) {
            Log::error($e->getMessage());
            DB::rollback();
        }
    }

//    private function getMemberAccount($member_id)
//    {
//        $account = MemberBankAccount::where('member_id', $member_id)
//            ->join('banks as b', 'b.id', 'member_bank_accounts.bank_id')
//            ->select(
//                'b.name as bank_name',
//                'b.code as bank_code',
//                'member_bank_accounts.account_name as account_name',
//                'member_bank_accounts.account_no as account_no'
//            )
//            ->first();
//        return $account;
//    }

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

    private function addWithdrawRedis($agent_cash_flow_group_id, $order_id)
    {
        $redis = new REDIS();
        $key = array(
            'notice',
            'withdrawal',
            $agent_cash_flow_group_id
        );
        //如果沒有資料,就查DB做寫入
        if (!$redis->exists($key)) {
            $model = WithdrawalOrder::where('agent_group_id', $agent_cash_flow_group_id)
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
                'amount' => $data->amount++,
                'last_order_id' => $order_id
            );
            $redis->setCommon($key, $data);
        }
    }

    private function getWithdrawalAmountLimit($member_id)
    {
        $result = array();
        $agent_cash_flow = DB::table('agent_cash_flows as acf')
            ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
            ->join('agents as a', 'a.cash_flow_group_id', 'ag.id')
            ->join('members as m', function ($join) use ($member_id) {
                $join->on('m.parent', 'a.id')
                    ->where('m.id', $member_id);
            })
            ->where('cash_flow_id', 6)
            ->first();

        if (is_object($agent_cash_flow)) {
            $result = json_decode($agent_cash_flow->info, true);
        } else {
            $result['MinAmount'] = 0;
            $result['MaxAmount'] = 0;
        }

        return $result;
    }

    private function messages()
    {
        return [
            'account_no.required' => __('error_message.2038'),
            'account_no.num' => __('error_message.2039'),
        ];
    }
}
