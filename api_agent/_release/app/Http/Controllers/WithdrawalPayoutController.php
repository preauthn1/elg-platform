<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\WithdrawalOrder;
use App\Models\TransactionLog;
use App\Repositories\AgentCashFlowRepository;
use App\ThirdPartyApi\Models\ThirdPartyApiPayoutResult;
use App\ThirdPartyApi\ATPayIntegrationHelper;
use App\ThirdPartyApi\GoldPayIntegrationHelper;
use App\ThirdPartyApi\AllPayIntegrationHelper;
use Validator;
use DB;
use log;

class WithdrawalPayoutController extends Controller
{

    /**
     * withdrawal/payout-process
     * - 將提款單設置為代付處理
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": 
     *    {
     *    }
     * }
     */
    public function runPayoutProcess(Request $request)
    {
        try {          
            $agent_cash_flow_id = $request->agent_cash_flow_id;
            $target_order_id = $request->order_id;

            $query_acf = DB::table('agent_cash_flows as ac')
                ->where('id', $agent_cash_flow_id)
                ->first();

            if (!is_object($query_acf)) {
                return response()->json([
                    'code' => 4044,
                    'message' => __('error_message.4044'),
                    'data' => ''
                ]);
            }

            $cash_flow_id = $query_acf->cash_flow_id;

            //先將訂單 狀態更新為 準備請求三方         
            $result = $this->UpdateOrderStatusToWaitingRequest($target_order_id);
            $original_status = $result['original_status'];

            if ($result['is_success'] == false) {
                return response()->json([
                    'code' => 4501,
                    'message' => __('error_message.4501'),
                    'data' => ''
                ]);
            }
           
            //請務必每一支call都實作返回這種格式的物件
            $payoutResult = new ThirdPartyApiPayoutResult();
            $isNotImplement = false;
            switch ($cash_flow_id) {
                case 11:
                    $payoutResult = ATPayIntegrationHelper::RequestWithdrawalOrder($target_order_id);
                    break;
                case 14:
                    $payoutResult = GoldPayIntegrationHelper::RequestWithdrawalOrder($target_order_id);
                    break;
                case 23:
                    $payoutResult = AllPayIntegrationHelper::RequestWithdrawalOrder($target_order_id);
                    break;
                default:    
                    $isNotImplement = true; 
                    break;               
            }

            if ($isNotImplement) {
                $this->UpdateOrderStatusToReviewConfirm($target_order_id, $original_status);

                return response()->json([
                    'code' => 9999,
                    'message' => '此 cash_flow_id 並未進行實作代付 (' . $cash_flow_id . ')',
                ]);
            }

            if (!$payoutResult->is_success) {
                $this->UpdateOrderStatusToReviewConfirm($target_order_id, $original_status);

                $langText = __('error_message.4502');
                return response()->json([
                    'code' => 4502,
                    'message' => $langText . ' (' . $payoutResult->display_error_msg . ') '
                ]);
            }

            //將狀態更新為 三方處理中
            $this->UpdateOrderStatusToThirdPartyOnProcess(
                $target_order_id,
                $payoutResult->third_party_order_no,
                $payoutResult->third_party_response
            );

            //更新transaction_logs
            TransactionLog::where('order_id', $target_order_id)
                ->where('type', 5)
                ->update(
                    [
                        'operator' => $request->USERNAME,
                        'admin' => $request->ID
                    ]
                );
            
            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
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
     * withdrawal/payout/cash_flows
     * - 取得特定提款單 可用的代付廠商
     *
     * @response {
     *  "code": 1000,
     *  "message": "SUCCESS",
     *  "data": 
     *    {
     *    }
     * }
     */
    public function getAvailablePayoutTypesByWitdhrawalOrder(Request $request)
    {
        try {             
            $target_order_id = $request->order_id;

            $queryOrder = DB::table('withdrawal_orders as wo')
                ->join('members as m', 'wo.member_id', 'm.id')
                ->where('wo.id', $target_order_id)
                ->select(
                    'm.parent as agent_id',
                    'wo.bank_code',
                    'wo.amount'
                )
                ->first();            

            $rep = new AgentCashFlowRepository();

            //根據訂單所屬代理、銀行代碼 查出可用的代付
            $query = $rep->getAvailablePayoutCashflowByAgentID(
                $queryOrder->agent_id,
                $queryOrder->bank_code,
                $queryOrder->amount
            );

            return response()->json([
                'code' => 1000,
                'message' => __('error_message.1000'),
                'data' => $query,
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

    private function UpdateOrderStatusToWaitingRequest($target_order_id)
    {
        DB::beginTransaction();

        $queryExist = WithdrawalOrder::where('id' ,$target_order_id)->lockForUpdate()->first();

        $availableStatus = [ 3, 29 ];

        if (!is_object($queryExist) || !in_array($queryExist->status, $availableStatus)) {
            DB::rollback();
            return 
            [
                'original_status' => 0,
                'is_success' => false
            ];
        }

        $original_status = $queryExist->status;

        //狀態 0:訂單建立,1:待審核,2:失敗,3:通過,4:已轉帳 21:代付提交中 22:代付處理中 24:三方成功  29:三方失敗
        //將狀態更新為三方提交中
        $queryExist->update(
        [
            'status' => 21   //代付提交中(尚未打API，先鎖定此筆單、避免重複提交)
        ]);

        DB::commit();    
    
        return 
        [
            'original_status' => $original_status,
            'is_success' => true
        ];
    }

    private function UpdateOrderStatusToThirdPartyOnProcess($target_order_id, $third_party_order_no, $third_party_response)
    {
        $queryExist = WithdrawalOrder::where('id' ,$target_order_id)->first();

        //將狀態更新為代付處理中
        $queryExist->update(
        [
            'third_party_response' =>  $third_party_response,
            'third_party_order_no' => $third_party_order_no,
            'status' => 22   //代付處理中
        ]); 
    }


    private function UpdateOrderStatusToReviewConfirm($target_order_id, $original_status)
    {
        $queryExist = WithdrawalOrder::where('id', $target_order_id)->first();

        //將狀態還原回已審核
        $queryExist->update(
        [
            'status' => $original_status
        ]); 
    }
}