<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Models\Agent;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Member;
use target\support\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TouchngoPay extends CashFlowBase
{
    private $rules_callback = [
        'DepositId' => 'required',
        'Result' => 'required',
        'MerchantCode' => 'required',
        'TransNum' => 'required',
        'Amount' => 'required',
        'CheckString' => 'required'
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
        if ($order->member_id > 0) {
            $member = Member::find($order->member_id);
        } else {
            $member = Agent::find($order->agent_id);
        }
        $info = json_decode($agentCashFlow->info, true);

        $url = $info['api_url'] . '/Deposit.aspx';
        $method = 'POST';
        $params = [
            'MerchantCode' => $info['merchant_code'],
            'TransNum' => $order->order_no,
            'Currency' => $info['currency'],
            'Amount' => $order->amount,
            'PaymentDesc' => '',
            'FirstName' => $member->username,
            'LastName' => '',
            'EmailAddress' => '',
            'PhoneNum' => '',
            'Address' => '',
            'City' => '',
            'State' => '',
            'Postcode' => '',
            'Country' => $info['country'],
            'MerchantRemark' => '',
            'RedirectUrl' => $returnUrl
        ];

        $requestBody = $this->makeRequestBody($info, $params);

        $html = '<html>' .
            '<head></head>' .
            '<body>redirect...' .
            '<form method="' . $method . '"  action="' . $url . '">';
        foreach ($requestBody as $key => $val) {
            $html .= '<input type="hidden" name="' . $key . '" value="' . $val . '" />';
        }
        $html .= '</form>' .
            '<script>
                window.onload = function(){
                    setTimeout(() => {
                        document.forms[0].submit();
                    }, 500);
                };
            </script>
            ' .
            '</body>' .
            '</html>';

        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
            'requestBody' => $requestBody,
            'html' => $html
        ]);

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
        $agent_cash_flow_id = $request->agent_cash_flow_id;
        DB::beginTransaction();
        try {
            //因為PayEssence回傳為xml, 所以直接針對content做json decode
            if (count($params) == 0) {
                $params = json_decode($request->getContent(), true);
            } else {
                $this->validate($request, $this->rules_callback);
            }

            $agentCashFlow = $this->getAgentCashFlow($agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            $this->checkSign($params, $info);

            $order = Order::where('order_no', $params['TransNum'])->lockForUpdate()->first();
            if ($order === null) {
                return new CallbackResponse(
                    0,
                    json_encode([
                        'status' => -1,
                        'ticket_id' => $params['TransNum']
                    ]),
                    null
                );
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            //三方單號
            $order->third_party_order_no = $params['DepositId'];
            if ($order->status == 0) {
                if ($params['Result'] == '10001') {
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
                    return new CallbackResponse(
                        1,
                        json_encode([
                            'status' => 0,
                            'ticket_id' => $params['TransNum']
                        ]),
                        $order
                    );
                } else {
                    return new CallbackResponse(
                        0,
                        json_encode([
                            'status' => -1,
                            'ticket_id' => $params['TransNum']
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
                    'ticket_id' => $params['TransNum']
                ]),
                null
            );
        }
    }

    private function makeRequestBody($info, $params)
    {
        $signMsg = $info['merchant_code'] .
            $info['secret_key'] .
            $params['TransNum'] .
            $info['currency'] .
            $params['Amount'];
        $params['Signature'] = $this->makeSha256($signMsg);
        return $params;
    }

    private function makeSha256($signMsg)
    {
        $hash = hash('sha256', $signMsg, true);
        return bin2hex($hash);
    }

    /**
     * @param $params
     * @param $info
     * @throws Exception
     */
    private function checkSign($params, $info)
    {
        $sign_msg = $params['CheckString'];
        $msg = $info['merchant_code'] . $info['secret_key'] . $params['TransNum'];
        $sign = $this->makeSha256($msg);
        if ($sign_msg !== $sign) {
            Log::debug(get_class($this) . '_checkSign', [
                'check_string' => $sign_msg,
                'sign' => $sign,
                'result' => ($sign_msg !== $sign),
                'encryption_string' => $msg
            ]);

            throw new Exception('sign error', 2002);
        }

        // Reverse of (Merchant Transaction No. + Merchant Code) + Merchant Secret Key
        $sign_msg2 = $params['CheckString2'];
        $msg2 = strrev($params['TransNum'] . $info['merchant_code']) . $info['secret_key'];
        $sign2 = $this->makeSha256($msg2);
        if ($sign_msg2 !== $sign2) {
            Log::debug(get_class($this) . '_checkSign2', [
                'check_string2' => $sign_msg2,
                'sign2' => $sign2,
                'result' => ($sign_msg2 !== $sign2),
                'encryption_string' => $msg2
            ]);
            throw new Exception('sign 2 error', 2002);
        }
    }
}
