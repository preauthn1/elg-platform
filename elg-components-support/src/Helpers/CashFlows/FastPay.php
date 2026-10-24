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

class FastPay extends CashFlowBase
{
    private $rules_callback = [
        'trans_time' => 'required',
        'ref_id' => 'required',
        'currency_code' => 'required',
        'ticket_id' => 'required',
        'given_amount' => 'required',
        'requested_amount' => 'required',
        'trans_status' => 'required',
        'security_token' => 'required'
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
        $url = $info['APIUrl'] . '/requestDeposit.shtml';
        $method = 'POST';
        $params = [
            'merchant_code' => $info['MerchantCode'],   //商家代码; ABCD0001
            'username' => $member->username,            //玩家用户名; Player123
            'ip_address' => $order->ip,                 //IP 地址; 0.0.0.0
            'currency_code' => $info['CurrencyCode'],   //货币代码; SGD
            'trans_amount' => $order->amount,           //交易数额; 10.00
            'ref_id' => $order->order_no,               //商家生成的参考; ID 20201928392123
            'trans_time' => date('Y-m-d H:i:s', strtotime("now +8 hours")), //时间日期
            'return_url' => $returnUrl,                 //成功交易后重定向客户的 URL
            'request_url' => $callbackUrl,              //成功交易后重定向通知商家的 URL
            'bank_code' => $order_type['key'],          //银行代码
        ];

        $requestBody = $this->makeRequestBody($info, $params);
        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
            'requestBody' => $requestBody
        ]);

        $html = '<html>' .
            '<head></head>' .
            '<body>redirect...' .
            '<form id="form" method="' . $method . '"  action="' . $url . '">';
        foreach ($requestBody as $key => $val) {
            $html .= '<input type="hidden" name="' . $key . '" value="' . $val . '" />';
        }
        $html .= '</form>' .
            '<script>window.onload = function(){document.forms["form"].submit();}</script>' .
            '</body>' .
            '</html>';

        return [
            'type' => 3,
            'url' => '',
            'html' => $html
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
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);

            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            $this->checkSign($params, $info);

            $order = Order::where('order_no', $request->ref_id)->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            $order->third_party_order_no = $request['ticket_id'];
            if ($order->status == 0) {
                if ($request['trans_status'] == '20') {
                    $amount = $this->getAmount($request['requested_amount']);
                    if ($order->amount == $amount) {
                        $order->status = 1;
                    } else {
                        //callback金額不符
                        $order->status = 2;
                    }
                } elseif ($request['trans_status'] == '0' || $request['trans_status'] == '10') {
                    $order->status = 0;
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode([
                    'trans_time' => $params['trans_time'],
                    'ref_id' => $params['ref_id'],
                    'currency_code' => $params['currency_code'],
                    'ticket_id' => $params['ticket_id'],
                    'given_amount' => $params['given_amount'],
                    'requested_amount' => $params['requested_amount'],
                    'trans_status' => $params['trans_status'],
                    'security_token' => $params['security_token']
                ]);
                if (!$order->save()) {
                    throw new Exception('system error', 2002);
                }
                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(
                        1,
                        json_encode([
                            'status' => 0,
                            'ticket_id' => $request['ticket_id']
                        ]),
                        $order
                    );
                } else {
                    return new CallbackResponse(
                        0,
                        json_encode([
                            'status' => -1,
                            'ticket_id' => $request['ticket_id']
                        ]),
                        $order
                    );
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
                'exception' => $e->getMessage()
            ]);
            return new CallbackResponse(
                0,
                json_encode([
                    'status' => -1,
                    'ticket_id' => $request['ticket_id']
                ]),
                null
            );
        }
    }

    private function makeRequestBody($info, $params)
    {
        $signMsg = '';
        foreach ($params as $v) {
            $signMsg .= $v;
        }
        $signMsg .= $info['SecretKey'];

        $params['security_token'] = strtolower(md5($signMsg));
        return $params;
    }

    /**
     * @param $params
     * @param $info
     * @throws Exception
     */
    private function checkSign($params, $info)
    {
        $signMsg = $params['security_token'];
        $msg = $info['MerchantCode'] .
            $params['trans_time'] .
            $params['ref_id'] .
            $params['currency_code'] .
            $params['ticket_id'] .
            $params['given_amount'] .
            $params['requested_amount'] .
            $params['trans_status'] .
            $info['SecretKey'];
        $ticket_id = $params['ticket_id'];

        if ($signMsg !== strtolower(md5($msg))) {
            throw new Exception('sign error', 2002);
        }
    }
}
