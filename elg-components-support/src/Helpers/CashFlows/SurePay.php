<?php
namespace target\support\Helpers\CashFlows;

use target\support\Models\Agent;
use Illuminate\Http\Request;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Member;
use target\support\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class SurePay extends CashFlowBase
{
    private $rules_callback = [
        'merchant' => 'required',
        'amount' => 'required',
        'status' => 'required',
        'trxno' => 'required'
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

        $url = $info['APIUrl'];
        $method = 'POST';
        $params = [
            'merchant' => $info['MerchantID'],
            'amount' => $order->amount,
            'refid' => $order->order_no,
            'token' => '',
            'customer' => $member->username,
            'currency' => $info['Currency'],
            'contractname' => $info['ContractName'],
            'clientip' => $order->ip,
            'failed_return_url' => $returnUrl,
            'return_url' => $returnUrl,
            'post_url' => $callbackUrl
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
            '<script>window.onload = function(){document.forms[0].submit();}</script>' .
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
            $this->validate($request, $this->rules_callback);
            $agentCashFlow = $this->getAgentCashFlow($agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            $this->checkToken($params, $info);

            $order = Order::where('order_no', $params['refid'])->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            //三方單號
            $order->third_party_order_no = $params['trxno'];
            if ($order->status == 0) {
                if ($params['status'] == '1') {
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
        } catch (Exception $e) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $e->getMessage()
            ]);
            return new CallbackResponse(0, "failure", null);
        }
    }

    private function makeRequestBody($info, $params)
    {
        $signMsg = $info['MerchantID'] .
            $params['amount'] .
            $params['refid'] .
            $params['customer'] .
            $info['ApiKey'] .
            $info['Currency'] .
            $params['clientip'];
        $params['token'] = strtolower(md5($signMsg));
        return $params;
    }

    /**
     * @param $params
     * @param $info
     * @throws Exception
     */
    private function checkToken($params, $info)
    {
        $token_msg = $params['token'];
        $msg = $info['MerchantID'] . $params['amount'] . $params['status'] . $info['CallBackApiKey'] . $params['trxno'];
        $token = [REDACTED];
        if ($token_msg !== $token) {
            Log::debug(get_class($this) . '_checkSign', [
                'check_string' => $token_msg,
                'sign' => $token,
                'result' => ($token_msg !== $token),
                'encryption_string' => $msg
            ]);

            throw new Exception('sign error', 2002);
        }
    }
}
