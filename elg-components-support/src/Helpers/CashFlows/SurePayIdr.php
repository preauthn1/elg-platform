<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SurePayIdr extends CashFlowBase
{
    private $rules_callback = [
        'status' => 'required',
        'merchant_id' => 'required',
        'amount' => 'required',
        'apply_date' => 'required',
        'trans_no' => 'required',
        'mer_trans_no' => 'required',
        'respCode' => 'required',
        'currency' => 'required',
        'sign' => 'required'
    ];

    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @param Request $request
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode, 3 Form Post
     *      "url" : string
     *      "html": string
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        $info = json_decode($agentCashFlow->info, true);
        $type = 0;
        $return_url = '';

        $url = $info['APIUrl'] . '/api/depositRequest';
        $method = 'POST';
        $params = [
            'trans_no' => $order->order_no,
            'amount' => $order->amount,
            'merchant_id' => $info['MerchantId'],
            'redirect_url' => $returnUrl,
            'feedback_url' => $callbackUrl,
            'payment_method' => $this->getPaymentMethod($info['Currency']),
            'currency' => $this->getCurrencyCode($info['Currency'])
        ];

        $sign = $this->makeSign($info, $params);
        $params['sign'] = $sign;

        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params
        ]);

        $sender = new VendorSend(['json_post' => true]);
        $response = $sender->send('SurePayIdr_API', $url, $method, $params, ['Content-Type: application/json']);
        $responseData = json_decode($response, true);
        if (isset($responseData['status']) && strtolower($responseData['status']) == 'success') {
            $order->save();
            $type = 1;
            $return_url = urldecode($responseData['payment_url']);
        }
        
        return [
            'type' => $type,
            'url' => $return_url
        ];
    }

    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    public function callback(Request $request)
    {
        $params = $request->all();
        $agent_cash_flow_id = $request->agent_cash_flow_id;
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);
            $agentCashFlow = $this->getAgentCashFlow($agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            $this->checkSign($params, $info);

            $order = Order::where('order_no', $params['trans_no'])->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            if ($params['currency'] != $this->getCurrencyCode($info['Currency'])) {
                throw new Exception('currency error', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            //三方單號
            $order->third_party_order_no = $params['mer_trans_no'];
            if ($order->status == 0) {
                if ($params['status'] == '3') {  //Success = 3
                    $amount = $params['amount'];
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
                    return new CallbackResponse(1, 'success', $order);
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
                'exception' => $exception->getMessage()
            ]);
            return new CallbackResponse(0, "failure", null);
        }
    }

    private function getCurrencyCode($currency)
    {
        //1 = India Rupee
        //2 = Indonesia Rupiah
        switch (strtolower($currency)) {
            case 'idr':
                $currency_id = 2;
                break;
            case 'inr':
                $currency_id = 1;
                break;
            default:
                $currency_id = 0;
                break;
        }
        return $currency_id;
    }

    private function getPaymentMethod($currency)
    {
        //India = upi
        //Indonesia = online_transfer
        switch (strtolower($currency)) {
            case 'idr':
                $payment_method = 'online_transfer';
                break;
            case 'inr':
                $payment_method = 'upi';
                break;
            default:
                $payment_method = '';
                break;
        }
        return $payment_method;
    }

    private function makeSign($info, $params)
    {
        ksort($params);
        //使用 JSON_UNESCAPED_SLASHES 選項保留 URL 中的斜線
        $json_string = json_encode($params, JSON_UNESCAPED_SLASHES);
        // 使用正規表達式將 currency 的值轉換為字串
        $json_string = preg_replace('/"currency":\s*(\d+)/', '"currency":"$1"', $json_string);

        $json_string .= $info['MerchantKey'];

        $sign = strtolower(md5($json_string));
        Log::debug(get_class($this) . '_sign', [
            'sign_string' => $json_string,
            'sign' => $sign
        ]);

        return $sign;
    }


    /**
     * @param $params
     * @param $info
     * @throws Exception
     */
    private function checkSign($params, $info)
    {
        $sign = strtolower($params['sign']);
        $params['status'] = intval($params['status']);
        unset($params['sign']);
        $str = json_encode($params) . $info['MerchantKey'];
        $check_sign = strtolower(md5($str));
        if ($check_sign !== $sign) {
            Log::debug(get_class($this) . '_checkSign', [
                'check_string' => $str,
                'sign' => $sign,
                'encryption_string' => $check_sign,
                'result' => ($check_sign == $sign)
            ]);
            throw new Exception('sign error', 2002);
        }
    }
}
