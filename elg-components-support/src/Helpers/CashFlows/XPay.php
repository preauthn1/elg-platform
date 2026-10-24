<?php

namespace target\support\Helpers\CashFlows;

use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Member;
use target\support\Models\Order;
use target\support\Models\OrderType;
use target\support\Helpers\Utils\XPayDataEncrypt;
use target\support\Models\Agent;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class XPay extends CashFlowBase
{
    private $rules_callback = [
        'Data' => 'required',
        'EncryptText' => 'required'
    ];

    /**
     * @param AgentCashFlow $agentCashFlow
     * @param Order $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @param Request $request
     * @return array [
     *      "type" : 1  // 0: 失敗, 1: 轉址, 2: QRCode, 3: form post
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
        $info = json_decode($agentCashFlow->info, false);
        if (is_object($info)) {
            $url = $info->APIUrl . 'payment.php';
            $method = 'GET';

            $data = [
                'MerchantID' => $info->MerchantId,
                'CustID' => $member->username,
                'Curr' => $info->Currency,
                'Amount' => $order->amount,
                'RefID' => $order->order_no,
                'TransTime' => '',
                'ReturnURL' => $returnUrl,
                'RequestURL' => $callbackUrl,
                'BankCode' => '' //空值是直接到三方畫面選銀行
                //'CustIP' => $order->ip,
                //'Remarks' => '',
            ];
            $params = $this->makeRequestBody($info, $data, $order->remark);
            Log::debug(
                get_class($this) . '_' . $order->order_no,
                [
                    'url' => $url . '?' . http_build_query($params),
                    'method' => $method,
                    'params' => $params,
                    'requestBody' => ''
                ]
            );
            return [
                'type' => 1,
                'url' => $url . '?' . http_build_query($params)
            ];
        } else {
            return ['type' => 0, 'url' => ''];
        }
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
            $info = json_decode($agentCashFlow->info, false);
            parse_str($this->decryptData($request), $data);
            $this->checkSign($info, $data);
            $trans_id = isset($data['TransID']) ? $data['TransID'] : '';
            $validation_key = isset($data['ValidationKey']) ? $data['ValidationKey'] : '';
            if (isset($params['EncryptText'])
                && isset($data['EncryptText'])
                && $params['EncryptText'] == $data['EncryptText']) {
                $order = $this->getOrder($data['RefID']);

                Log::debug(
                    get_class($this) . '_callback',
                    [
                        'method' => $_SERVER['REQUEST_METHOD'],
                        'params' => $params,
                    ]
                );

                if ($order->status == 0) {
                    if ($data['Status'] == '002') {
                        $amount = $this->getAmount($params['Amount']);
                        if ($order->amount == $amount) {
                            $order->status = 1;
                        } else {
                            //callback金額不符
                            $order->status = 2;
                        }
                    } elseif ($data['Status'] == '111') {
                        $order->status = 2;
                    }
                    $order->remark = json_encode($data);
                    if (!$order->save()) {
                        throw new Exception('system error', 2002);
                    }
                    DB::commit();
                    if ($order->status == 1) {
                        return new CallbackResponse(1, $trans_id . '||'. $validation_key, $order);
                    } else {
                        return new CallbackResponse(0, $trans_id . '||'. $validation_key, null);
                    }
                } else {
                    throw new Exception('order status not 0', 2002);
                }
            } else {
                return new CallbackResponse(0, $trans_id . '||'. $validation_key, null);
            }
        } catch (Exception $exception) {
            DB::rollback();
            Log::error(
                get_class($this) . '_callback',
                [
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'url' => $request->fullUrl(),
                    'params' => $params,
                    'exception' => $exception->getMessage()
                ]
            );
            return new CallbackResponse(0, 'failure', null);
        }
    }

    private function makeRequestBody($info, $params, $remark = '')
    {
        $param = array();
        $x_pay_encrypt = new XPayDataEncrypt();

        $encrypt_data = '';
        $encrypt_text = $info->MerchantKey . ':';
        foreach ($params as $key => $value) {
            $encrypt_data .= '&' . $key . '=' . $value;
            $encrypt_text .= $value . ',';
        }
        $encrypt_data = substr($encrypt_data, 1);
        $encrypt_text .= $remark;

        $param['Data'] = $x_pay_encrypt->encryptData($encrypt_data);
        $param['Remarks'] = $remark;
        $param['EncryptText'] = strtoupper(md5(utf8_encode($encrypt_text)));
        return $param;
    }

    private function getOrder($order_id)
    {
        $order = Order::where('order_no', $order_id)->lockForUpdate()->first();
        if ($order === null) {
            throw new Exception('can not find order', 2002);
        }
        return $order;
    }

    /**
     * 解析异步通知Data
     */
    private function decryptData(Request $request)
    {
        $x_pay_encrypt = new XPayDataEncrypt();
        return $x_pay_encrypt->decryptData($request['Data']);
    }

        /**
         * @param $info
         * @param $data
         * @return boolean
         * @throws Exception
         */
    private function checkSign($info, $data)
    {
        $str = $info->MerchantKey . ':' .
            $data['RefID'] . ',' .
            $data['Curr'] . ',' .
            $data['Amount'] . ',' .
            $data['Status'] . ',' .
            $data['TransID'] . ',' .
            $data['ValidationKey'];
        $sign = strtoupper(md5($str));
        if ($sign !== $data['EncryptText']) {
            throw new Exception('sign error', 2002);
        }
    }
}
