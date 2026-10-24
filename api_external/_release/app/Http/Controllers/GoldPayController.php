<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Helpers\Common;
use App\Helpers\TelegramHelper;
use App\Models\WithdrawalPayoutRecord;
use App\Models\WithdrawalOrder;
use Hash;
use DB;

class GoldPayController extends Controller
{
    protected $cash_flow_id = 14;

    /**
     * GoldPay/withdrawal/callback
     * - 4.1 代收成功/失敗 回調     *
     * @response OK
     */
    public function WihtdrawalCallback(Request $request)
    {
        $begin_transaction = false;
        try {
            //非callback模式, 直接返回404
            if (Common::IsNullOrEmptyString($request->status) ||
                Common::IsNullOrEmptyString($request->result) ||
                Common::IsNullOrEmptyString($request->sign)) {
                return response('', 404);
            }

            $msg = '';
            $debug_json = json_encode($request->all());
            Common::log($debug_json, 'GoldPay_WihtdrawalCallback');

            //檢查簽名
            $sign = 'result=' . $request->result .
                '&status=' . $request->status .
                '&key=9nb4Zbqa48IdmlkV';
            $checkSign = strtoupper(md5($sign));
            //簽名錯誤, 直接返回
            if ($checkSign != $request->sign) {
                Common::log(
                    [
                        'sign' => $request->sign,
                        'check_sign' => $checkSign
                    ],
                    'GoldPay_Wihtdrawal_sign_error'
                );
                return response('success', 200);
            }

            $res_result = json_decode($request->result);
            if (is_object($res_result)) {
                $transaction_id = isset($res_result->transactionid) ? $res_result->transactionid : '';
                $order_id = isset($res_result->orderid) ? $res_result->orderid : '';
                $amount = isset($res_result->amount) ? $res_result->amount : 0;
            } else {
                $transaction_id = '';
                $order_id = '';
                $amount = 0;
            }

            DB::beginTransaction();
            $begin_transaction = true;

            $withdrawal_payout_record = WithdrawalPayoutRecord::lockForUpdate()
                ->where('third_party_transaction_no', $transaction_id)
                ->first();

            //如果紀錄不存在
            if (!is_object($withdrawal_payout_record)) {
                DB::rollback();
                return response('success', 200);
            }

            //如果部分欄位已有值(判定此單已處理過 直接返回)
            if (Common::IsNotNullNorEmptyString($withdrawal_payout_record->transaction_time) ||
               Common::IsNotNullNorEmptyString($withdrawal_payout_record->result_code)) {
                DB::rollback();    
                return response('success', 200);
            }

            //防單檢查我方單號
            if ($withdrawal_payout_record->transaction_no != $order_id) {
                DB::rollback();   
                return response('success', 200);
            }

            $withdrawal_order = WithdrawalOrder::lockForUpdate()
                ->where('id', $withdrawal_payout_record->withdrawal_order_id)
                ->first();

            //若訂單不存在
            if (!is_object($withdrawal_order)) {
                DB::rollback();
                return response('success', 200);
            }

            //若狀態已經是成功了 不重複處理
            if ($withdrawal_order->status == 4) {
                DB::rollback();
                return response('success', 200);
            }

            //正常應該是處於 [三方處理中]，若是其他狀態 e.g. 失敗、拒絕 ....
            if ($withdrawal_order->status != 22) {
                $msg = "代付回調單號:". $transaction_id . "訂單狀態不合預期".PHP_EOL."當前狀態為:".$withdrawal_order->status."，但回調流程仍會照常進行";
            }

            //若金額不符 姑且發個TG通知 但是維持流程進行
            if ($withdrawal_order->amount != $amount) {
                $msg = "代付回調單號:". $transaction_id . "，金額不符。".PHP_EOL."訂單金額:".$withdrawal_order->amount."、回調金額：".$amount;
            }

            if ($request->status == 30907) {
                $msg = 'GoldPay 代付餘額不足';
            }

            $is_success_payout = $request->status == 10000;
            $update = array();
            $update["result_amount"] = $amount;
            $update["result_code"] = $request->status;
            $update["transaction_time"] = gmdate("Y-m-d H:i:s");
            $update["sign"] = $request->sign;
            $update["callback_request_body"] = json_encode($request->all());
            $update["callback_time"] = gmdate('Y-m-d H:i:s');
            $update["info_1"] = '';
            $update["is_success_payout"] = $is_success_payout;

            // Step 1. 更新payout紀錄 資訊
            $withdrawal_payout_record->update($update);

            // Step 2. 更新存款訂單狀態
            $targetStatus = $is_success_payout ? 24 : 29;  //24: 三方代付成功，29: 三方回應失敗
            $withdrawal_order->update(
                [                  
                    'status' => $targetStatus
                ]);

            // Stpe 3. 更新 transaction_logs 的狀態 (算進洗分報表數據內)
            if ($targetStatus == 24) {
                DB::table('transaction_logs')
                ->where('type', 5)
                ->where('order_id', $withdrawal_order->id)
                ->update(['status' => 1000]);
            }            

            DB::commit();

            //TG 訊息最後再發送 避免浪費transaction等待時間
            if ($msg != '') {
                TelegramHelper::sendMessage($msg, '');
            }
            
            return response('success', 200);

        } catch (\Illuminate\Database\QueryException $e) {
            if ($begin_transaction) {
                DB::rollback();
            }
            $info = $e->errorInfo;
            TelegramHelper::sendMessage("GoldPay_WihtdrawalCallback Error!_".$info[0]."_".$info[2], '');
            return response()->json('success' , 200);

        } catch (\Exception $e) {
            if ($begin_transaction) {
                DB::rollback();
            }
            $msg = 'GoldPay_WihtdrawalCallback Error!_' . $e->getMessage() .
                ' line:' . $e->getLine() .
                ' flie:' . $e->getFile();
            TelegramHelper::sendMessage($msg, '');
            return response()->json('success', 200);
        }
    }
}