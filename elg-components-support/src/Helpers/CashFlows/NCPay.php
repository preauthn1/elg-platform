<?php
namespace target\support\Helpers\CashFlows;

use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Models\OrderType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NCPay extends CashFlowBase
{
    // 回調參數策略
    private $rules_callback = [
        'apikey' => 'required',
        'action' => 'required',
        'orderid' => 'required',
        'amount' => 'required',
        'type' => 'required',
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
     *
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        $order_type = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['APIUrl'] . 'V1/Merchant/Deposit';
        $method = 'POST';
        $params = [
            'apikey' => $info['APIKey'],
            'bank' => $order_type->key,
            'orderid' => $order->order_no,
            'amount' => $order->amount,
            'timestamp' => time()
        ];
        $user = rand(10000, 99999) . rand(10000, 99999);
        $sign = $this->makeSign($info, $user, json_encode($params));
        $url .= '?user=' . $user . '&sign=' . $sign;

        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
        ]);
        $sender = new VendorSend(['json_post'=>true]);
        $response = $sender->send('MTPay_API', $url, $method, $params, ['Content-Type: application/json']);
        $responseData = json_decode($response, true);
        if ($responseData['status'] == 'ok' && $responseData['data']['orderid']) {
            $order->third_party_order_no = $responseData['data']['index'];
            $order->save();
            return [
                'type'=>1,
                'url'=>$responseData['data']['redirect']
            ];
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
        try {
            $params = $request->post();
            $content = $request->getContent();
            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'content'=> $content
            ]);
            return;

            // NCPay 暫時不接受回調先log回調資料就好, 下方代碼事實上己經測試成功.
            // 因為在RD-studio-01 有job在主動查單, 擔心重複上分的問題.

            $this->validate($request->all(), $this->rules_callback);
            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);

            $info = json_decode($agentCashFlow->info, true);
            $this->checkSign($info, $request, $content, $params);

            $order = Order::where('order_no', $params['orderid'])->first();
            if ($order === null) {
                throw new \Exception('can not find order', 2002);
            }

            if ($order->status == 0) {
                if ($params['action'] == 'ordersuccess') {
                    $amount = $this->getAmount($params['amount']);
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
                    throw new \Exception('system error', 2002);
                }
                if ($order->status == 1) {
                    return new CallbackResponse(1, json_encode([
                        'status' => 'ok',
                        'error' => null,
                        'code' => ''
                    ]), $order);
                } else {
                    return new CallbackResponse(0, json_encode([
                        'status' => 'failure',
                        'error' => null,
                        'code' => ''
                    ]), null);
                }
            } else {
                return new CallbackResponse(0, json_encode([
                    'status' => 'failure',
                    'error' => null,
                    'code' => ''
                ]), null);
            }
        } catch (\Exception $e) {
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $e->getMessage(),
            ]);
            return new CallbackResponse(0, "failure", null);
        }
    }

    private function makeSign($info, $user, $content)
    {
        return sha1($user . $content . $info['APISecret']);
    }

    /**
     * @param $info
     * @param $request
     * @param $content
     * @param $params
     * @throws Exception
     */
    private function checkSign($info, $request, $content, $params)
    {
        $sign = $this->makeSign($info, $request->user, $content);
        if ($params['apikey'] != $info['APIKey'] || $sign != $request->sign) {
            throw new Exception('sign error', 2002);
        }
    }
}
