<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Common;
use App\Models\OtaOrder;
use App\Models\Member;
use App\Models\TransactionLog;
use Hash;
use DB;
use log;

class OtaController extends Controller
{

    /**
     * authenticate
     * -4.1驗證登入
     *
     * @bodyParam user_id string token Example:xxx
     * @bodyParam data string 帳號 Example: xxx
     * @bodyParam product_code string 密碼 Example: xxx
     * @bodyParam amount float64 密碼 Example: xxx
     * @bodyParam description string 密碼 Example: xxx
     * @bodyParam date_time string 密碼 Example: xxx
     * @bodyParam hash_value string 密碼 Example: xxx
     * @response {
     *  "playerID": "玩家編號",
     *  "error": "成功回應0，其他錯誤代碼自訂",
     *  "description": "失敗回應錯誤訊息(選填)"
     * }
     */
    public function requestPayment(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'ota_request_payment_body');
            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }

            $memberId = DB::table('member_access_tokens as mat')
                ->join('members as m', 'm.id', 'mat.member_id')
                ->where('token', $param['client_user_id'])
                ->where('expire_date' ,'>', date('Y/m/d H:i:s'))
                ->orderBy('id', 'desc')
                ->pluck('m.id')
                ->first();

            if (is_null($memberId)) {
                return response()->json([
                    'hash_value' => $param['hash_value'],
                    'remark' => 'Session expired, please relogin',
                ]);
            }

            $agentCashFlow = $this->getAgentCashFlowId($memberId);

            if (is_object($agentCashFlow)) {
                $info = json_decode($agentCashFlow->info);
                //把需串成sign的參數放進array
                $hash_param = array(
                    'KEY' => $info->key,
                    'date_time' => $param['date_time'],
                    'client_user_id' => $param['client_user_id'],
                    'product_code' => $param['product_code'],
                    'data' => $param['data'],
                    'amount' => $param['amount']
                );
                $sign = $this->sign($hash_param);

                if ($param['hash_value'] != $sign) {
                    return response()->json([
                        'hash_value' => $param['hash_value'],
                        'remark' => 'hash_value error'
                    ]);
                }

                DB::beginTransaction();
                $member = Member::lockForUpdate()->find($memberId);

                if ($member->balance >= $param['amount']) {
                    $transaction_no = $this->getTransactionNo();

                    $data = array(
                        'reference_number' => $transaction_no,
                        'member_id' => $memberId,
                        'token' => $param['client_user_id'],
                        'agent_cash_flow_id' => $agentCashFlow->id,
                        'amount' => $param['amount'],
                        'status' => 1,
                        'info' => json_encode($param)
                    );

                    $createOrder = OtaOrder::create($data);

                    $before_balance = $member->balance;
                    $balance = ($member->balance - $param['amount']);
                    $member->update([
                        'balance' => DB::raw('balance-' . $param['amount'])
                    ]);

                    $log = array();
                    $log['transaction_no'] = $transaction_no;
                    $log['agent_id'] = $member->parent;
                    $log['member_id'] = $member->id;
                    $log['order_id'] = $createOrder->id;
                    $log['point'] = $param['amount'];
                    $log['before_balance'] = $before_balance;
                    $log['balance'] = $balance;
                    $log['type'] = 22;//預付網扣款
                    $log['operator'] = '';
                    $log['status'] = 9; //未審核前 狀態先保持在9
                    $log['remark'] = 'Ota';

                    $createTransactionLog = TransactionLog::create($log);

                    if ($createTransactionLog) {
                        DB::commit();
                        return response()->json([
                            'hash_value' => $param['hash_value'],
                            'reference_number' => $transaction_no,
                            'remark' => 'SUCCESS'
                        ]);
                    } else {
                        DB::rollback();
                        return response()->json([
                            'hash_value' => $param['hash_value'],
                            'remark' => 'Error'
                        ]);
                    }
                } else {
                    DB::rollback();
                    return response()->json([
                        'hash_value' => $param['hash_value'],
                        'remark' => 'Insufficient balance'
                    ]);
                }
            } else {
                return response()->json([
                    'hash_value' => $param['hash_value'],
                    'remark' => 'Error'
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

    public function orderStatus(Request $request)
    {
        try {
            Common::log([
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $request->all(),
                'ContentType' => $request->getContentType(),
                'Content' => $request->getContent()
            ], 'ota_order_status_body');

            if ($request->all() == null) {
                $param = json_decode($request->getContent(), true);
            } else {
                $param = $request->all();
            }

            $order = OtaOrder::where('reference_number', $param['reference_number'])->first();
            if (!is_object($order)) {
                return response()->json([
                    'hash_value' => $param['hash_value'],
                    'remark' => 'reference_number error',
                ]);
            }

            if ($order->status == 2 || $order->status == 3) {
                return response()->json([
                    'hash_value' => $param['hash_value'],
                    'remark' => 'This order has been settled',
                ]);
            }

            $agentCashFlow = DB::table('agent_cash_flows')->find($order->agent_cash_flow_id);
            if (is_object($agentCashFlow)) {
                $info = json_decode($agentCashFlow->info);
                //把需串成sign的參數放進array
                $hash_param = array(
                    'KEY' => $info->key,
                    'reference_number' => $param['reference_number'],
                    'status' => $param['status']
                );
                $sign = $this->sign($hash_param);

                if ($param['hash_value'] != $sign) {
                    return response()->json([
                        'hash_value' => $param['hash_value'],
                        'hash' => $sign,
                        'remark' => 'hash_value error',
                    ]);
                }

                if ($param['status'] == 'SUCCESS') {
                    //把訂單更新成已完成
                    TransactionLog::where('transaction_no', $param['reference_number'])->update(['status' => 1000]);
                    $updateOrder = $order->update(['status' => 2]);
                    if ($updateOrder) {
                        return response()->json([
                            'hash_value' => $param['hash_value'],
                            'reference_number' => $param['reference_number'],
                            'remark' => ''
                        ]);
                    } else {
                        return response()->json([
                            'hash_value' => $param['hash_value'],
                            'remark' => 'Error'
                        ]);
                    }

                } elseif ($param['status'] == 'REFUNDED') {

                    //拒絕後要把錢還給玩家
                    DB::beginTransaction();
                    $member = Member::lockForUpdate()->find($order->member_id);

                    $before_balance = $member->balance;
                    $balance = ($member->balance + $order->amount);
                    $member->update([
                        'balance' => DB::raw('balance+' . $order->amount)
                    ]);
                    //建立一筆退款的transaction_log

                    $log = array();
                    $log['status'] = 9996;
                    $log['remark'] = 'Ota order REFUNDED';

                    TransactionLog::where('transaction_no', $param['reference_number'])->update($log);

                    $updateOrder = $order->update(['status' => 3]);;

                    if ($updateOrder) {
                        DB::commit();
                        return response()->json([
                            'hash_value' => $param['hash_value'],
                            'reference_number' => $param['reference_number'],
                            'remark' => ''
                        ]);
                    } else {
                        DB::rollback();
                        return response()->json([
                            'hash_value' => $param['hash_value'],
                            'remark' => 'Error'
                        ]);
                    }
                } else {
                    return response()->json([
                        'hash_value' => $param['hash_value'],
                        'remark' => 'status error'
                    ]);
                }
            } else {
                return response()->json([
                    'hash_value' => $param['hash_value'],
                    'remark' => 'Error'
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

    private function getAgentCashFlowId($memberId)
    {
        $AgentCashFlowId = DB::table('agent_cash_flows as acf')
            ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
            ->join('agents as a', 'a.cash_flow_group_id', 'ag.id')
            ->join('members as m', function ($join) use ($memberId) {
                $join->on('m.parent', 'a.id')
                    ->where('m.id', $memberId);
            })
            ->where('acf.cash_flow_id', 18)
            ->select('acf.*')
            ->first();

        return $AgentCashFlowId;
    }

    private function sign($params)
    {
        $str = '';
        foreach ($params as $row) {
            $str .= $row;
            if ($row !== end($params)) {
                $str .= '*';
            }
        }
        return md5($str);
    }

    public function getTransactionNo()
    {
        $number = date('ymdHis') . str_pad(rand(0, 9999), 4, '0', STR_PAD_LEFT);
        $is_exist = DB::table('transaction_logs')
            ->whereBetween('created_at', [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')])
            ->where('transaction_no', $number)->count();
        // 驗證不重覆
        if ($is_exist) {
            return self::getTransactionNo();
        } else {
            return $number;
        }
    }
}