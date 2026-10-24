<?php

namespace target\support\Helpers\CashFlows;

use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Helpers\VendorSend;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class BigPay extends CashFlowBase
{
    private $rules_callback = [
        'contract' => 'required',
        'transaction' => 'required',
        'status' => 'required',
        'status_message' => 'required',
        'ItemID' => 'required',
        'ItemDescription' => 'required',
        'Amount' => 'required',
        'Currency' => 'required',
        'ClientName' => 'required',
        'signature2' => 'required'
    ];

    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode
     *      "url" : string
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        $info = json_decode($agentCashFlow->info, true);
        $method = 'POST';
        $url = $info['APIUrl'];
        if ($order->member_id > 0) {
            $itemDescription = 'top up for member ' . $order->member_id;
            $clientName = $order->member_id;
        } else {
            $itemDescription = 'top up for agent ' . $order->agent_id;
            $clientName = $order->agent_id;
        }
        $params = [
            'Seller' => $info['Username'],
            'ReturnURL' => $returnUrl,
            'FailedReturnURL' => $returnUrl,
            'HTTPPostURL' => $callbackUrl,
            'Amount' => $order->amount,
            'Currency' => $info['Currency'],
            'ItemID' => $order->order_no,
            'ItemDescription' => $itemDescription,
            'ClientName' => $clientName
        ];
        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
        ]);
        $sender = new VendorSend();
        $headers = array(
            'Authorization: ' . $info['APIKey'],
            'Content-Type: application/x-www-form-urlencoded'
        );
        $response = $sender->send('BigPay_API', $url, $method, $params, $headers);
        $responseData = json_decode($response, true);
        if (!isset($responseData['error_message'])) {
            if ($responseData['status'] == 0) {
                $order->third_party_order_no = $responseData['transaction'];
                $order->remark = $response;
                $order->save();
                return [
                    'type'=>1,
                    'url'=>str_replace('redirectlink=', '', $responseData['redirect_to'])
                ];
            }
        }
        return ['type'=>0, 'url'=>''];
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
                $params['transaction'],
                $params['status'],
                $params['Currency'],
                $params['Amount'],
                $params['created_at'],
                $info['APIKey'],
                $params['signature2']
            );

            $order = Order::where('order_no', $params['ItemID'])->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'params' => $params,
            ]);

            if ($order->status == 0) {
                // 1 是系統自動回調成功 3 是手動回調成功
                if ($params['status'] == 1 || $params['status'] == 3) {
                    $amount = $this->getAmount($params['Amount']);
                    if ($order->amount == $amount) {
                        $order->status = 1;
                    } else {
                        //callback金額不符
                        $order->status = 2;
                    }
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode($params);

                if (!$order->save()) {
                    throw new Exception('system error', 2002);
                }
                DB::commit();
                if ($order->status == 1) {
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
    private function checkSign($transaction_id, $status_code, $currency, $amount, $created_at, $api_key, $sign)
    {
        $str = $transaction_id . $status_code . $currency . $amount . $created_at;
        $msg = hash_hmac('sha256', $str, $api_key);

        if ($sign !== $msg) {
            throw new Exception('sign error', 2002);
        }
    }
}
