<?php

namespace target\support\Helpers\CashFlows;

use target\support\Helpers\HttpClient;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\FirebaseCloudMessage;
use target\support\Models\Member;
use target\support\Models\Order;
use target\support\Models\OrderType;
use target\support\Helpers\VendorSend;
use target\support\Models\REDIS;
use target\support\Services\CashFlowService;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class QRReload extends CashFlowBase
{
    /**
     * @param mixed $agentCashFlow
     * @param mixed $order
     * @param string $callbackUrl
     * @param string $returnUrl
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode, 9
     *      "code" : int
     *      "url" : string
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl)
    {
        $request = request();
        // $member = Member::find($order->member_id);
        // $order_type = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $method = 'POST';
        $url = $info['api_url'];
        $params = [
            'op' => 'use_key',
            'agent_id' => $info['agent_id'],
            'key' => $request->pin
        ];
        $sign = $this->getSign($info, $params);
        $params['signature'] = $sign;
        $client = new HttpClient();
        $response_body = $client->request(
            "qrreload_send",
            $method,
            $url,
            ['form_params' => $params]
        );
        $response = json_decode($response_body, true);
        $result = $this->checkResponse($agentCashFlow, $order, $response, $request->pin);
        if ($result->getStatus() == 1) { //回調成功上分
            $service = new CashFlowService();
            if ($order->member_id > 0) {
                $service->updateMemberBalance($result->getOrder());
                // 刪除Redis中相關的資料
                $redis = new REDIS();
                $redis->delete(array(
                    'member'
                ));
                //新增推播
                $message = [
                    'member_id' => $result->getOrder()->member_id,
                    'title' => '系統訊息',
                    'content' => '線上儲值成功',
                    'url' => env('PUSH_URL') . 'RecordList'
                ];
                FirebaseCloudMessage::create($message);
            } else {
                $service->updateAgentBalance($result->getOrder());
            }

            return [
                'type' => 9,
                'code' => 999,
                'message' => sprintf(__('param.deposit_success'), $result->getOrder()->point)
            ];
        }
        return [
            'type' => 9,
            'code' => 998,
            'message' => $result->getResponse()
        ];
    }

    /**
     * 處理 payment_api 返回結果
     * @param mixed $agentCashFlow
     * @param mixed $order
     * @param mixed $response
     * @param mixed $pin
     * @return CallbackResponse
     */
    private function checkResponse($agentCashFlow, $order, $response, $pin)
    {
        DB::beginTransaction();
        try {
            $order = Order::lockForUpdate()->find($order->id);
            if ($order === null) {
                throw new Exception('can not find order', 2002);
            }

            Log::debug(get_class($this) . '_checkResponse', [
                'pin' => $pin,
                'response' => $response
            ]);

            if ($order->status == 0) {
                if ($response['status'] == 1) { //成功直接上分
                    $order->status = 1;
                    $amount = round($response['amount'], 2);
                    $point = round($amount * $agentCashFlow->rate);
                    $order->amount = $amount;
                    $order->point = $point;
                } else {
                    $order->status = 2;
                }
                $order->remark = json_encode([
                    'pin' => $pin,
                    'response' => $response
                ]);

                if (!$order->save()) {
                    throw new Exception(__('param.system_error'), 2002);
                }
                DB::commit();
                if ($order->status == 1) {
                    return new CallbackResponse(1, __('param.success'), $order);
                } else {
                    if ($response['msg'] == 110) {
                        return new CallbackResponse(0, __('param.code_has_been_used'), null);
                    }
                    return new CallbackResponse(0, __('param.fail'), null);
                }
            } else {
                throw new Exception(__('param.order_status_not_zero'), 2002);
            }
        } catch (Exception $e) {
            DB::rollback();
            Log::error(get_class($this) . '_callback', [
                'pin' => $pin,
                'response' => $response,
                'exception' => $e->getMessage(),
            ]);
            return new CallbackResponse(0, __('param.fail'), null);
        }
    }

    /**
     * 三方回調
     * @param Request $request
     * @return CallbackResponse
     */
    public function callback(Request $request)
    {
        // 實作一個空的 callback
        return new CallbackResponse(0, __('param.fail'), null);
    }


    private function getSign($info, $params)
    {
        $params['secret_key'] = $info['secret_key'];
        ksort($params);
        $arr = array_values($params);
        $str = implode('', $arr);
        return strtoupper(md5($str));
    }
}
