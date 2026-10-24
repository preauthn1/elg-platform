<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Member;
use target\support\Models\Order;
use target\support\Models\OrderType;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use target\support\Models\Agent;

class DGPay extends CashFlowBase
{
    private $rules_callback = [
        'orderId' => 'required',
        'amount' => 'required|numeric',
        'status' => 'required',
    ];

    protected $currency = null;
    protected $payment_type = '';
    protected $provider_id = 0;
    protected $provider_type = 0;
    protected $version = 1;
    protected $extra_content = null;
    protected $default_email = 'user@example.invalid';

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
        $orderType = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $this->version = isset($info['VERSION']) ? $info['VERSION'] : 'V1';
        $this->currency = isset($info['Currency']) ? $info['Currency'] : '';
        $this->provider_id = isset($info['PROVIDER_ID']) ? $info['PROVIDER_ID'] : 0;
        $this->provider_type = isset($info['PROVIDER_TYPE']) ? $info['PROVIDER_TYPE'] : 0;
        $this->default_email = isset($info['DefaultEmail']) ? $info['DefaultEmail'] : '';
        if ($this->provider_id == 0 && $this->provider_type == 0) {
            $this->getProviderData($orderType);
        }

        $type = 0;
        $return_url = '';
        $url = $info['APIUrl'] . ($this->version == 'V2' ? '/v2/deposit' : '/deposit');
        $method = 'POST';
        if ($this->version == 'V2') {
            $this->extra_content = json_decode($order->extra_content);
        }
        //取得對應的provider_id & provider_type
        $requestData = $this->getRequestData($member, $orderType, $returnUrl);

        if ($this->version == 'V2') {
            $params = [
                'opCode' => $info['Merchant'],
                'orderId' => $order->order_no,
                'paymentType' => $info['PAYMENT_CODE'],
                'currency' => $info['Currency'],
                'amount' => $order->amount,
                'callbackUrl' => $callbackUrl,
                'redirectUrl' => $returnUrl,
                'reqDateTime' => date('Y-m-d H:i:s', strtotime("now +8 hours")),
                'sender' => json_encode($requestData, JSON_UNESCAPED_SLASHES),
            ];
        } else {
            $params = [
                'orderId' => $order->order_no,
                'providerId' => $this->provider_id,
                'providerType' => $this->provider_type,
                'currency' => $info['Currency'],
                'amount' => $order->amount,
                'callbackUrl' => $callbackUrl,
                'data' => json_encode($requestData, JSON_UNESCAPED_SLASHES),
                'opCode' => $info['Merchant'],
                'reqDateTime' => date('Y-m-d H:i:s', strtotime("now +8 hours"))
            ];
        }

        $params['securityToken'] = $this->makeSign($info, $params);

        Log::debug(get_class($this) . '_' . $info['Currency']. '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params
        ]);

        $sender = new VendorSend();
        $response = $sender->send(
            'DGPay_API',
            $url,
            $method,
            $params,
            ['Content-Type: application/x-www-form-urlencoded']
        );
        $responseData = json_decode($response, true);
        if (isset($responseData['code']) && $responseData['code'] == '0') {
            $order->save();
            if ($info['Currency'] == 'IDR' && $this->provider_id == 26 && $this->provider_type == 20) {
                //IDR - OVO request 成功后，会在app里直接跳出转账需要approve的notification
                $type = 4;
                $return_url = '';
            } else {
                $type = 1;
                $return_url = urldecode($responseData['paymentUrl']);
            }
        }
        
        return [
            'type' => $type,
            'url' => $return_url
        ];
    }

    /**
     * 三方回調
     * @param  Request  $request
     * @return CallbackResponse
     * @throws Exception
     * @throws Exception
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
            $order_no = $this->checkSign($params, $info);
            $order = Order::where('order_no', $order_no)->lockForUpdate()->first();
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            //三方單號
            $order->third_party_order_no = $params['orderId'];
            // DGPay 特例：允許首次失敗後重新回調為成功
            // 僅當訂單已失敗(status=2)且本次回調為成功(status=20)時允許重試
            if ($order->status != 0) {
                if (!($order->status == 2 && $params['status'] == "20")) {
                    throw new Exception('order status : ' . $order->status . ' not 0', 2002);
                }
            }

            /**
             * Expired = -20
             * Failed = -10
             * Pending = 0
             * Submit = 1
             * Quene = 2
             * Pending Verification = 5
             * Process = 10
             * Success = 20
             * */
            if ($params['status'] == "20") {  //Success = 20
                if ($order->amount == $params['amount']) {
                    $order->status = 1;
                }
            } elseif ($params['status'] == "-10") { //Failed
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
                        'code' => "0",
                        'description' => "Success"
                    ]),
                    $order
                );
            } else {
                return new CallbackResponse(
                    0,
                    json_encode([
                        'code' => "0",
                        'description' => "Success"
                    ]),
                    $order
                );
            }
        } catch (Exception $exception) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $exception->getMessage()
            ]);
            return new CallbackResponse(
                0,
                json_encode([
                    'code' => "-100",
                    'description' =>"Error"
                ]),
                null
            );
        }
    }

    private function makeSign($info, $params)
    {
        if ($this->version == 'V2') {
            $signMsg = $params['opCode'] .
                $params['orderId'] .
                $params['paymentType'] .
                $params['currency'] .
                $params['amount'] .
                $params['callbackUrl'] .
                $params['redirectUrl'] .
                $params['reqDateTime'] .
                $params['sender'] .
                $info['MerchantKey'];
        } else {
            $signMsg = $params['orderId'] .
                $params['providerId'] .
                $params['providerType'] .
                $params['currency'] .
                $params['amount'] .
                $params['reqDateTime'] .
                $params['opCode'] .
                $info['MerchantKey'];
        }
        $sign = strtolower(md5($signMsg));
        Log::debug(get_class($this) . '_' . $params['currency'] . '_sign', [
            'sign_string' => $signMsg,
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
        $orderId = $params['orderId'];
        if (substr_count($orderId, '@') == 1) {
            $orderId_array = explode("@", $orderId);
            $order_no = $orderId_array[0];
            $Merchant = $orderId_array[1];
            if ($Merchant == $info['Merchant']) {
                return $order_no;
            } else {
                throw new Exception('sign error', 2002);
            }
        } else {
            throw new Exception('third party order id format error', 2002);
        }
    }

    private function getProviderData($orderType)
    {
        if ($this->currency == 'MYR') {
            switch ($orderType->key) {
                case 'DUI':  //Duit Now
                    $this->provider_id = 17;
                    $this->provider_type = 11;
                    break;
                case 'TNG':  //Touch’n Go
                    $this->provider_id = 17;
                    $this->provider_type = 12;
                    break;
                case 'BOT':  //Boost
                    $this->provider_id = 17;
                    $this->provider_type = 13;
                    break;
                case 'GPY':  //GrabPay
                    $this->provider_id = 17;
                    $this->provider_type = 14;
                    break;
                case 'SPY':  //ShopeePay
                    $this->provider_id = 17;
                    $this->provider_type = 15;
                    break;
                case 'MAE':  //Maybank QR
                    $this->provider_id = 17;
                    $this->provider_type = 16;
                    break;
            }
        }
    }
    
    /**
     * @param Member|Agent $member
     * @param OrderType $orderType
     * @param string $return_url
     * @return array
     */
    private function getRequestData($member, $orderType, $return_url)
    {
        $data = array();
        switch ($this->currency) {
            case 'MYR':
                if ($this->provider_id == 5 && $this->provider_type == 10) {
                    //Route 1
                    $data['fromBankCode'] = $orderType->key;
                } elseif ($this->provider_id == 17) {
                    //E-Wallet (Duit Now | Touch’n Go | Boost | GrabPay | ShopeePay | Maybank QR)
                    $data['username'] = $member->username;
                    $data['code'] = $orderType->key;
                }
                $data['redirectUrl'] = $return_url;
                break;
            case 'SGD':
                if ($this->version == 'V2') {
                    $data['senderFirstName'] = $this->extra_content->first_name;
                    $data['senderLastName'] = $this->extra_content->last_name;
                    $data['senderNo'] = $this->extra_content->bank_number;
                    $data['bankCode'] = $orderType->key;
                    $data['username'] = $member->username;
                    $data['phone'] = $member->mobile;
                } else {
                    if ($this->provider_id == 5 && $this->provider_type == 10) {
                        //Route 1
                        $data['fromBankCode'] = $orderType->key;
                        $data['redirectUrl'] = $return_url;
                    } elseif ($this->provider_id == 12 && $this->provider_type == 20) {
                        //Route 2
                        $data['name'] = $member->username;
                        $data['redirectUrl'] = $return_url;
                        $data['bankCode'] = $orderType->key;
                    }
                }
                break;
            case 'THB':
                if ($this->provider_id == 11 && $this->provider_type == 10) {
                    //Qris
                    $data['name'] = $member->username;
                    $data['email'] = ($member->email != '') ? $member->email : $this->default_email;
                    $data['phone'] = $member->mobile;
                    $data['language'] = 'EN';
                }
                break;
            case 'IDR':
                if ($this->provider_type == 10) {
                    //Qris
                    $data['accName'] = $member->username;
                } elseif ($this->provider_type == 15) {
                    //Virtual Account
                    $data['accName'] = $member->username;
                    $data['bankCode'] = $orderType->key;
                } else {
                    //OVO / Dana / ShopeePay
                    $data['accName'] = $member->username;
                    $data['redirectUrl'] = $return_url;
                    $data['phoneNum'] = $member->mobile;
                }
                break;
        }
        return $data;
    }
}
