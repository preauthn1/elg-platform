<?php

namespace target\support\Services;

use target\support\Models\FlowLimit;
use target\support\Models\Member;
use target\support\Models\MemberWallet;
use target\support\Models\Order;
use target\support\Models\TransactionLog;
use target\support\Enums\WalletTypeEnum;
use Exception;
use DB;
use Log;

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
            ->join('cash_flow_infos as cfi', function ($join) {
                $join->on('acf.cash_flow_info_id', 'cfi.id')
                    ->where('cfi.status', 1);
            })
            ->join('cash_flows as cf', function ($join) {
                $join->on('cfi.cash_flow_id', 'cf.id')
                    ->where('cf.status', 1);
            })
            ->join('order_types as ot', function ($join) use ($order_type_id) {
                $join->on('ot.cash_flow_id', 'cf.id')
                    ->where('ot.id', $order_type_id)
                    ->where('ot.status', 1);
            })
            ->where('m.id', $member_id)
            ->select(
                'ot.id',
                'ot.name',
                'ot.code',
                'acf.id as agent_cash_flow_id',
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
        int $cash_flow_id,
        int $agent_cash_flow_id,
        int $order_type_id,
        int $currency_id,
        int $member_id,
        string $amount,
        string $point,
        string $callback,
        string $ip_address
    ) {
        //@TODO WalletTypeEnum 到時候看要怎麼處理
        $data = [
            'cash_flow_id' => $cash_flow_id,
            'agent_cash_flow_id' => $agent_cash_flow_id,
            'order_type_id' => $order_type_id,
            'currency_id' => $currency_id,
            'member_id' => $member_id,
            'wallet_type_id' => WalletTypeEnum::CODE_CHIP,
            'amount' => $amount,
            'point' => $point,
            'balance' => 0,
            'callback' => $callback,
            'status' => 0,
            'ip' => $ip_address
        ];
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
            $type = 3;  //訂單儲值
            $setting_key = 6011;    //充值流水倍數
            $checkOrder = DB::table('orders')
                ->where('id', $order->id)
                ->lockForUpdate()
                ->first();
            if ($checkOrder->status != 1) {
                throw new Exception('order status does not equal 1.', 9999);
            }
            $member = Member::find($order->member_id);
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
            $memberWallet = MemberWallet::lockForUpdate()
                ->where('member_id', $order->member_id)
                ->where('wallet_type_id', $order->wallet_type_id)
                ->first();

            $before_balance = $memberWallet->balance;
            $balance = ($memberWallet->balance + $order->point);

            $log = array();
            $log['transaction_no'] = $this->getTransactionNo();
            $log['agent_id'] = $member->parent;
            $log['member_id'] = $order->member_id;
            $log['wallet_type_id'] = $order->wallet_type_id;
            $log['order_id'] = $order->id;
            $log['point'] = $order->point;
            $log['before_balance'] = $before_balance;
            $log['balance'] = $balance;
            $log['type'] = $type;
            $log['status'] = 0;
            $transaction_log = TransactionLog::create($log);

            $order->update([
                'status' => 3,
                'balance' => $balance,
                'pay_time' => date("Y-m-d H:i:s")
            ]);
            $memberWallet->update([
                'balance' => DB::raw('balance+' . $order->point)
            ]);

            $transaction_log = TransactionLog::find($transaction_log->id)
                ->update([
                    'status' => 1000
                ]);

            //增加稽核流水log
            if (floatval($agent->deposit_flow_limit_rate) > 0) {
                $flow = array();
                $flow['member_id'] = $order->member_id;
                $flow['type'] = $type;
                $flow['reference_id'] = isset($transaction_log)? $transaction_log->id : null;
                $flow['flow_limit'] = strval($order->point * floatval($agent->deposit_flow_limit_rate));
                $flow['admin'] = 0;
                FlowLimit::create($flow);
            }

            // 充值獎勵
            //DB::update('call SP_event_deposit(' . $order->id . ')');

            DB::commit();
        } catch (Exception $e) {
            Log::error($e->getMessage());
            DB::rollback();
        }
    }

    public function getOrderNo()
    {
        $prefix = date('Ymd');
        $orderNo = $prefix
            .random_int(100000, 999999)
            .substr(sprintf("%01.4f", microtime(true)), -4);
        return $orderNo;
    }

    public function getTransactionNo()
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
}
