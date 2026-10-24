<?php

namespace target\support\Services;

use target\support\Enums\TransactionEnum;
use target\support\Enums\WalletEnum;
use target\support\Models\MemberWallet;
use target\support\Models\WalletTransactionLog;
use target\support\Enums\AgentCustomizedSettingKeyEnum;
use target\support\Enums\TransactionLogAgentTypeEnum;
use target\support\Models\Agent;
use target\support\Models\FlowLimitRestriction;
use target\support\Models\FlowLimit;
use target\support\Models\VipFlowLimit;
use target\support\Models\Member;
use target\support\Models\Order;
use target\support\Models\TransactionLog;
use target\support\Models\TransactionLogAgent;
use Exception;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CashFlowService
{
    public function getOrderCreateRules()
    {
        return [
            'order_type_id' => 'required|digits_between:0,10'
        ];
    }

    public function getOrderTypes(int $member_id, int $language)
    {
        return DB::table('members as m')
            ->join('agents as a', 'm.parent', 'a.id')
            ->join('agent_cash_flows as acf', function ($join) {
                $join->on('a.cash_flow_group_id', 'acf.agent_group_id')
                    ->where('acf.status', 1);
            })
            ->join('agent_cash_flow_order_types as acfot', function ($join) {
                $join->on('acf.id', 'acfot.agent_cash_flow_id')
                    ->where('acfot.status', 1);
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('acf.cash_flow_id', 'cf.id')
                    ->where('cf.status', 1)
                    ->where('cf.type', 1);
            })
            ->leftJoin('order_types as ot', 'acfot.order_type_id', 'ot.id')
            ->leftJoin('order_type_names as otn', function ($join) use ($language) {
                $join->on('otn.type_id', 'ot.id')
                    ->where('otn.language_id', $language);
            })
            ->where('m.id', $member_id)
            ->orderBy('acfot.sorts', 'desc')
            ->select(
                'ot.id as order_type_id',
                DB::raw('(CASE WHEN otn.name is NULL THEN ot.name ELSE otn.name END) as name'),
                'acfot.min_amount',
                'acfot.max_amount',
                'acfot.available_amount',
                'acf.rate'
            )
            ->distinct()
            ->get();
    }

    public function getOrderType(int $member_id, int $order_type_id)
    {
        return DB::table('members as m')
            ->join('agents as a', 'm.parent', 'a.id')
            ->join('agent_cash_flows as acf', function ($join) {
                $join->on('a.cash_flow_group_id', 'acf.agent_group_id')
                    ->where('acf.status', 1);
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('acf.cash_flow_id', 'cf.id')
                    ->where('cf.status', 1);
            })
            ->join('agent_cash_flow_order_types as acfot', 'acfot.agent_cash_flow_id', 'acf.id')
            ->join('order_types as ot', function ($join) use ($order_type_id) {
                $join->on('ot.id', 'acfot.order_type_id')
                    ->where('ot.id', $order_type_id)
                    ->where('ot.status', 1);
            })
            ->where('m.id', $member_id)
            ->select(
                'ot.id',
                'ot.name',
                'ot.key',
                'acf.id as agent_cash_flow_id',
                'acf.rate',
                'cf.id as cash_flow_id'
            )
            ->first();
    }
    /**
     * 調用三方app, type: 0 失敗, 1 轉址, 2 QRCode
     * @param  mixed $agentCashFlow
     * @param  mixed $order
     * @return Order [
     *   "type": 1,
     *   "url": "位址",
     * ]
     */
    public function createOrder(
        int $agent_cash_flow_id,
        int $order_type_id,
        int $currency_id,
        int $member_id,
        string $amount,
        string $point,
        string $callback,
        string $ip_address,
        float $rate = 1,
        array $extra_content = null,
        string $tax = '0.0000',
        int $agent_id = 0
    ) {
        $data = [
            'agent_cash_flow_id' => $agent_cash_flow_id,
            'order_type_id' => $order_type_id,
            'currency_id' => $currency_id,
            'member_id' => $member_id,
            'agent_id' => $agent_id,
            'amount' => $amount,
            'rate' => $rate,
            'tax' => $tax,
            'point' => $point,
            'balance' => 0,
            'callback' => $callback,
            'status' => 0,
            'ip' => $ip_address
        ];
        if ($extra_content != null) {
            $data['extra_content'] = json_encode($extra_content);
        }
        $try = 0;
        do {
            $data['order_no'] = $this->getOrderNo();
            $model = Order::create($data);
            $try++;
        } while (!$model && $try < 3); // 最多嘗試三次創建訂單

        return $model;
    }

    /**
     * 更新用戶分數
     * @param  Order $order
     * @throws
     */
    public function updateMemberBalance(Order $order)
    {
        DB::beginTransaction();
        try {
            Log::info("UpdateMemberBalance 136 begin " . $order->id);
            
            // 檢查是否為會員的訂單, 避免呼叫到代理的訂單
            if ($order->member_id == 0) {
                throw new Exception('order member_id is 0.', 9999);
            }

            $type = TransactionEnum::TYPE_ORDER_DEPOSIT;  //訂單儲值
            $setting_key = 6011;    //充值流水倍數
            $multiple_wallet_setting_key = AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH; // 多錢包開關
            $vip_setting_key = 3533;    //VIP充值流水倍數
            $transaction_log_id = $wallet_transaction_log_id = 0;
            $checkOrder = DB::table('orders')
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();
            Log::info("UpdateMemberBalance 143 after lock " . $order->id);
            if ($checkOrder->status != 1) {
                throw new Exception('order status does not equal 1.', 9999);
            }
            $member = Member::lockForUpdate()->find($order->member_id);
            $is_multiple_wallet = $this->checkMemberSetting($member->parent, $multiple_wallet_setting_key);

            // 取代理資料
            $agent = DB::table('agents as a')
                ->leftJoin('agent_customized_settings as s', function ($join) use ($setting_key) {
                    $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                        ->where('s.setting_key', $setting_key);
                })
                ->where('a.id', $member->parent)
                ->select(
                    'a.id',
                    DB::raw('IFNULL(s.value, 0) AS deposit_flow_limit_rate')
                )
                ->first();
            Log::info("UpdateMemberBalance 172 " . $order->id);

            if ($is_multiple_wallet) {
                $member_wallet = DB::table('member_wallets')
                    ->where('wallet_type_id', WalletEnum::CODE_GAME)
                    ->where('member_id', $member->id)
                    ->first();
                if (!is_object($member_wallet)) {
                    // 如果沒有就建一個
                    $create_data = array(
                        'member_id' => $member->id,
                        'wallet_type_id' => WalletEnum::CODE_GAME,
                        'balance' => 0,
                        'first_deposit' => null,
                        'status' => 1,
                        'admin' => 0
                    );
                    $member_wallet = MemberWallet::create($create_data);
                } elseif ($member_wallet->status != 1) {
                    // 如果status不是正常狀態，直接回傳帳號鎖定中
                    throw new Exception(__('error_message.90062'), 90062);
                }
                $member_wallet_id = $member_wallet->id;
                $member_wallet = MemberWallet::lockForUpdate()->find($member_wallet_id);

                $before_balance = $member_wallet->balance;
                $balance = ($member_wallet->balance + $order->point);

                $member_wallet->update(['balance' => $balance]);

                Log::info("UpdateMemberBalance 205 after member wallet update " . $order->id);
                $wallet_log = array();
                $wallet_log['transaction_no'] = $this->getTransactionNo(2);
                $wallet_log['wallet_type_id'] = WalletEnum::CODE_GAME;
                $wallet_log['agent_id'] = $member->parent;
                $wallet_log['member_id'] = $order->member_id;
                $wallet_log['order_id'] = $order->id;
                $wallet_log['point'] = $order->point;
                $wallet_log['before_balance'] = $before_balance;
                $wallet_log['balance'] = $balance;
                $wallet_log['type'] = TransactionEnum::TYPE_GAME_WALLET_ORDER_DEPOSIT;
                $wallet_log['status'] = TransactionEnum::STATUS_SUCCESS;
                $wallet_transaction_log = WalletTransactionLog::create($wallet_log);
                $wallet_transaction_log_id = $wallet_transaction_log->id;
                Log::info("UpdateMemberBalance 219 after wallet transaction log " . $order->id);
            } else {
                $before_balance = $member->balance;
                $balance = ($member->balance + $order->point);

                $member->update(['balance' => $balance]);
                Log::info("UpdateMemberBalance 224 after member update " . $order->id);
                $log = array();
                $log['transaction_no'] = $this->getTransactionNo();
                $log['agent_id'] = $member->parent;
                $log['member_id'] = $order->member_id;
                $log['order_id'] = $order->id;
                $log['point'] = $order->point;
                $log['before_balance'] = $before_balance;
                $log['balance'] = $balance;
                $log['type'] = $type;
                $log['status'] = 1000;
                $transaction_log = TransactionLog::create($log);
                $transaction_log_id = $transaction_log->id;
                Log::info("UpdateMemberBalance 238 after transaction log " . $order->id);
            }

            $order->update([
                'status' => 3,
                'balance' => $balance,
                'pay_time' => date("Y-m-d H:i:s")
            ]);
            Log::info("UpdateMemberBalance 246 after order update " . $order->id);
            //增加稽核流水log
            if (floatval($agent->deposit_flow_limit_rate) > 0) {
                $flow = array();
                $flow['member_id'] = $order->member_id;
                $flow['type'] = $type;
                $flow['reference_id'] = $transaction_log_id;
                if ($is_multiple_wallet) {
                    $flow['wallet_transaction_log_id'] = $wallet_transaction_log_id;
                }
                $flow['flow_limit'] = strval($order->point * floatval($agent->deposit_flow_limit_rate));
                $flow['admin'] = 0;
                $flowLimit = FlowLimit::create($flow);
                // @TODO 先寫死只限老虎機, 之後再來調整
                // @TODO 先關閉, 之後再來寫 by mars
                //FlowLimitRestriction::create([
                //    'flow_limit_id' => $flowLimit->id,
                //    'vendor_id' => 0,
                //    'type_id' => 2
                //]);
            }

            $has_vip = DB::table('agents as a')
                ->leftJoin('agent_customized_settings as s', function ($join) use ($vip_setting_key) {
                    $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                        ->where('s.setting_key', 3531);
                })
                ->where('a.id', $member->parent)
                ->select(
                    'a.id',
                    DB::raw('IFNULL(s.value, 0) AS value')
                )
                ->first();
            // 有開啟VIP才寫入VIP稽核流水
            if ($has_vip->value == 1) {
                $vip_flow_limit_setting = DB::table('agents as a')
                    ->leftJoin('agent_customized_settings as s', function ($join) use ($vip_setting_key) {
                        $join->on('s.agent_group_id', 'a.customized_setting_group_id')
                            ->where('s.setting_key', $vip_setting_key);
                    })
                    ->where('a.id', $member->parent)
                    ->select(
                        'a.id',
                        DB::raw('IFNULL(s.value, 0) AS multiple')
                    )
                    ->first();

                $vip_flow_limit = array();
                $vip_flow_limit['member_id'] = $order->member_id;
                $vip_flow_limit['type'] = 3; //訂單儲值
                $vip_flow_limit['deposit_amount'] = $order->point;
                $vip_flow_limit['flow_limit'] = strval($order->point * floatval($vip_flow_limit_setting->multiple));
                $vip_flow_limit['status'] = (floatval($vip_flow_limit_setting->multiple) == 0) ? 1 : 0;
                $vip_flow_limit['admin'] = 0;
                $vipFlowLimit = VipFlowLimit::create($vip_flow_limit);

                if (floatval($vip_flow_limit_setting->multiple) == 0) {
                    //如果倍數是0 直接加進充值分數
                    DB::update(
                        'call SP_calc_member_vip_deposit_amount(?, ?, ?)',
                        [$order->member_id, $order->point, $vipFlowLimit->id]
                    );
                }
            }
            Log::info("UpdateMemberBalance 305 before call SP_event_deposit" . $order->id);
            // 充值獎勵
            DB::update('call SP_event_deposit(' . $order->id . ')');
            Log::info("UpdateMemberBalance 308 after call SP_event_deposit" . $order->id);

            // 下線充值獎金
            $checkCondition = DB::table('orders')
                ->where('member_id', $member->id)
                ->where('created_at', '>=', $member->created_at)
                ->where('id', '!=', $order->id)
                ->where('status', 3)
                ->first();
            if (!is_object($checkCondition)) {
                Log::info("UpdateMemberBalance 317 before call SP_event_invite_deposit" . $order->id);
                // 如果這筆訂單是該會員的第一筆儲值訂單，則call SP_event_invite_deposit
                DB::update('call SP_event_invite_deposit(' . $order->id . ')');
                Log::info("UpdateMemberBalance 320 before call SP_event_invite_deposit" . $order->id);
            }

            Log::info("UpdateMemberBalance 343 before call SP_calc_event_progressive_jackpot_deposit" . $order->id);
            // 成長彩金計劃
            DB::update('call SP_calc_event_progressive_jackpot_deposit(' . $order->id . ')');
            Log::info("UpdateMemberBalance 346 after call SP_calc_event_progressive_jackpot_deposit" . $order->id);

            DB::commit();
        } catch (Exception $e) {
            Log::error("UpdateMemberBalance 312 " . $order->id . " error " . $e->getMessage());
            DB::rollback();
            throw $e;
        }
    }

    /**
     * 更新代理分數
     * @param  Order $order
     * @throws
     */
    public function updateAgentBalance(Order $order)
    {
        DB::beginTransaction();
        try {
            $logTitle = "UpdateAgentBalance";
            Log::info($logTitle . " begin " . $order->id);
            $type = TransactionEnum::TYPE_ORDER_DEPOSIT;  //訂單儲值
            $transaction_log_id = 0;

            // 檢查是否為代理的訂單, 避免呼叫到會員的訂單
            if ($order->member_id > 0) {
                throw new Exception('order member_id is not 0.', 9999);
            }

            $checkOrder = Order::lockForUpdate()->find($order->id);
            Log::info($logTitle . " after lock order " . $order->id);
            if ($checkOrder->status != 1) {
                throw new Exception('order status does not equal 1.', 9999);
            }
            $agent = Agent::lockForUpdate()->find($order->agent_id);

            Log::info($logTitle . " after lock agent " . $agent->id);

            $before_balance = $agent->balance;
            $balance = ($agent->balance + $order->point);

            $agent->update(['balance' => $balance]);
            Log::info($logTitle . " after update agent balance " . $agent->id . " to " . $balance);
            
            $log = [];
            $log['order_id'] = $order->id;
            $log['from_agent_id'] = $agent->id;
            $log['to_agent_id'] = $agent->id;
            $log['type'] = TransactionLogAgentTypeEnum::DEPOSIT;
            $log['amount'] = $order->point;
            $log['from_limit_of_credit'] = $balance;
            $log['from_used_credit'] = 0;
            $log['to_limit_of_credit'] = $balance;
            $log['to_used_credit'] = 0;
            $log['status'] = 1;
            $log['platform'] = 2;
            $log['operator'] =  $agent->username;
            $transactionLogAgent = TransactionLogAgent::create($log);
            $transaction_log_agent_id = $transactionLogAgent->id;
            Log::info($logTitle . " after create transaction agent log " . $transaction_log_agent_id);

            $order->update([
                'status' => 3,
                'balance' => $balance,
                'pay_time' => date("Y-m-d H:i:s")
            ]);
            Log::info($logTitle . " after order update " . $order->id);
            DB::commit();
        } catch (Exception $e) {
            Log::error($logTitle . " " . $order->id . " error " . $e->getMessage());
            DB::rollback();
            throw $e;
        }
    }
    public function getOrderNo()
    {
        $prefix = date('Ymd');
        $orderNo = $prefix
            . random_int(100000, 999999)
            . substr(sprintf("%01.4f", microtime(true)), -4);
        return $orderNo;
    }

    public function getTransactionNo($type = 1)
    {
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $time = array(date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'));
        if ($type == 1) {
            $is_exist = TransactionLog::whereBetween('created_at', $time)
                ->where('transaction_no', $number)->count();
        } else {
            $is_exist = WalletTransactionLog::whereBetween('created_at', $time)
                ->where('transaction_no', $number)->count();
        }
        // 驗證不重覆
        if ($is_exist) {
            return $this->getTransactionNo($type);
        } else {
            return $number;
        }
    }

    private function checkMemberSetting($agent_id, $setting)
    {
        $agent = DB::table('agents')->where('id', $agent_id)->first();
        $data = DB::table('agent_setting_defines as asd')
            ->leftJoin('agent_customized_settings as acs', function ($join) use ($agent) {
                $join->on('asd.setting_id', 'acs.setting_key')
                    ->where('acs.agent_group_id', $agent->customized_setting_group_id);
            })
            ->where('asd.setting_id', $setting)
            ->select(
                'asd.setting_id',
                'asd.data_type',
                DB::raw('IFNULL(acs.value, asd.default_value) as value')
            )
            ->first();

        if ($data->data_type == 'boolean') {
            $result = ($data->value == 1) ? true : false;
        } else {
            $result = $data->value;
        }

        return $result;
    }
}
