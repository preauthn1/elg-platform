<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Models\OrderType;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AutoBankPay extends CashFlowBase
{
    private $rules_callback = [
        'username' => 'required',
        'password' => 'required',
        'request_id' => 'required',
        'bank' => 'required',
        'status' => 'required',
        'amount' => 'required',
        'message' => 'required',
        'signature' => 'required'
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
        $request = request();
        $order_type = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['APIUrl'] . '/apiautobank/api/requestPayment';
        $method = 'POST';
        $params = [
            'username' => $info['Username'],
            'password' => $info['Password'],
            'bank_code' => $request->bank_code,
            'money' => $order->amount,
            'request_id' => $order->order_no
        ];

        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params
        ]);
        $sender = new VendorSend(['json_post' => true]);
        $headers = array('Content-Type: application/json');
        $response = $sender->send('AutoBankPay_API', $url, $method, $params, $headers);
        $response_data = json_decode($response, true);
        /*
         * {
         *  "errorCode":"1",
         *  "redirectLink":"https:\/\/pay.nghiensex.pro\/07a8c124-5768-46c6-baf6-93254578778e",
         *  "banknumber":"19036900511022",
         *  "bankname":"TRAN THU HANG",
         *  "content":"057567",
         *  "timeactive":"600",
         *  "message":"Success"
         * }
         * */
        if ($response_data['errorCode'] == '1') {
            $payment_link = $response_data['redirectLink'];     //Payment link
            $order->third_party_order_no = $response_data['content'];
            $order->save();
            /*
            $message = $response_data['message'];
            $bank_number = $response_data['banknumber'];        //Bank account number
            $bank_name = $response_data['bankname'];            //Name of the bank
            $time_active = $response_data['timeactive'];        //expire time of that link ~600s
            */
            return [
                'type' => 1,
                'url' => $payment_link
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
            //因為AutoBank回傳為txt, 所以直接針對content做json decode
            if (count($params) == 0) {
                $params = json_decode($request->getContent(), true);
            } else {
                $this->validate($request, $this->rules_callback);
            }

            $order_no = $params['request_id'];
            $order = Order::where('order_no', $order_no)->lockForUpdate()->first();
            $agentCashFlow = $this->getAgentCashFlow($order->agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            if ($this->checkSign($params, $info)) {
                DB::rollback();
                throw new Exception('sign error', 2002);
            }

            if ($order === null) {
                DB::rollback();
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'ContentType' => $request->getContentType(),
                'url' => $request->fullUrl(),
                'params' => $params,
                'content'=> $request->getContent()
            ]);

            if ($order->status == 0) {
                if ($params['status'] == '1') { //1:Success, -1:transaction fail
                    $amount = $this->getAmount($params['amount']);
                    if ($order->amount == $amount) {
                        $order->status = 1;
                    } else {
                        $order->status = 2;
                    }
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode($params);
                if (!$order->save()) {
                    DB::rollback();
                    throw new Exception('system error', 2002);
                }

                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(
                        1,
                        json_encode([
                            'status' => 1,
                            'message' => 'success'
                        ]),
                        $order
                    );
                } else {
                    return new CallbackResponse(
                        0,
                        json_encode([
                            'status' => 0,
                            'message' => 'fail'
                        ]),
                        null
                    );
                }
            } else {
                throw new Exception('order status not 0', 2002);
            }
        } catch (Exception $e) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'ContentType' => $request->getContentType(),
                'url' => $request->fullUrl(),
                'params' => $params,
                'content'=> $request->getContent(),
                'exception' => $e->getMessage()
            ]);
            return new CallbackResponse(
                0,
                json_encode([
                    'status' => 0,
                    'message' => 'fail'
                ]),
                null
            );
        }
    }

    /**
     * @param $params
     * @param $info
     * @throws Exception
     */
    private function checkSign($params, $info)
    {
        $signMsg = strtolower($params['signature']);
        $msg = $params['username'] .
            $params['password'] .
            $params['request_id'] .
            $params['amount'] .
            $params['bank'] .
            $params['message'] .
            $info['SecretKey'];

        return ($signMsg !== strtolower(md5($msg))) ? true : false;
    }
}
