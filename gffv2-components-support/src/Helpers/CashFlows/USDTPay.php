<?php

namespace target\support\Helpers\CashFlows;

use App\Models\WithdrawOrder;
use App\Models\WithdrawPayoutRecord;
use target\support\Models\AgentCashFlow;
use target\support\Models\CashFlowInfo;
use target\support\Models\CallbackResponse;
use target\support\Models\Member;
use target\support\Models\Order;
use target\support\Models\OrderType;
use target\support\Helpers\VendorSend;
use target\support\Services\TelegramService;
use Exception;
use Illuminate\Http\Request;
use Log;
use DB;

class USDTPay extends CashFlowBase
{
    private $rules_callback = [
        'crypto' => 'required',
        'amount' => 'required',
        'item_number' => 'required',
        'status' => 'required',
    ];

    private $rules_withdraw_callback = [
        'receiver_wallet_address' => 'required',
        'crypto' => 'required',
        'amount' => 'required',
        'item_number' => 'required',
        'status' => 'required',
    ];

    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @param Request $request
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode
     *      "url" : string
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        $order_type = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $method = 'GET';
        $url = $info['APIUrl'];
        $params = [
            'merchant_id' => $info['MerchantId'],
            'crypto' => $order_type->code,
            'amount' => $order->amount,
            'item_number' => $order->order_no,
        ];
        $sign = $this->getSign($info, $params);
        $params['sign'] = $sign;
        $url = $url . "?" . http_build_query($params);
        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
        ]);
        if (is_array($params)) {
            return [
                'type' => 1,
                'url' => $url
            ];
        }
        return ['type' => 0, 'url' => ''];
    }

    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    public function callback(Request $request)
    {
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);
            $params = $request->all();

            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);

            $info = json_decode($agentCashFlow->info, true);

            $this->checkSign($params['sign'], $info, [
                'merchant_id' => $info['MerchantId'],
                'crypto' => $params['crypto'],
                'amount' => $params['amount'],
                'item_number' => $params['item_number'],
                'status' => $params['status']
            ]);

            $order = Order::where('order_no', $params['item_number'])->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $params['wallet_address'],
                'params' => $params,
            ]);
            if ($order->status == 0) {
                if ($params['status'] == 2) {
                    $order->status = 1;
                    if ($params['amount'] < $order->amount) {
                        $amount = round($params['amount'], 2);
                        $point = round($amount * $agentCashFlow->rate);
                        $tgService = new TelegramService();
                        $tgService->sendMessage(
                            sprintf(
                                "USDT 單號 [%s] 打款數目比訂單數目少, 更新 amount %.2f to %.2f, point %.2f to %.2f",
                                $order->order_no,
                                $order->amount,
                                $amount,
                                $order->point,
                                $point
                            ),
                            'info'
                        );
                        $order->amount = $amount;
                        $order->point = $point;
                    } elseif ($params['amount'] > $order->amount) {
                        $order->status = 4;  //4:待手動確認上分
                        $params['orig_amount'] = $order->amount;
                        $params['orig_point'] = $order->point;
                        $amount = round($params['amount'], 2);
                        $point = round($amount * $agentCashFlow->rate);
                        $tgService = new TelegramService();
                        $tgService->sendMessage(
                            sprintf(
                                "USDT 單號 [%s] 打款數目比訂單數目多, 更新 amount %.2f to %.2f, point %.2f to %.2f",
                                $order->order_no,
                                $order->amount,
                                $amount,
                                $order->point,
                                $point
                            ),
                            'info'
                        );
                        $order->amount = $amount;
                        $order->point = $point;
                    }
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode($params);
                if (!$order->save()) {
                    throw new Exception('system error', 2002);
                }
                \DB::commit();
                if (in_array($order->status, [1, 4])) {
                    return new CallbackResponse(1, "success", $order);
                } else {
                    return new CallbackResponse(0, "failure", null);
                }
            } else {
                throw new Exception('order status not 0', 2002);
            }
        } catch (Exception $exception) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $exception->getMessage(),
            ]);
            return new CallbackResponse(0, "failure", null);
        }
    }

    /**
     * 代付三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    public function withdrawCallback(Request $request)
    {
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_withdraw_callback);
            $params = $request->all();

            $cashFlowInfo = CashFlowInfo::find($request->cash_flow_info_id);
            if ($cashFlowInfo === null) {
                throw new Exception('can not find cash flow info', 2002);
            }
            $info = json_decode($cashFlowInfo->info, true);

            $this->checkSign($params['sign'], $info, [
                'merchant_id' => $info['MerchantId'],
                'receiver_wallet_address' => $params['receiver_wallet_address'],
                'crypto' => $params['crypto'],
                'amount' => $params['amount'],
                'item_number' => $params['item_number'],
                'status' => $params['status']
            ]);

            $record = WithdrawPayoutRecord::where('transaction_no', $params['item_number'])->lockForUpdate()->first();
            if ($record === null) {
                throw new Exception('can not find payout record', 2002);
            }
            $withdrawOrder = WithdrawOrder::find($record->withdraw_order_id);
            if ($record === null) {
                throw new Exception('can not find withdraw order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $_SERVER['REQUEST_URI'],
                'params' => $params,
            ]);

            if ($withdrawOrder->status != 4) {
                $is_success_payout = ($params['status'] == 2)?1:0;
                $info_1 = $params['receiver_wallet_address'];
                if ($params['status'] == 4) {
                    $info_1 = 'Not enough credit, please contact the system provider';
                }
                $callback_time = gmdate('Y-m-d H:i:s');
                $update['result_amount'] = $params['amount'];
                $update['result_code'] = $params['status'];
                $update['transaction_time'] = $callback_time;
                $update['sign'] = $params['sign'];
                $update['callback_request_body'] = json_encode($params);
                $update['callback_time'] = $callback_time;
                $update['info_1'] = $info_1;
                $update['is_success_payout'] = $is_success_payout;
                $record->update($update);
                
                if ($is_success_payout) {
                    //TG 訊息最後再發送 避免浪費transaction等待時間; 正常應該是處於 [三方處理中]，若是其他狀態 e.g. 失敗、拒絕 ....
                    if ($withdrawOrder->status != 22) {
                        $msg = "代付回調單號:" . $params['item_number'] . PHP_EOL .
                            "訂單狀態異常: 當前狀態為" . $withdrawOrder->status . " 非三方處理中(22)" . PHP_EOL .
                            "，請進行確認";
                        $tgService = new TelegramService();
                        $tgService->sendMessage($msg, 'info');
                        DB::commit();
                    } else {
                        // Step 2. 更新存款訂單狀態
                        $withdrawOrder->update(['status' => 24]); //24: 三方代付成功，29: 三方回應失敗
                        // Stpe 3. 更新 transaction_logs 的狀態 (算進洗分報表數據內)
                        DB::table('transaction_logs')
                            ->where('type', 5)
                            ->where('order_id', $withdrawOrder->id)
                            ->update(['status' => 1000]);
                        DB::commit();
                        //若金額不符 姑且發個TG通知 但是維持流程進行
                        if ($withdrawOrder->amount != $params['amount']) {
                            $msg = "代付回調單號:".$params['item_number']."，金額不符。".PHP_EOL
                            ."訂單金額:".$withdrawOrder->amount."、回調金額：".$params['amount'];
                            $tgService = new TelegramService();
                            $tgService->sendMessage($msg, 'info');
                        }
                    }
                    return new CallbackResponse(1, "success", null);
                } else {
                    $withdrawOrder->update(['status' => 29]); //24: 三方代付成功，29: 三方回應失敗
                    DB::commit();
                    return new CallbackResponse(0, "success", null);
                }
            } else {
                return new CallbackResponse(1, "success", null);
            }
        } catch (Exception $exception) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $exception->getMessage(),
            ]);
            return new CallbackResponse(0, "failure", null);
        }
    }

    private function getSign($info, $params)
    {
        $params['amount'] = $params['amount']  * 1000000;
        ksort($params);
        $text = '';
        foreach ($params as $v) {
            $text .= $v;
        }
        $text .= $info['SecretKey'];
        $sign = strtolower(sha1($text));
        \Log::debug("getSign", [
            'text' => $text,
            'sign' => $sign
        ]);
        return $sign;
    }
    
    /**
     * @param $sign
     * @param $info
     * @param $params
     * @throws Exception
     */
    private function checkSign($sign, $info, $params)
    {
        if (isset($params['amount'])) {
            $params['amount'] = $params['amount'] * 1000000;
        }
        ksort($params);
        $text = '';
        foreach ($params as $v) {
            $text .= $v;
        }
        $text .= $info['SecretKey'];
        
        $msg = strtolower(sha1($text));
        Log::debug('--------', [
            'plainText' => $text,
            'sign1' => $sign,
            'sign2' => $msg
        ]);
        if ($sign !== $msg) {
            throw new Exception('sign error', 2002);
        }
    }
}
