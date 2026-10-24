<?php

namespace target\support\Helpers\CashFlows;

use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Models\OrderType;
use target\support\Services\TelegramService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class USDTPay extends CashFlowBase
{
    private $rules_callback = [
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
            'crypto' => $order_type->key,
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

            $this->checkSign(
                $params['crypto'],
                $params['amount'],
                $params['item_number'],
                $params['status'],
                $info['MerchantId'],
                $info['SecretKey'],
                $params['sign']
            );

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
                        $tg_service = new TelegramService();
                        $tg_service->sendMessage(
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
                        
                        $tg_service = new TelegramService();
                        $tg_service->sendMessage(
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
                DB::commit();
                if (in_array($order->status, [1, 4])) {
                    return new CallbackResponse(1, "success", $order);
                } else {
                    return new CallbackResponse(0, "failure", null);
                }
            } else {
                throw new Exception('order status not 0', 2002);
            }
        } catch (Exception $e) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $e->getMessage(),
            ]);
            return new CallbackResponse(0, "failure", null);
        }
    }


    private function getSign($info, $params)
    {
        return strtolower(
            sha1(
                $params['amount'] .
                '000000' .
                $params['crypto'] .
                $params['item_number'] .
                $params['merchant_id'] .
                $info['SecretKey']
            )
        );
    }

    /**
     * @param $crypto
     * @param $amount
     * @param $item_number
     * @param $status
     * @param $merchant_id
     * @param $secret_key
     * @param $sign
     * @throws Exception
     */
    private function checkSign($crypto, $amount, $item_number, $status, $merchant_id, $secret_key, $sign)
    {
        $amount = $amount * 1000000;
        $msg = strtolower(sha1($amount . $crypto . $item_number . $merchant_id . $status . $secret_key));

        if ($sign !== $msg) {
            throw new Exception('sign error', 2002);
        }
    }
}
