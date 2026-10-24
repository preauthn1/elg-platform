<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Helpers\Common;
use App\Ace333Account;
use Hash;
use DB;
use App\Helpers\TelegramHelper;


class ATPayController extends Controller
{
    private $cash_flow_id_ATPay = 11;


    /**
     * ATPay/withdrawal/callback
     * - 4.1 代收成功/失敗 回調     *
     * @response OK
     */
    public function WihtdrawalCallback(Request $request)
    {
        try {           
           
            $debug_json = json_encode($request->all());
            Common::log($debug_json , "ATPay-WihtdrawalCallback");       

            DB::beginTransaction();

            $expressSQLRecord = DB::table("withdrawal_payout_records")->where("third_party_transaction_no" , $request->transaction_id);
            $queryExistRecord = $expressSQLRecord->first();


            //如果紀錄不存在
            if(!is_object($queryExistRecord))
            {
                DB::rollback();    
                return response('Fail' , 200);
            }

            //如果部分欄位已有值(判定此單已處理過 直接返回)
            if(Common::IsNotNullNorEmptyString($queryExistRecord->transaction_time) || 
               Common::IsNotNullNorEmptyString($queryExistRecord->result_code))
            {
                DB::rollback();    
                return response('OK' , 200);
            }

            if(Common::IsNullOrEmptyString($request->sign))
            {
                DB::rollback();   
                return response('Fail' , 200);
            }

            //防單檢查我方單號
            if($queryExistRecord->transaction_no != $request->orderid)
            {
                DB::rollback();   
                return response('Fail' , 200);
            }

            $expressSQLOrder = DB::table("withdrawal_orders")->where("id" , $queryExistRecord->withdrawal_order_id);
            $queryExistOrder = $expressSQLOrder->first();
                    
            //若訂單不存在
            if(!is_object($queryExistOrder))
            {
                DB::rollback();    
                return response('Fail' , 200);
            }

            //若狀態已經是成功了 不重複處理
            if($queryExistOrder->status == 4)
            {
                DB::rollback();    
                return response('OK' , 200);
            }
           
            $is_success_payout = $request->returncode == "00";


            $update = array();
            $update["result_amount"] = $request->amount;
            $update["result_code"] = $request->returncode;
            $update["transaction_time"] = $request->datetime;
            $update["sign"] = $request->sign;
            $update["callback_request_body"] = json_encode($request->all());
            $update["callback_time"] = gmdate('Y-m-d H:i:s');
            $update["info_1"] = $request->transaction_msg;
            $update["is_success_payout"] = $is_success_payout;
            
            // Step 1. 更新payout紀錄 資訊
            $expressSQLRecord->update($update);


            // Step 2. 更新存款訂單狀態
            $targetStatus = $is_success_payout ? 24 : 29;  //24: 三方代付成功，29: 三方回應失敗            
            $expressSQLOrder->update(
                [                  
                    'status' => $targetStatus
                ]);

            // Stpe 3. 更新 transaction_logs 的狀態 (算進洗分報表數據內)
            if($targetStatus == 24)
            {
                DB::table("transaction_logs")
                ->where("type" , 5)
                ->where("order_id" , $queryExistOrder->id)
                ->update(["status" => 1000]);
            }            

            DB::commit();                


            //TG 訊息最後再發送 避免浪費transaction等待時間
            //正常應該是處於 [三方處理中]，若是其他狀態 e.g. 失敗、拒絕 ....
            if($queryExistOrder->status != 22)
            {
                $msg = "代付回調單號:". $request->transaction_id."訂單狀態不合預期".PHP_EOL."當前狀態為:".$queryExistOrder->status."，但回調流程仍會照常進行";
                TelegramHelper::sendMessage($msg , '');
            }

            //若金額不符 姑且發個TG通知 但是維持流程進行
            if($queryExistOrder->amount != $request->amount)
            {
                $msg = "代付回調單號:". $request->transaction_id."，金額不符。".PHP_EOL."訂單金額:".$queryExistOrder->amount."、回調金額：".$request->amount;
                TelegramHelper::sendMessage($msg , '');
            }
            
            return response('OK' , 200);

        } catch (\Illuminate\Database\QueryException $e) {
            
            $info = $e->errorInfo;
            TelegramHelper::sendMessage("ATPay_WihtdrawalCallback Error!_".$info[0]."_".$info[2] , '');

            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);

        } catch (\Exception $e) {

            TelegramHelper::sendMessage("ATPay_WihtdrawalCallback Error!_".$e->getMessage() , '');

            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }
}