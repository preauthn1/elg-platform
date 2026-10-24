<?php

namespace App\Http\Controllers;

use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Helpers\Common;
use App\Helpers\TelegramHelper;
use Exception;
use Hash;
use DB;

class AllPayController extends Controller
{
    private $description = 'AllPay_WihtdrawalCallback';
    private $cash_flow_id = 23;

    /**
     * AllPay/withdrawal/callback
     * - 4.1 代收成功/失敗 回調     *
     * @response OK
     */
    public function WihtdrawalCallback(Request $request)
    {
        try {
            $params = $request->all();
            $content = json_decode(base64_decode($params['content']), true);
            $debug_json = json_encode($content);
            Common::log($debug_json , $this->description);

            DB::beginTransaction();
            $expressSQLRecord = DB::table('withdrawal_payout_records')
                ->where('third_party_transaction_no', $content['gbpOrderNo']);
            $queryExistRecord = $expressSQLRecord->first();

            //如果紀錄不存在
            if (!is_object($queryExistRecord)) {
                DB::rollback();    
                return response('Fail', 200);
            }

            //如果部分欄位已有值(判定此單已處理過 直接返回)
            if (Common::IsNotNullNorEmptyString($queryExistRecord->transaction_time) ||
               Common::IsNotNullNorEmptyString($queryExistRecord->result_code)) {
                DB::rollback();    
                return response('OK', 200);
            }

            if (Common::IsNullOrEmptyString($request->signMsg)) {
                DB::rollback();   
                return response('Fail', 200);
            }

            //防單檢查我方單號
            if ($queryExistRecord->transaction_no != $content['orderNo']) {
                DB::rollback();   
                return response('Fail', 200);
            }

            $expressSQLOrder = DB::table('withdrawal_orders')
                ->where('id', $queryExistRecord->withdrawal_order_id);
            $queryExistOrder = $expressSQLOrder->first();

            //若訂單不存在
            if (!is_object($queryExistOrder)) {
                DB::rollback();
                return response('Fail', 200);
            }

            //若狀態已經是成功了 不重複處理
            if ($queryExistOrder->status == 4) {
                DB::rollback();
                return response('OK', 200);
            }

            //取得settings
            $info = $this->getVendorApiSettings($queryExistOrder->member_id);
            //檢查簽名
            if (!$this->checkSign($params, $info)) {
                DB::rollback();
                return response('Fail', 200);
            }

            $is_success_payout = (strtolower($content['status']) == 'success');

            $update = array();
            $update['result_amount'] = $content['amount'];
            $update['result_code'] = $content['status'];
            $update['transaction_time'] = $content['createTime'];
            $update['sign'] = $request->signMsg;
            $update['callback_request_body'] = json_encode($content);
            $update['callback_time'] = gmdate('Y-m-d H:i:s');
            $update['info_1'] = $request->transaction_msg;
            $update['is_success_payout'] = $is_success_payout;
            
            // Step 1. 更新payout紀錄 資訊
            $expressSQLRecord->update($update);

            //TG 訊息最後再發送 避免浪費transaction等待時間
            //正常應該是處於 [三方處理中]，若是其他狀態 e.g. 失敗、拒絕 ....
            if ($queryExistOrder->status != 22) {
                $msg = "代付回調單號:" . $content['gbpOrderNo'] . PHP_EOL .
                    "訂單狀態異常: 當前狀態為" . $queryExistOrder->status . " 非三方處理中(22)" . PHP_EOL .
                    "，請進行確認";
                TelegramHelper::sendMessage($msg, '');
                DB::commit();
            } else {
                // Step 2. 更新存款訂單狀態
                $targetStatus = $is_success_payout ? 24 : 29;  //24: 三方代付成功，29: 三方回應失敗
                $expressSQLOrder->update(['status' => $targetStatus]);

                // Stpe 3. 更新 transaction_logs 的狀態 (算進洗分報表數據內)
                if ($targetStatus == 24) {
                    DB::table('transaction_logs')
                        ->where('type', 5)
                        ->where('order_id', $queryExistOrder->id)
                        ->update(['status' => 1000]);
                }
                DB::commit();
                //若金額不符 姑且發個TG通知 但是維持流程進行
                if ($queryExistOrder->amount != $content['amount']) {
                    $msg = "代付回調單號:".$content['gbpOrderNo']."，金額不符。".PHP_EOL."訂單金額:".$queryExistOrder->amount."、回調金額：".$content['amount'];
                    TelegramHelper::sendMessage($msg, '');
                }
            }

            
            return response('OK', 200);

        } catch (QueryException $e) {
            $info = $e->errorInfo;
            TelegramHelper::sendMessage($this->description . " Error!_".$info[0]."_".$info[2] , '');
            return response()->json([
                'code' => $info[0],
                'message' => $info[2]
            ]);

        } catch (Exception $e) {
            TelegramHelper::sendMessage($this->description . " Error!_".$e->getMessage() , '');
            return response()->json([
                'code' => $e->getCode(),
                'message' => $e->getMessage()
            ]);
        }
    }

    private function checkSign($param, $info)
    {
        $msg = sprintf(
            'content:%s&notifyTime:%s&version:%s&key:%s',
            $param['content'],
            $param['notifyTime'],
            $param['version'],
            $info['MerchantKey']
        );
        return ($param['signMsg'] !== strtoupper(md5($msg))) ? false : true;
    }

    private function getVendorApiSettings($member_id)
    {
        $query = DB::table('members as m')
            ->join('agents as a', 'a.id', 'm.parent')
            ->join('agent_cash_flows as acf', function ($join) {
                $join->on('acf.agent_group_id', 'a.cash_flow_group_id')
                    ->where('acf.cash_flow_id', $this->cash_flow_id);
            })
            ->where('m.id', $member_id)
            ->select('example.invalid')
            ->first();

        if (!is_object($query) || Common::IsNullOrEmptyString($query->info)) {
            return null;
        }

        $jsonSetting = json_decode($query->info , true);
        return $jsonSetting;
    }
}