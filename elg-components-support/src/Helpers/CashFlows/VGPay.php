<?php
namespace target\support\Helpers\CashFlows;

use target\support\Helpers\VendorSend;
use target\support\Models\Agent;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Member;
use target\support\Models\Order;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VGPay extends CashFlowBase
{
    /**
     * Casey, [2025/3/13 下午 03:35]
     *    充值成功回调
     *    {
     *        "url": "商户回调地址",
     *        "header": {
     *            "Accept": "application\/json"
     *        },
     *        "params": {
     *            "order_id": "VGroup订单号",
     *            "tx_id": "商户订单号",
     *            "transferror_name": "转账人姓名",
     *            "status": "COMPLETE",
     *            "order_amount": "100.00",
     *            "service_change": "5.00",
     *            "final_amount": "95.00"
     *        }
     *    }
     *
     *    充值失败回调
     *    {
     *        "url": "商户回调地址",
     *        "header": {
     *            "Accept": "application\/json"
     *        },
     *        "params": {
     *            "order_id": "VGroup订单号",
     *            "tx_id": "商户订单号",
     *            "transferror_name": "转账人姓名",
     *            "status": "REJECT",
     *            "order_amount": "100.00",
     *            "service_change": "5.00",
     *            "final_amount": "95.00"
     *        }
     *    }
     *
     *    Casey, [2025/3/13 下午 03:35]
     *    提现失败回调
     *    {
     *        "url": "-",
     *        "header": {
     *            "Accept": "application\/json"
     *        },
     *        "params": {
     *            "order_id": "VGroup订单号",
     *            "tx_id": "商户订单号",
     *            "status": "REJECT",
     *            "order_amount": "300.07",
     *            "service_change": "6.00",
     *            "final_amount": "306.07",
     *            "receipt": "收据地址"
     *        }
     *    }
     *
     *    提现成功回调
     *
     *    {
     *        "url": "-",
     *        "header": {
     *            "Accept": "application\/json"
     *        },
     *        "params": {
     *            "order_id": "VGroup订单号",
     *            "tx_id": "商户订单号",
     *            "status": "COMPLETE",
     *            "order_amount": "300.07",
     *            "service_change": "6.00",
     *            "final_amount": "306.07",
     *            "receipt": "收据地址"
     *        }
     *    }
     *
     *  @var array
     */
    protected $rules_callback = [
        'order_id'         => 'required',
        'tx_id'            => 'required',
        'transferror_name' => 'required',
        'status'           => 'required',
        'order_amount'     => 'required',
        'service_change'   => 'required',
        'final_amount'     => 'required',
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
        // $order_type     = OrderType::find($order->order_type_id);
        $info         = json_decode($agentCashFlow->info, true);
        $url          = $info['apiUrl'] . '/deposit/make_order';
        $merchantCode = $info['merchantCode'];

        // 將帳號和密碼組合成 "username:password" 格式
        $credentials = $info["merchantCode"] . ':' . $info["apiPassword"];

        // 使用 base64 編碼
        $basicAuthToken = [REDACTED];

        $method = 'POST';
        $params = [
            'amount'       => sprintf('%.2f', $order->amount),
            "tx_id"        => $order->order_no,
            'callback_url' => $callbackUrl,
            'redirect_url' => $returnUrl,
            'customer_id'  => $member->username,
        ];

        $headers = [
            'merchant: ' . $merchantCode,
            'Content-Type: application/json',
            'Authorization: Basic ' . $basicAuthToken,
        ];

        Log::info('VGPay_API_Deposit_' . $order->order_no, [
            'url'    => $url,
            'method' => $method,
            'params' => $params,
            'header' => $headers,
        ]);

        $sender = new VendorSend(['json_post' => true]);
        $result = $sender->send('VGPay_API_Deposit', $url, $method, $params, $headers);
        /** @var string $result */
        $responseData = json_decode($result, true);

        if (empty($responseData) || ! isset($responseData["status"])) {
            throw new Exception('VGPay deposit api response error');
        }
        // {
        //     "status": 1,
        //     "code": "SUCCESS",
        //     "data": {
        //       "order_id": "DP17180892266667F60A0DDC",
        //       "tx_id": "sampletxid",
        //       "order_amount": "200.00",
        //       "service_change": "2.00",
        //       "final_amount": "198.00",
        //       "transferror_name": "sample name",
        //       "payment_details": {
        //         "bank_name": "例子銀行（SAMPLE BANK）",
        //         "holder_name": "test",
        //         "acc_number": "28856152",
        //         "bsb_number": "3007090",
        //         "pay_id_number": "108237999",
        //         "redirect_url": "http://example.invalid/deposits/order_received/DP17180893676667F6974987"
        //       }
        //     }
        //   }

        if (1 == $responseData['status']) {
            $order->extra_content = json_encode($responseData['data']['payment_details']);
            $order->save();

            $url = $responseData['data']['payment_details']['redirect_url'];

            return [
                'type' => 1,
                'url'  => $url,
            ];
        }

        return [
            'type' => 0,
            'url'  => '',
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
        try {
            $this->validate($request, $this->rules_callback);

            $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);
            $info          = json_decode($agentCashFlow->info, true);

            DB::beginTransaction();
            $order = Order::where('order_no', $params['tx_id'])->lockForUpdate()->first();
            if (null === $order) {
                throw new Exception('can not find order', 2002);
            }

            Log::info('VGPay_API_Deposit_Callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url'    => $request->fullUrl(),
                'params' => $params,
            ]);

            //三方單號
            $order->third_party_order_no = $params['order_id'];
            if (0 != $order->status) {
                throw new Exception('order status : ' . $order->status . ' not 0', 2002);
            }

            /**
             * "status": "COMPLETE" | "REJECT"
             *
             * */
            if ("COMPLETE" == $params['status']) {
                if ((float) ($order->amount) == (float) ($params['order_amount'])) {
                    $order->status = 1;
                }
            } else {
                //Failed
                $order->status = 2;
            }
            $order->remark = json_encode($params);

            if (! $order->save()) {
                throw new Exception('system error', 2002);
            }

            DB::commit();
            if (1 == $order->status) {
                return new CallbackResponse(
                    1,
                    json_encode([
                        'code'        => "0",
                        'description' => "Success",
                    ]),
                    $order
                );
            } else {
                return new CallbackResponse(
                    0,
                    json_encode([
                        'code'        => "0",
                        'description' => "Success",
                    ]),
                    $order
                );
            }
        } catch (Exception $exception) {
            DB::rollback();
            Log::error('VGPay_API_Deposit_Callback', [
                'method'    => $_SERVER['REQUEST_METHOD'],
                'url'       => $request->fullUrl(),
                'params'    => $params,
                'exception' => $exception->getMessage(),
            ]);

            return new CallbackResponse(
                0,
                json_encode([
                    'code'        => "-100",
                    'description' => "Error",
                ]),
                null
            );
        }
    }
}
