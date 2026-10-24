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

class Mb88Pay extends CashFlowBase
{
    private $rules_callback = [
        'orderUserName' => 'required',
        'order' => 'required',
        'currentMoney' => 'required'
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
            'secretKeyId' => $info['SecretKeyId'],
            'ipAddress' => $info['IPAddress'],
            'orderUserName' => $member->username,
            'order' => $order->order_no,
            'cliNA' => 'PC',
            'notifyUrl' => $callbackUrl,
            'totalMoney' => $order->amount,
            'bankId' => $order_type->key
        ];
        $params['md5'] = $this->md5($info, $params);

        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params
        ]);
        $sender = new VendorSend(['json_post' => true]);
        $headers = array('Content-Type: application/json');
        $response = $sender->send('Mb88Pay_API', $url, $method, $params, $headers);
        $result = json_decode($response, true);
        if ($result['message'] == 'succecss') {
            return [
                'type' => 1,
                'url' => $result['body']['linkWeb']
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
        $params = $request->all();
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
            ]);

            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);

            $info = json_decode($agentCashFlow->info, true);

            $this->checkSign(
                $info['MD5Key'],
                $params['orderUserName'],
                $params['order'],
                $params['currentMoney'],
                $params['md5']
            );

            $order = Order::where('order_no', $params['order'])->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            if ($order->status == 0) {
                $amount = $params['currentMoney'];
                if ($order->amount == $amount) {
                    $order->status = 1;
                } else {
                    //callback金額不符
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

    private function md5($info, $params)
    {
        $arr = [];
        foreach ($params as $k => $v) {
            if ($k != 'notifyUrl') {
                if ($k != 'bankId') {
                    $arr[] = $k . ':' . $v;
                }
            }
        }
        $arr[] = 'secretKey:' . $info['SecretKey'];
        return md5(join('', $arr));
    }

    private function checkSign($md5_key, $order_username, $order, $current_money, $sign)
    {
        $str = $md5_key . $order_username . $order . $current_money;
        $msg = strtoupper(md5($str));

        if ($sign !== $msg) {
            throw new Exception('sign error', 2002);
        }
    }
}
