<?php

namespace target\support\Helpers\CashFlows;

use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Models\OrderType;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GffPay extends CashFlowBase
{
    /**
     *    成功回調
     *    {
     *        "merchant_code": "商戶編號",
     *        "currency_code": "貨幣代碼",
     *        "order_no": "訂單編號",
     *        "merchant_order_no": "商戶訂單編號",
     *        "status": 2,  // 訂單狀態 1:等待付款 2:成功 3:失敗
     *        "amount": "金額",
     *        "sign": "簽名",
     *    }
     *
     *    失败回调
     *    {
     *        "merchant_code": "商戶編號",
     *        "currency_code": "貨幣代碼",
     *        "order_no": "訂單編號",
     *        "merchant_order_no": "商戶訂單編號",
     *        "status": 3,  // 訂單狀態 1:等待付款 2:成功 3:失敗
     *        "amount": "金額",
     *        "sign": "簽名",
     *    }
     *
     *  @var array
     */
    protected $rules_callback = [
        'order_no' => 'required',
        'merchant_order_no' => 'required',
        'status' => 'required',
        'amount' => 'required',
        'sign' => 'required',
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
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['APIUrl'] . '/deposit-order';
        $secretKey = $info['MerchantKey'];
        $order_type = OrderType::find($order->order_type_id);

        $method = 'POST';
        $params = [
            'currency_code' => $order_type->key,
            'merchant_code' => $info['MerchantNo'],
            'amount' => $order->amount,
            "merchant_order_no" => $order->order_no,
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
        ];
        $params['sign'] = $this->sign($params, $secretKey);

        $headers = [
            'Content-Type: application/json',
        ];

        Log::info('GffPay_API_Deposit_' . $order->order_no, [
            'url' => $url,
            'method' => $method,
            'params' => $params,
        ]);

        $sender = new VendorSend(['json_post' => true]);
        $result = $sender->send('GffPay_API_Deposit', $url, $method, $params, $headers);
        /** @var string $result */
        // {
        //     "code": 200,
        //     "message": "SUCCESS",
        //     "data": {
        //         "order_no": "D2025092406165201",
        //         "merchant_order_no": "9",
        //         "amount": "0.1",
        //         "payment_url": "http://apis.local/external/api/ton/cashier/D2025092406165201"
        //     }
        // }

        $responseData = json_decode($result, true);

        if (empty($responseData)) {
            $order->extra_content = json_encode(['create_order_response' => $result]);
        } else {
            $order->extra_content = json_encode(['create_order_response' => $responseData]);
        }
        $order->save();

        if (empty($responseData) || ! isset($responseData["code"])) {
            throw new Exception('GffPay deposit api response error');
        }

        if (200 == $responseData['code']) {
            $url = $responseData['data']['payment_url'];

            return [
                'type' => 1,
                'url' => $url,
            ];
        }

        return [
            'type' => 0,
            'url' => '',
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
            $info = json_decode($agentCashFlow->info, true);

            // 驗證簽名
            $secretKey = $info['MerchantKey'];
            if ($this->sign($params, $secretKey) !== $params['sign']) {
                throw new Exception('sign error', 2002);
            }

            DB::beginTransaction();
            $order = Order::where('order_no', $params['merchant_order_no'])->lockForUpdate()->first();
            if (null === $order) {
                throw new Exception('can not find order', 2002);
            }

            if ($order->status != 0) {
                throw new Exception('order status : ' . $order->status . ' not 0', 2002);
            }

            Log::info('GffPay_API_Deposit_Callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
            ]);

            //三方單號
            $order->third_party_order_no = $params['order_no'];

            if (2 == $params['status']) {
                if ((float) ($order->amount) == (float) ($params['amount'])) {
                    // 成功
                    $order->status = 1;
                } else {
                    // 金額不符需手動確認
                    $order->status = 4;
                }
            } elseif (3 == $params['status']) {
                // 失敗
                $order->status = 2;
            }
            $extraContent = json_decode($order->extra_content, true);
            $extraContent['callback_request'] = $params;
            $order->extra_content = json_encode($extraContent);

            if (! $order->save()) {
                throw new Exception('system error', 2002);
            }

            DB::commit();
            if (1 == $order->status) {
                return new CallbackResponse(
                    1,
                    json_encode([
                        'code' => "200",
                    ]),
                    $order
                );
            } else {
                return new CallbackResponse(
                    0,
                    json_encode([
                        'code' => "200",
                    ]),
                    $order
                );
            }
        } catch (Exception $exception) {
            DB::rollback();
            Log::error('GffPay_API_Deposit_Callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $exception->getMessage(),
            ]);

            return new CallbackResponse(
                0,
                json_encode([
                    'code' => "400",
                    'message' => $exception->getMessage(),
                ]),
                null
            );
        }
    }

    public function sign(array $input, string $secretKey): string
    {
        unset($input['sign']);
        ksort($input);
        $signString = '';
        foreach ($input as $k => $v) {
            $signString .= $k . '=' . $v . '&';
        }
        $signString = rtrim($signString, '&');
        $signString .= $secretKey;
        $sign = strtoupper(md5($signString));

        return $sign;
    }
}
