<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Enums\WalletTypeEnum;
use target\support\Enums\OperatorEnum;
use Exception;
use Log;
use DB;

class DefiPay extends CashFlowBase
{
    private $rules_callback = [
        'id' => 'required',
        'amount' => 'required',
        'crypto' => 'required',
        'user_id' => 'required',
        'wallet_address' => 'required',
        'txn_id' => 'required',
        'sign' => 'required'
    ];

    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callback_url, string $return_url)
    {
        return ;
    }

    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    public function callback(Request $request)
    {
        $params = $request->all();
        Log::debug(get_class($this) . '_callback', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'url' => $request->fullUrl(),
            'params' => $params
        ]);
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);

            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            $this->checkSign($params['sign'], $info, [
                'merchant_id' => $info['MERCHANT_ID'],
                'amount' => $params['amount'],
                'crypto' => $params['crypto'],
                'user_id' => $params['user_id'],
                'wallet_address' => $params['wallet_address'],
                'txn_id' => $params['txn_id'],
            ]);  
            $third_party_order_no = $params['txn_id'];
            // TODO Name Lock
            $order = Order::where('third_party_order_no', $third_party_order_no)
                ->where('cash_flow_id', $agentCashFlow->cash_flow_id)
                ->first();
            if($order !== null) {
                throw new Exception('order already exist', 2001);
            }
            #新增訂單
            $orderType = $this->getOrderTypeByCrypto($agentCashFlow->cash_flow_id, $params['crypto']);
            $member = $this->getMemberByUuid($params['user_id']);
            $memberWallet = $this->getMemberWalletById($member->id, WalletTypeEnum::CODE_CHIP);

            $order_id = date('ymdHis') . '_' . str_pad(rand(0, 999), 3, '0', STR_PAD_LEFT) . '_' . $orderType->id;

            $data = array(
                'order_no' => $order_id,
                'third_party_order_no' => $third_party_order_no,
                'cash_flow_id' => $agentCashFlow->cash_flow_id,
                'agent_cash_flow_id' => $agentCashFlow->agent_cash_flow_id,
                'third_party_id' => $third_party_order_no,
                'order_type_id' => $orderType->id,
                'currency_id' => 0,
                'member_id' => $member->id,
                'wallet_type_id' => $memberWallet->wallet_type_id,
                'amount' => $this->getAmount($request->amount),
                'point' => $this->getAmount($request->amount), // TODO 匯率轉換
                'balance' => $memberWallet->balance,
                'ip' => $this->getIp(),
                'callback' => $request->fullUrl(),
                'remark' => json_encode($params),
                'status' => 1,
                'admin' => OperatorEnum::OPERATOR_SYSTEM
            );
            if (!$order = Order::create($data)) {
                throw new Exception('can not find order', 2002);
            }
            DB::commit();
            return new CallbackResponse(
                1,
                'success',
                $order
            );
            
        } catch (Exception $exception) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $exception->getMessage()
            ]);
            return new CallbackResponse(
                0,
                json_encode([
                    'code' => $exception->getCode(),
                    'message' => $exception->getMessage()
                ]),
                null
            );
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
        $text .= $info['SECRET_KEY'];
        
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

    private function getIp()
    {
        if (isset($_SERVER["HTTP_ALI_CDN_REAL_IP"])) {
            $ip = $_SERVER["HTTP_ALI_CDN_REAL_IP"];
        } else if (isset($_SERVER["HTTP_CF_CONNECTING_IP"])) {
            $ip = $_SERVER["HTTP_CF_CONNECTING_IP"];
        } else if (!empty(request()->header('V-HTTP-CLIENT-IP'))) {
            $ip = request()->header('V-HTTP-CLIENT-IP');
        } elseif (!empty($_SERVER["HTTP_CLIENT_IP"])) {
            $ip = $_SERVER["HTTP_CLIENT_IP"];
        } elseif (!empty($_SERVER["HTTP_X_FORWARDED_FOR"])) {
            $ip = $_SERVER["HTTP_X_FORWARDED_FOR"];
        } else {
            $ip = $_SERVER["REMOTE_ADDR"];
        }
        return $ip;
    }
}