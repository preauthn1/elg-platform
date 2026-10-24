<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class TelcoPay extends CashFlowBase
{
    private $rules_callback = [
        'status' => 'required',
        'desc' => 'required',
        'amount' => 'required',
        'real_amount' => 'required',
        'trans_id' => 'required',
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
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['APIUrl'] . '/apitelco/api/card/charge';
        $method = 'POST';
        $params = [
            'trans_id' => $order->order_no,     //Transaction Id (unique); string
            'telco' => $request->telco,         //VTT, VNP, VMS (Viettel, Vinaphone, Mobifone); string
            'serial' => trim($request->serial),       //Serial number; string
            'amount' => $order->amount,         //Card amount; int
            'partner_id' => $info['PartnerId']   //Admin provide (at the top of the document); int
        ];

        $requestBody = $this->makeRequestBody($info, $params);
        $requestBody['code'] = trim($request->code); //Code number; string
        Log::debug(get_class($this) . '_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
            'requestBody' => $requestBody
        ]);
        //新增傳送資訊
        $remark = array();
        $remark['request'] = $requestBody;
        $order->remark = json_encode($remark);

        $code = 998;    //前端會維持在原頁
        $message = __('param.fail');
        if ($order->save()) {
            if ($request->telco != '' && $request->serial != '' && $request->code != '') {
                $sender = new VendorSend(['json_post' => true]);
                $headers = array('Content-Type: application/json');
                $response = $sender->send('TelcoPay_API', $url, $method, $requestBody, $headers);
                $response_data = json_decode($response, true);
                /*
                 * status
                 * 0 Fail
                 * 1 Success
                 * 3 Parameter invalid 參數無效
                 * 4 Signature invalid 簽名無效
                 * 5 Transaction invalid 交易無效
                 * 6 Successful in another transaction 在另一筆交易中成功
                 * 7 Sent too many times for 1 series 相同序號發送次數過多
                 * 8 Serial invalid 序列號無效
                 * 10 Not foud transaction 未找到交易
                 * 11 Timeout 超時
                 * 100 Card has been accepted and waiting to receive the result (Api Callback) 卡已被接受並等待接收結果（Api回調）
                 * 200 Deny card 拒絕卡
                 */
                if (is_array($response_data) && isset($response_data['status'])) {
                    $message = '[ER-' . $response_data['status'] . '] ' . __('param.fail');
                    if ($response_data['status'] == 1) {
                        // 呼叫external直接上分
                        $req = array();
                        $req['status'] = $response_data['status'];
                        $req['desc'] = $response_data['desc'];
                        $req['amount'] = $response_data['amount'];
                        $req['real_amount'] = $response_data['real_amount'];
                        $req['trans_id'] = $order->order_no;
                        $req['serial'] = $request->serial;
                        $str = $req['status'] . '|' . $req['trans_id'] . '|' . $req['serial'] . '|' .
                            $info['PartnerId'] . '|' . $info['PrivateKey'];
                        $req['signature'] = md5($str);
                        $ext_res = $sender->send('Call_External_API', $callbackUrl, $method, $req, $headers);
                        $ext_res_data = json_decode($ext_res, true);
                        if ($ext_res_data['status'] == 0) {
                            $code = 999;    //前端會返回支付頁
                            $message = __('param.success');
                        }
                    } elseif ($response_data['status'] == 100) {
                        // 等待三方callback
                        $code = 999;    //前端會返回支付頁
                        $message = __('param.success');
                    }
                }
            }
        }
        return [
            'type' => 9, //前端會依message做alert
            'code' => $code,  //不能為1000
            'message' => $message
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

            $order_no = $request->trans_id;
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
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            if ($order->status == 0) {
                if ($request['status'] == 1) {
                    //依三方回調實際金額與玩家填寫金額, 以小的為主
                    $real_amount = ($request['real_amount'] <= $order->amount)
                        ? $request['real_amount']
                        : $order->amount;
                    $order->amount = $real_amount;
                    $order->point = $real_amount * $agentCashFlow->rate;
                    $order->status = 1;
                } else {
                    $order->status = 2;
                }
                $data = array();
                $data['status'] = $params['status'];
                $data['desc'] = $params['desc'];
                $data['amount'] = $params['amount'];
                $data['real_amount'] = $params['real_amount'];
                $data['trans_id'] = $params['trans_id'];
                $data['serial'] = $params['serial'];
                $data['signature'] = $params['signature'];
                $remark = json_decode($order->remark, true);
                $remark['response'] = $data;
                $order->remark = json_encode($remark);
                if (!$order->save()) {
                    DB::rollback();
                    throw new Exception('system error', 2002);
                }
                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(
                        1,
                        json_encode([
                            'status' => '0',
                            'message' => 'Success'
                        ]),
                        $order
                    );
                } else {
                    return new CallbackResponse(
                        0,
                        json_encode([
                            'status' => '0',
                            'message' => 'Success'
                        ]),
                        $order
                    );
                }
            } else {
                DB::rollback();
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
                    'message' => 'Success'
                ]),
                null
            );
        }
    }

    private function makeRequestBody($info, $params)
    {
        $signMsg = '';
        foreach ($params as $v) {
            $signMsg .= $v . '|';
        }
        $signMsg .= $info['PrivateKey'];

        $params['signature'] = strtolower(md5($signMsg));
        return $params;
    }

    /**
     * @param $params
     * @param $info
     * @throws Exception
     */
    private function checkSign($params, $info)
    {
        $sign_msg = strtolower($params['signature']);
        $msg = $params['status'] . '|' .
            $params['trans_id'] . '|' .
            $params['serial'] . '|' .
            $info['PartnerId'] . '|' .
            $info['PrivateKey'];

        return ($sign_msg !== strtolower(md5($msg))) ? true : false;
    }
}
