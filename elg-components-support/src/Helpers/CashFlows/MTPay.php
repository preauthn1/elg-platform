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

class MTPay extends CashFlowBase
{
    private $rules_callback = [
        'signMsg' => 'required',
        'version' => 'required',
        'notifyTime' => 'required',
        'content' => 'required'
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
            $username = $member->username;
            $mobile = $member->mobile;
            $realName = $member->first_name;
        } else {
            $agent = Agent::find($order->agent_id);
            $username = $agent->username;
            $mobile = $agent->mobile;
            $realName = $agent->real_name;
        }
        $order_type = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['APIUrl'];
        $method = 'POST';
        $params = [
            'amount' => $order->amount,
            'orderNo' => $order->order_no,
            'orderTime' => date('YmdHis', strtotime("now +8 hours")),
            'returnUrl' => $returnUrl,
            'notifyUrl' => $callbackUrl,
            'memberCode' => $username,
            'mobile' => $mobile,
        ];

        if (isset($info['disabled_member_code'])
         && $info['disabled_member_code'] == 1) {
            unset($params['memberCode']);
        }

        //memberCode改用真實姓名
        if (isset($info['useRealName']) &&  $info['useRealName'] == 1) {
            if (trim($realName) != '') {
                $params['memberCode'] = trim($realName);
            }
        }

        if (isset($info['hasBankCode']) && $info['hasBankCode'] == 1) {
            $params['paymentCode'] = $info['paymentCode'];
            $params['bankCode'] = $order_type['key'];
        } elseif (isset($info['hasPaymentCode']) && $info['hasPaymentCode'] == 1) {
            $params['paymentCode'] = $order_type['key'];
        }
        $requestBody = $this->makeRequestBody($info, $params);
        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
            'requestBody' => $requestBody,
            'info' => $info,
        ]);
        $sender = new VendorSend();
        $response = $sender->send('MTPay_API', $url, $method, $requestBody);
        $responseData = json_decode($response, true);
        if ($responseData['resCode'] == '000') {
            $result = json_decode(base64_decode($responseData['response']), true);
            if ($result['code'] == '0000') {
                // 若有三方單號，則更新三方單號，不一定會有
                if (empty($order->third_party_order_no)) {
                    $order->third_party_order_no = $result['gbpOrderNo'] ?? '';
                }
                $order->save();
                return [
                    'type'=>1,
                    'url'=>$result['resultUrl']
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
        $params = $request->all();
        $content = json_decode(base64_decode($params['content']), true);
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);
            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);

            $info = json_decode($agentCashFlow->info, true);
            $this->checkSign(
                $params['content'],
                $params['notifyTime'],
                $params['version'],
                $info['MerchantKey'],
                $params['signMsg']
            );

            $order = Order::where('order_no', $content['orderNo'])->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'content' => $content
            ]);

            if ($order->status == 0) {
                if (strtoupper($content['status']) == 'SUCCESS') {
                    $amount = $this->getAmount($content['amount']);
                    if ($order->amount == $amount) {
                        $order->status = 1;
                    } else {
                        //callback金額不符
                        $order->status = 2;
                    }
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode([
                    'content' => $content
                ]);

                //額外內容
                if (isset($content['extraContent']) && $content['extraContent'] != '') {
                    $extra_content_json = json_decode(base64_decode($content['extraContent']), true);
                    $extra_content = array();
                    if (is_array($extra_content_json)) {
                        $extra_content['from_account_name'] = $extra_content_json['FromAccountName'];
                    }
                    $order->extra_content = json_encode($extra_content);
                }

                // 因為allpay收銀台，建單的時候沒有給三方單號，所以在回調時要把三方單號更新上去
                // 如果三方單號已經有值就不更新
                if (!trim($order->third_party_order_no)) {
                    $order->third_party_order_no = $content['gbpOrderNo'];
                }
                if (!$order->save()) {
                    throw new Exception('system error', 2002);
                }
                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(1, "OK", $order);
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
                'content' => $content,
                'exception' => $e->getMessage(),
            ]);
            return new CallbackResponse(0, "failure", null);
        }
    }

    private function makeRequestBody($info, $params)
    {
        $request = base64_encode(json_encode($params));
        $requestBody = [
            'version' => '1.0',
            'merchantNo' => $info['MerchantNo'],
            'request' => $request
        ];
        ksort($requestBody);
        $signMsg = '';
        foreach ($requestBody as $k => $v) {
            $signMsg .= $k.':'.$v.'&';
        }
        $signMsg .= 'key:'.$info['MerchantKey'];

        $requestBody['signMsg'] = strtoupper(md5($signMsg));
        return $requestBody;
    }

    /**
     * @param $content
     * @param $notifyTime
     * @param $version
     * @param $key
     * @param $signMsg
     * @throws Exception
     */
    private function checkSign($content, $notifyTime, $version, $key, $signMsg)
    {
        $msg = sprintf("content:%s&notifyTime:%s&version:%s&key:%s", $content, $notifyTime, $version, $key);
        if ($signMsg !== strtoupper(md5($msg))) {
            throw new Exception('sign error', 2002);
        }
    }
}
