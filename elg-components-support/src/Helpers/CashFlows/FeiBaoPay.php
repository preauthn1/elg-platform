<?php

namespace target\support\Helpers\CashFlows;

use target\support\Helpers\VendorSend;
use target\support\Models\Agent;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Member;
use target\support\Models\Order;
use target\support\Models\OrderType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class FeiBaoPay extends CashFlowBase
{
    private $rules_callback = [
        'code' => 'required',
        'merchant' => 'required',
        'merchant_order_num' => 'required',
        'action' => 'required',
        'order' => 'required',
        'msg' => 'required'
    ];

    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode, 3 Form Post
     *      "url" : string
     *      "html": string
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        if ($order->member_id > 0) {
            $member = Member::find($order->member_id);
        } else {
            $member = Agent::find($order->agent_id);
        }

        $order_type = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['APIUrl'];
        $method = 'POST';
        $params = [
            'amount' => $order->amount,
            'callback_url' => urlencode($callbackUrl),
            'device' => "mobile",
            'gateway' => $order_type['key'],
            'merchant_order_num' => $order->order_no,
            'merchant_order_remark' => '',
            'merchant_order_time' => strtotime("now") . '.000000',
            'return_url' => $returnUrl,
            'uid' => $member->username,
            'user_ip' => $order->ip
        ];

        $params_encrypt = $this->encrypt($info['MerchantKey'], $info['MerchantIV'], json_encode($params));

        $post_data = array(
            "merchant_slug" => $info['MerchantNo'],
            "data" => $params_encrypt
        );

        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
            'post_data' => $post_data
        ]);

        $sender = new VendorSend(['json_post' => true]);
        $headers = array('Content-Type:application/json');
        $result = $sender->send('FeiBaoPay_API', $url, $method, $post_data, $headers);
        $response = json_decode($result, true);

        if ($response['code'] == 0 && $response['merchant_order_num'] == $order->order_no) {
            $order_str = str_replace('\n', '', $response['order']); // 他回傳的加密字串會有\n這個換行會導致無法解密
            $order_decrypt = $this->decrypt($info['MerchantKey'], $info['MerchantIV'], $order_str);
            $order_info = json_decode($order_decrypt, true);
            return [
                'type' => 1,
                'url' => $order_info['navigate_url']
            ];
        }

        return [
            'type' => 0,
            'url' => ''
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
        try {
            $this->validate($request, $this->rules_callback);

            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            $order_str = str_replace('\n', '', $request->order); // 他回傳的加密字串會有\n這個換行會導致無法解密
            $order_info = json_decode($this->decrypt($info['MerchantKey'], $info['MerchantIV'], $order_str), true);

            DB::beginTransaction();
            $order = Order::where('order_no', $request->merchant_order_num)->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'order_info' => $order_info
            ]);

            if ($order->status == 0) {
                if ($order_info['status'] == 'success' || $order_info['status'] == 'success_done') {
                    $amount = number_format($order_info['amount'], 4);
                    if ($order->amount == $amount) {
                        $order->status = 1;
                    } else {
                        //callback金額不符
                        $order->status = 2;
                    }
                } elseif ($order_info['status'] == 'waiting' || $order_info['status'] == 'processing') {
                    $order->status = 0;
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode($order_info);
                if (!$order->save()) {
                    throw new Exception('system error', 2002);
                }
                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(1, 'success', $order);
                } else {
                    return new CallbackResponse(0, 'failure', $order);
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
                'file' => $exception->getFile(),
                'line' => $exception->getLine()
            ]);
            return new CallbackResponse(0, 'failure', null);
        }
    }


    private function encrypt($key, $iv_code, $data)
    {
        // openssl 實現的 AES 加密
        $cryptText = openssl_encrypt($data, "aes-256-cbc", $key, OPENSSL_RAW_DATA, $iv_code);
        return base64_encode($cryptText);
    }

    private function decrypt($key, $iv_code, $data)
    {
        // 解密
        $cryptText = base64_decode($data);
        return trim(openssl_decrypt($cryptText, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv_code));
    }
}
