<?php

namespace App\Http\Controllers;

use App\Helpers\Common;
use App\Helpers\SQLHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Services\TelegramService;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberSetting;
use App\Models\MemberCreditCard;
use App\Models\MemberValidation;
use App\Models\TransactionLog;
use App\Models\TransactionLogAgent;
use App\Models\FlowLimit;
use App\Models\AgentCustomizedSetting;
use App\Enums\AgentCustomizedSettingKeyEnum;
use App\Enums\AgentPersonalSettingKeyEnum;
use App\REDIS;
use Hash;
use Validator;
use Mail;
use DB;

class ThirdPartyController extends Controller
{
    protected $tgService;
    protected $transactionDebugLogs;
    public function __construct()
    {
        $this->tgService = new TelegramService();
    }

    /**
     * getInfo
     * -會員資訊
     *
     * @bodyParam username string required 會員帳號 Example: 1
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *     "nickname": "暱稱，EX:TEST",
     *     "mobile": "手機號碼，EX:601853183",
     *     "image": "個人頭像，EX:https:xxx.xxxx.xxx/xxx.jpg",
     *     "balance": "餘額，EX:109.00",
     *     "device_id": "推播設備號，EX:123456",
     *     "event_mode": "是否在鎖分模式中0:否、1:是，EX:1",
     *     "transaction_all": "是否為單一錢包模式0:否、1:是，EX:1",
     *     "red_envelopes_tax": "紅包稅收%數，EX:0.02",
     *     "payment_password_visible": "支付密碼，EX:1",
     *     "flow_limit_visible": "稽核流水開關; 0:否,1:是 EX:1",
     *     "flow_limit": "當前未達稽核流水額度; 是 EX:1000",
     *   }
     * }
     */
    public function info(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'username' => 'required|between:4,30'
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

            // 取會員資料
            $member = DB::table('members as m')
                ->join('agents as a', 'a.id', 'm.parent')
                ->where('m.username', $request->username)
                ->select(
                    'm.id',
                    'm.nickname',
                    'm.mobile',
                    'm.image',
                    'm.balance',
                    'm.device_id',
                    DB::raw('CASE WHEN m.event_mode = 0 THEN 0 ELSE 1 END AS event_mode'),
                    'a.transaction_all',
                    'a.customized_setting_group_id',
                    DB::raw('0 as red_envelopes_tax'),
                    DB::raw('0 as payment_password_visible'),
                    DB::raw('0 as flow_limit_visible'),
                    DB::raw('0 as flow_limit'),
                    DB::raw('0 as has_login_event_reward')
                )
                ->first();
            if (is_object($member)) {
                if ($member->transaction_all == 1 && false) { //2020.6.18 不需要將用戶餘額轉回平台 by rory
                    //如果是單一錢包查詢餘額，需要先將餘額轉至平台
                    $balance = app('App\Http\Controllers\VendorController')
                        ->getSingleWalletBalanceNew($member->id);
                    if (is_array($balance) && $balance['code'] == 1000) {
                        $member->balance = $balance['data'];
                    }
                }

                $settings = DB::table('agent_customized_settings')
                    ->where('agent_group_id', $member->customized_setting_group_id)
                    ->whereIn(
                        'setting_key',
                        [
                            AgentCustomizedSettingKeyEnum::FINANCIAL_RED_ENVELOPE_TAX,
                            AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE,
                            AgentCustomizedSettingKeyEnum::MEMBER_SEND_RED_ENVELOPE_BUTTON_VISIBLE,
                            AgentCustomizedSettingKeyEnum::MEMBER_RECEIVE_RED_ENVELOPE_BUTTON_VISIBLE,
                            AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE
                        ]
                    )
                    ->select(
                        'agent_group_id',
                        'setting_key',
                        'value'
                    )
                    ->get();
                foreach ($settings as $setting) {
                    switch ($setting->setting_key) {
                        case AgentCustomizedSettingKeyEnum::FINANCIAL_RED_ENVELOPE_TAX:
                            $member->red_envelopes_tax = $setting->value * 1;
                            break;
                        case AgentCustomizedSettingKeyEnum::PAYMENT_PASSWORD_BUTTON_VISIBLE:
                            $member->payment_password_visible = $setting->value * 1;
                            break;
                        case AgentCustomizedSettingKeyEnum::MEMBER_SEND_RED_ENVELOPE_BUTTON_VISIBLE:
                            $member->member_send_red_envelope_visible = $setting->value * 1;
                            break;
                        case AgentCustomizedSettingKeyEnum::MEMBER_RECEIVE_RED_ENVELOPE_BUTTON_VISIBLE:
                            $member->member_receive_red_envelope_visible = $setting->value * 1;
                            break;
                        case AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE:
                            $member->flow_limit_visible = $setting->value * 1;
                            break;
                    }
                }

                //查玩家稽核流水
                if ($member->flow_limit_visible == 1) {
                    $flow_limit = FlowLimit::where('member_id', $member->id)
                        ->where('status', 0)
                        ->groupBy('member_id')
                        ->select(
                            DB::raw('SUM(flow_limit) AS total_flow_limit'),
                            DB::raw('SUM(reach) AS total_reach')
                        )
                        ->first();
                    if (is_object($flow_limit)) {
                        $member->flow_limit = ($flow_limit->total_flow_limit - $flow_limit->total_reach);
                    }
                }

                //issue 1136: 充值獎勵未領取前，洗分與發紅包後應該要被取消資格
                $param_count = '@o_total_count';
                DB::select('CALL SP_check_login_event(?, ' . $param_count . ')', array($request->ID));
                $output = DB::select(DB::raw('select ' . $param_count . ' as count'));
                $count = (is_null($output[0]->count)) ? 0 :$output[0]->count;
                $member->has_login_event_reward = ($count > 0) ? 1 : 0;

                return response()->json([
                    'code' => 1000,
                    'message' => __('error_message.1000'),
                    'data' => $member
                ]);
            } else {
                // 會員號不存在
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     *
     * checkPassword
     * - 確認密碼
     *
     * @bodyParam username string required 會員帳號 Example: 1
     * @bodyParam password string required 會員密碼 Example: 1
     * @bodyParam type numeric required 密碼類型 Example: 0
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS"
     * }
     */
    public function check_password(Request $request)
    {
        try {
            // 驗證POST資料
            $rules = array(
                'username' => 'required|between:4,30',
                'password' => 'required|between:6,12',
                'password_type' => 'required|numeric'
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
            // 查詢帳號是否存在
            $member = Member::where('username', $request->username)->first();

            if (is_object($member)) {
                // 確認密碼是否正確
                if ($request->password_type == 1) {
                    //支付密碼
                    if (Hash::check($request->password, $member->payment_password)) {
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000')
                        ]);
                    }
                } else {
                    //登入密碼
                    if (Hash::check($request->password, $member->password)) {
                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000')
                        ]);
                    }
                }
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007')
                ]);
            } else {
                // 帳號不存在
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     *
     * transaction
     * -轉帳
     *
     * @bodyParam agent_username string required 代理帳號 Example: 1
     * @bodyParam username string required 會員帳號 Example: 1
     * @bodyParam platform integer 類型(預設1:平台)1:平台，2:遊戲商 Example: 1
     * @bodyParam amount integer required 金額 Example: 100
     * @bodyParam tax decimal required 稅收 Example: 0.02
     * @bodyParam audit_amount decimal required 稽核流水 Example: 0.02
     * @bodyParam transaction_type integer required 轉帳記錄類型4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包取消(用戶手動),9:紅包退還(系統自動),10:開分(贈點),11:拒絕洗分,12:退還洗分 Example: 4
     * @bodyParam remark string  備註 Example: hello
     * @bodyParam role_id integer 角色 Example: 1
     * @bodyParam operator string  操作者 Example: cs7
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function transaction(Request $request)
    {
        try {
            $timeout = 20; //seconds
            $time_start = microtime(true);
            $this->transactionDebugLogs = [];
            $this->transactionDebug(
                sprintf('[%s] transfer amount [%d] from [%s] begin', $request->username, $request->amount, $request->agent_username),
                $time_start
            );
            // 驗證POST資料
            $rules = array(
                'username' => 'required|between:4,30',
                'platform' => 'in:1,2',
                'amount' => 'required|numeric',
                'transaction_type' => 'required|in:4,5,6,7,8,9,10,11,12,14',
                'audit_amount' => 'numeric',
                'remark' => 'between:0,50',
                'operator' => 'between:0,30'
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

            //應該依轉帳類型決定是轉入還轉出
            switch ($request->transaction_type) {
                case 4: //開分
                case 7: //領紅包
                case 8: //紅包取消(用戶手動)
                case 9: //紅包退還(系統自動)
                case 10: //開分(贈點)
                case 11: //拒絕洗分
                case 12: //退還洗分
                case 14: //補點
                    $type = 1;
                    break;
                case 5: //洗分
                case 6: //發紅包
                    $type = 2;
                    break;
            }
            $platform = isset($request->platform) ? $request->platform : 1;
            $audit_amount = isset($request->audit_amount) ? $request->audit_amount : 0;
            DB::beginTransaction();
            // 取會員資料
            $member = Member::lockForUpdate()
                ->where('username', $request->username)
                ->select(
                    'id',
                    'parent',
                    'balance',
                    DB::raw('CASE WHEN event_mode = 0 THEN 0 ELSE 1 END AS event_mode')
                )
                ->first();
            $this->transactionDebug('get member', $time_start);
            $before_balance = $member->balance;
            // 取代理資料
            $agent = Agent::where('username', $request->agent_username)
                ->select('id', 'transaction_all', 'username')
                ->first();
            $self_agent = Agent::where('id', $member->parent)
                ->select('id', 'transaction_all')
                ->first();

            $tax = isset($request->tax) ? $request->tax : 0;
            if (is_object($member) && is_object($agent) && is_object($self_agent)) {
                if ($type == 1) {
                    // 轉入
                    $result = $member->increment('balance', ($request->amount + $tax));
                    if ($result) {
                        // 重新取最後的餘額
                        $member = Member::where('username', $request->username)
                            ->select('id', 'balance', 'username')
                            ->first();
                        //寫入transation_log
                        $log = array();
                        $log['agent_id'] = $agent->id;
                        $log['member_id'] = $member->id;
                        $log['type'] = $request->transaction_type;
                        $log['point'] = $request->amount;
                        $log['tax'] = $tax;
                        $log['before_balance'] = $before_balance;
                        $log['balance'] = $member->balance;
                        $log['status'] = 1000;
                        $log['remark'] = $request->remark;
                        if ($request->transaction_type == 14) {
                            $log['result'] = 'CS補點';
                        }
                        $log['operator'] = $request->operator;
                        $log['admin'] = $member->id;
                        $transaction_log = app('App\Http\Controllers\TransactionLogController')
                            ->insertLog($log);

                        //補點發TG通知
                        if ($request->transaction_type == 14) {
                            $message = "[營運商CS後台手動補點]" . PHP_EOL .
                                        "代理:" . $agent->username . PHP_EOL .
                                        "玩家:" . $member->username . PHP_EOL .
                                        "點數:" . $request->amount . "點" . PHP_EOL .
                                        "備註:" . $request->remark . PHP_EOL .
                                        "操作者:" . $request->operator;
                            //發TG告知有補帳
                            $this->tgService->sendMessage($message, 'info');
                        }

                        // 開分 / 領紅包 / 開分(贈點) / 補點
                        if ($request->transaction_type == 4
                            || $request->transaction_type == 7
                            || $request->transaction_type == 10
                            || $request->transaction_type == 14) {
                            //稽核流水
                            $flow_limit = array();
                            $flow_limit['member_id'] = $member->id;
                            $flow_limit['type'] = $request->transaction_type;
                            $flow_limit['event_id'] = 0;
                            $flow_limit['reference_id'] = isset($transaction_log) ? $transaction_log->id : null ;
                            $flow_limit['flow_limit'] = ($tax > 0)
                                ? ($request->amount + $tax)
                                : $request->audit_amount;
                            $flow_limit['admin'] = 0;
                            FlowLimit::create($flow_limit);
                        }
                        $secs = $this->transactionDebug('before commit', $time_start);
                        if($secs > $timeout) {
                            DB::rollBack();
                            throw new \Exception("This transaction execute has been timeout({$timeout} seconds).");
                        }

                        //開分(4)充值獎勵
                        if ($request->transaction_type == 4 && isset($request->role_id)) {
                            DB::update("CALL SP_event_deposit_for_cs(" . $transaction_log->id . ", " . $request->role_id . ")");
                        }
                        DB::commit();

                        //刪除REDIS中的舊資料
                        $redis = new REDIS();
                        $redis->delete(['member']);
                        /**
                         * 單一錢包才可選擇開分到遊戲內
                         * 如果選擇開分到遊戲內，需幫他將點數轉入遊戲商
                         * (只有開分跟開分贈點可以選擇，其他一律轉平台)
                         */
                        if ($self_agent->transaction_all == 1
                            && $platform == 2
                            && ($request->transaction_type == 4 || $request->transaction_type == 10)) {
                            //取最後一次轉入的遊戲商
                            $last_vendor = app('App\Http\Controllers\VendorController')
                                ->lastTransactionVendor($member->id);
                            //沒有最後一次轉入的遊戲商，直接用平台金額就行
                            if (is_array($last_vendor) && $last_vendor['vendor_id'] != 0) {
                                //開分的金額+平台餘額，轉入最後一次轉入的遊戲商
                                $transaction_result = app('App\Http\Controllers\VendorController')
                                    ->vendorTransfer($last_vendor['vendor_id'], $member->id, $member->balance, 1);
                                if (!is_array($transaction_result) || $transaction_result['code'] != 1000) {
                                    //轉入失敗, 錢在玩家的平台錢包裡, 要返回成功
                                    return response()->json([
                                        'code' => 1000,
                                        'message' => isset($transaction_result['message'])
                                            ? $transaction_result['message']
                                            : __('error_message.9996'),
                                        'balance' => $member->balance
                                    ]);
                                } else {
                                    return response()->json([
                                        'code' => 1000,
                                        'message' => __('error_message.1000'),
                                        'data' => $transaction_result['data']['vendor_balance']
                                    ]);
                                }
                            }
                        }

                        return response()->json([
                            'code' => 1000,
                            'message' => __('error_message.1000'),
                            'data' => $member->balance
                        ]);
                    } else {
                        // 資料庫操作失敗
                        return response()->json([
                            'code' => 2001,
                            'message' => __('error_message.2001')
                        ]);
                    }
                } elseif ($type == 2) {
                    // 轉出
                    /**
                                        * 單一錢包轉出前要將遊戲餘額轉回平台
                                        */
                    if ($self_agent->transaction_all == 1 && false) { // 怒取消三方轉回平台的步驟
                        //取最後一次轉入的遊戲商
                        $last_vendor = app('App\Http\Controllers\VendorController')->lastTransactionVendor($member->id);
                        //沒有轉點記錄或最後一筆是轉出遊戲商，直接用平台餘額就行
                        if (is_array($last_vendor) && $last_vendor['vendor_id'] != 0 && $last_vendor['type'] == 1) {
                            //查詢最後一間遊戲商的餘額將餘額轉回平台
                            $vendor_balance = app('App\Http\Controllers\VendorController')->getBalanceByVendorId($member->id, $last_vendor['vendor_id']);
                            if (is_array($vendor_balance) && $vendor_balance['code'] == 1000) {
                                //將餘額轉回平台
                                $transaction_result = app('App\Http\Controllers\VendorController')
                                    ->vendorTransfer($last_vendor['vendor_id'], $member->id, $vendor_balance['data'], 2);
                                if (is_array($transaction_result) && $transaction_result['code'] == 1000) {
                                    //轉入成功，重新取最後餘額
                                    $member = Member::lockForUpdate()->where('username', $request->username)->select('id', 'balance')->first();
                                } else {
                                    //轉入失敗只能看平台餘額是否足夠(通常餘額會在最後進入的遊戲中)
                                }
                            } else {
                                //餘額查詢失敗,只能看平台餘額是否足夠
                            }
                        }
                    }

                    if ($member->balance < ($request->amount + $tax)) {
                        // 餘額小於轉出金額，餘額不足
                        return response()->json([
                            'code' => 1022,
                            'message' => __('error_message.1022')
                        ]);
                    } else {
                        // 轉出
                        $this->transactionDebug('member balance ['.$member->balance.']', $time_start);
                        $result = $member->decrement('balance', ($request->amount + $tax));
                        $this->transactionDebug('member decrement, balance ['.$member->balance.']', $time_start);
                        if ($result) {
                            // 重新取最後的餘額
                            $member = Member::lockForUpdate()
                                ->where('username', $request->username)
                                ->select('id', 'username', 'parent', 'balance')
                                ->first();
                            $this->transactionDebug('get member again, balance ['.$member->balance.']', $time_start);
                            if ($member->balance < 0) {
                                // 轉出後如果小於0，加回扣除的金額
                                $result = Member::where('username', $request->username)
                                    ->increment('balance', ($request->amount + $tax));
                                // 餘額小於轉出金額
                                return response()->json([
                                    'code' => 1022,
                                    'message' => __('error_message.1022')
                                ]);
                            } else {
                                //寫入transation_log
                                $log = array();
                                $log['agent_id'] = $agent->id;
                                $log['member_id'] = $member->id;
                                $log['type'] = $request->transaction_type;
                                $log['point'] = $request->amount;
                                $log['tax'] = $tax;
                                $log['before_balance'] = $before_balance;
                                $log['balance'] = $member->balance;
                                $log['status'] = 1000;
                                $log['remark'] = $request->remark;
                                $log['operator'] = $request->operator;
                                $log['admin'] = $member->id;
                                $transaction_log = app('App\Http\Controllers\TransactionLogController')
                                    ->insertLog($log);
                                $secs = $this->transactionDebug('before commit', $time_start);
                                if($secs > $timeout) {
                                    DB::rollBack();
                                    throw new \Exception("This transaction execute has been timeout({$timeout} seconds).");
                                }

                                //issue 1136: 取消充值獎勵資格
                                DB::update("CALL SP_cancel_login_event(" . $member->id . ")");

                                DB::commit();
                                $agent_setting = $this->getAgentAdvanceSetting($member->parent);
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
                                        ->where('m.id', $member->id)
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

                                //刪除REDIS中的舊資料
                                $redis = new REDIS();
                                $redis->delete(['member']);
                                // 成功
                                return response()->json([
                                    'code' => 1000,
                                    'message' => __('error_message.1000'),
                                    'data' => $member->balance
                                ]);
                            }
                        } else {
                            // 資料庫操作失敗
                            return response()->json([
                                'code' => 2001,
                                'message' => __('error_message.2001')
                            ]);
                        }
                    }
                }
            } else {
                // 會員號不存在
                return response()->json([
                    'code' => 1007,
                    'message' => __('error_message.1007')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            $this->tgService->sendMessage(
                json_encode([
                    'message' => $exp->getMessage(),
                    'debug_logs' => $this->transactionDebugLogs
                ]),
            );
            \Log::error($exp->getMessage(), $this->transactionDebugLogs);
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     *
     * agentTransaction
     * -代理之間轉帳
     *
     * @bodyParam from string required 發送者帳號 Example: 1
     * @bodyParam to string required 接收者帳號 Example: 1
     * @bodyParam type integer required 類型1:開分,2:洗分 Example: 100
     * @bodyParam amount  required 金額 Example: 100
     * @bodyParam from_limit_of_credit required 發送者剩餘額度 Example: 100
     * @bodyParam from_used_credit required 發送者已使用額度 Example: 100
     * @bodyParam to_limit_of_credit required 接收者剩餘額度 Example: 100
     * @bodyParam to_used_credit required 接收者已使用額度 Example: 100
     * @bodyParam remark string 備註 Example: hello
     * @bodyParam operator string 操作者 Example: cs7
     * @bodyParam status boolean 狀態0:失敗，1:成功 Example: 0
     * @response {
     *  "code": 1000,
     *  "message": "Success"
     * }
     */
    public function agentTransaction(Request $request)
    {
        try {
            // 驗證POST資料
            // $rules = array(
            //     'username' => 'required|between:4,30',
            //     'type' => 'required|in:1,2',
            //     'amount' => 'required|digits_between:0,10',
            //     'transaction_type' => 'required|in:4,5,6,7,8,9,10,11,12',
            //     'remark' => 'between:0,50',
            //     'operator' => 'between:0,30',
            // );
            // $validator = Validator::make($request->all(), $rules, $this->messages());
            // if (!$validator->passes()) {
            //     return response()->json([
            //         'code' => 2002,
            //         'message' => __('error_message.2002'),
            //         'data' => $validator->errors()
            //             ->all()
            //     ]);
            // }
            // 取發送者代理資料
            $from_agent = Agent::where('username', $request->from)->select('id')->first();
            // 取接收者代理資料
            $to_agent = Agent::where('username', $request->to)->select('id')->first();
            // 代理不存在
            if (is_object($from_agent) && is_object($to_agent)) {
                //寫入transation_log
                $log = array();
                $log['from_agent_id'] = $from_agent->id;
                $log['to_agent_id'] = $to_agent->id;
                $log['type'] = $request->type;
                $log['amount'] = $request->amount;
                $log['from_limit_of_credit'] = $request->from_limit_of_credit;
                $log['from_used_credit'] = $request->from_used_credit;
                $log['to_limit_of_credit'] = $request->to_limit_of_credit;
                $log['to_used_credit'] = $request->to_used_credit;
                $log['status'] =  $request->status;
                $log['remark'] = $request->remark;
                $log['operator'] = $request->operator;
                if (TransactionLogAgent::create($log)) {
                    return response()->json([
                        'code' => 1000,
                        'message' => __('error_message.1000'),
                    ]);
                } else {
                    // 資料庫操作失敗
                    return response()->json([
                        'code' => 2001,
                        'message' => __('error_message.2001')
                    ]);
                }
            } else {
                // 代理不存在
                return response()->json([
                    'code' => 1005,
                    'message' => __('error_message.1005')
                ]);
            }
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * checkFlowLimit
     * - 當前稽核狀態
     *
     * @bodyParam username string required 會員帳號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *     "reach": "目前流水,ex:100.00",
     *     "flow_limit": "需要流水,ex:100.00",
     *     "flow_limit_visable": "稽核開關, ex:1"
     *   }
     * }
     */
    public function checkFlowLimit(Request $request)
    {
        try {
            $data = DB::table('members as m')
                ->join('agents as a', 'a.id', 'm.parent')
                ->leftJoin('agent_customized_settings as s', function ($join) {
                    $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                        ->where('s.setting_key', AgentCustomizedSettingKeyEnum::FLOW_LIMIT_VISIBLE);
                })
                ->leftJoin('flow_limits as f', function ($join) {
                    $join->on('m.id', 'f.member_id')
                        ->where('f.status', 0);
                })
                ->where('m.username', $request->username)
                ->select(
                    DB::raw('IFNULL(SUM(f.flow_limit), 0) AS flow_limit'),
                    DB::raw('IFNULL(SUM(f.reach), 0) AS reach'),
                    DB::raw('IFNULL(s.value, 0) AS flow_limit_visable')
                )
                ->first();

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    /**
     * csSetting
     * - CS後台設定
     *
     * @bodyParam from string required 代理帳號 Example: 1
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": {
     *     "setting_id": "鍵值ID,ex:100.00",
     *     "value": "鍵值內容,ex:0"
     *   }
     * }
     */
    public function csSetting(Request $request)
    {
        try {
            $agent = Agent::where('username', $request->from)->first();

            $setting = DB::table('agent_setting_defines as d')
                ->leftJoin('agent_customized_settings as s', function ($join) use ($agent) {
                    $join->on('s.setting_key', 'd.setting_id')
                        ->where('s.agent_group_id', $agent->customized_setting_group_id);
                })
                ->leftJoin('agent_personal_settings as p', function ($join) use ($agent) {
                    $join->on('p.setting_id', 'd.setting_id')
                        ->where('p.agent_id', $agent->id);
                })
                ->whereIn(
                    'd.parent',
                    [
                        AgentCustomizedSettingKeyEnum::CS_BACKEND_SETTING,
                        AgentPersonalSettingKeyEnum::PERSONAL_OTHER,
                    ]
                )
                ->select(
                    'd.setting_id',
                    DB::raw('CASE d.type WHEN 2 THEN IFNULL(p.value, d.default_value) WHEN 3 THEN IFNULL(s.value, d.default_value) END AS value')
                )
                ->get();
            
            $data = array();
            if (is_object($setting) > 0) {
                foreach ($setting as $obj) {
                    $data[$obj->setting_id] = $obj->value;
                }
            }

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[1]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    public function getBankList(Request $request)
    {
        try {
            //取得代理
            $agent = Agent::find($request->AGENT_ID);
            //取得銀行列表
            $data = DB::table('banks as b')
                ->leftjoin('bank_names as bn', function ($join) use ($request) {
                    $join->on('bn.bank_id', 'b.id')
                        ->where('bn.language_id', $request->lang);
                })
                ->where('b.agent_group_id', $agent->cash_flow_group_id)
                ->where('b.status', 1)
                ->select(
                    'b.id as id',
                    DB::raw("(CASE WHEN bn.name is NULL THEN b.name ELSE bn.name END) as name"),
                    'b.code as code'
                )
                ->get();
            
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $data
            ]);
        } catch (\Illuminate\Database\QueryException $exp) {
            $info = $exp->errorInfo;
            return response()->json([
                'code' => $info[0],
                'message' => $info[1]
            ]);
        } catch (\Exception $exp) {
            return response()->json([
                'code' => $exp->getCode(),
                'message' => $exp->getMessage()
            ]);
        }
    }

    // return resource usage time
    private function transactionDebug($title, $time_start) {
        $now = microtime(true);
        $execution_time = $now - $time_start;
        $execution_time = number_format($execution_time, 2);
        $this->transactionDebugLogs[] = "{$title} $execution_time seconds";
        return $execution_time;
    }

    private function messages()
    {
        return [
            'username.required' => '請輸入會員帳號',
            'username.between' => '會員帳號請輸入長度不超過:max的文字',

            'agent_username.required' => '請輸入代理帳號',
            'agent_username.between' => '代理帳號請輸入長度不超過:max的文字',

            'password.required' => '請輸入密碼',
            'password.between' => '密碼請輸入:min-:max位數',

            'type.required' => '如要轉入請輸入1，如要轉出請輸入2',
            'type.in' => '如要轉入請輸入1，如要轉出請輸入2',

            'amount.required' => '金額請輸入正整數',
            'amount.digits_between' => '金額請輸入正整數',

            'transaction_type.required' => '轉帳類型4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包退回',
            'transaction_type.in' => '轉帳類型4:開分,5:洗分,6:發紅包,7:領紅包,8:紅包退回',
        ];
    }
}
