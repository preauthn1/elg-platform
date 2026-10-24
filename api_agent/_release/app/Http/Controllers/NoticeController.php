<?php

namespace App\Http\Controllers;

use App\Models\WithdrawalOrder;
use App\Models\Order;
use App\Models\AgentPermission;
use Illuminate\Http\Request;
use DB;
use App\REDIS;
use Validator;
use log;

class NoticeController extends Controller
{


    /**
     * notice/checkNotice
     * -確認是否開啟
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": 1
     * }
     */
    public function checkNotice(Request $request)
    {
        try {
            $result = 0;
            $check = AgentPermission::where('agent_id', $request->ID)
                ->join('agent_menus as am', function ($join) use ($request) {
                    $join->on('am.id', 'agent_permissions.agent_menu_id')
                        ->where('am.code', $request->code);
                })
                ->where('agent_id', $request->ID)
                ->first();
            if (is_object($check)) {
                $checkVoice = AgentPermission::where('agent_id', $request->ID)
                    ->join('agent_menus as am', function ($join) use ($request) {
                        $join->on('am.id', 'agent_permissions.agent_menu_id')
                            ->where('am.code', 'notice_voice');
                    })
                    ->where('agent_id', $request->ID)
                    ->first();
                if(is_object($checkVoice)){
                    $result = 2;
                } else {
                    $result = 1;
                }
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $result
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
     * notice/autoDepositAndWithdraw
     * -自助上分/洗分未處理訂單數
     *
     * @response {
     *  "code": 1000,
     *  "message": "Success",
     *  "data": [
     *          {
     *            "Deposit": "未處理自助上分,ex:1",
     *            "Withdraw": "未處理自助洗分,ex:1",
     *          }
     *    ]
     * }
     */
    public function autoDepositAndWithdraw(Request $request)
    {
        try {
            $info = $this->getAgentInfo($request->ID);

            $redis = new REDIS();
            $depositKey = array(
                'notice',
                'deposit',
                $info->agent_cash_flow_id
            );

            if (!$redis->exists($depositKey)) {
                $deposit = Order::where('agent_cash_flow_id', $info->agent_cash_flow_id)
                    ->where('status', 1);
                $depositAmount = $deposit->count();
                $depositLastOrderId = $deposit->orderBy('id', 'DESC')->pluck('id')->first();
                $data = array(
                    'amount' => $depositAmount,
                    'last_order_id' => $depositLastOrderId
                );
                $redis->setCommon($depositKey, $data);
            } else {
                $deposit = $redis->get($depositKey);
                $depositAmount = $deposit->amount;
                $depositLastOrderId = $deposit->last_order_id;
            }
            $withdrawKey = array(
                'notice',
                'withdrawal',
                $info->cash_flow_group_id
            );
            if (!$redis->exists($withdrawKey)) {
                $withdraw = WithdrawalOrder::where('agent_group_id', $info->cash_flow_group_id)
                    ->where('status', 1);
                $withdrawAmount = $withdraw->count();
                $withdrawLastOrderId = $withdraw->orderBy('id', 'DESC')->pluck('id')->first();
                $data = array(
                    'amount' => $withdrawAmount,
                    'last_order_id' => $withdrawLastOrderId
                );
                $redis->setCommon($withdrawKey, $data);
            } else {
                $withdraw = $redis->get($withdrawKey);
                $withdrawAmount = $withdraw->amount;
                $withdrawLastOrderId = $withdraw->last_order_id;
            }
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => [
                    'Deposit' => $depositAmount,
                    'deposit_last_order_id' => $depositLastOrderId,
                    'Withdraw' => $withdrawAmount,
                    'withdraw_last_order_id' => $withdrawLastOrderId
                ]
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

    private function getAgentInfo($agent_id)
    {
        $agentCashFlowId = DB::table('agent_cash_flows as acf')
            ->join('agent_groups as ag', 'ag.id', 'acf.agent_group_id')
            ->join('agents as a', function ($join) use ($agent_id) {
                $join->on('a.cash_flow_group_id', 'ag.id')
                    ->where('a.id', $agent_id);
            })
            ->where('acf.cash_flow_id', 5)
            ->select(
                'acf.id as agent_cash_flow_id',
                'a.cash_flow_group_id as cash_flow_group_id'
            )
            ->first();

        return $agentCashFlowId;
    }
}
