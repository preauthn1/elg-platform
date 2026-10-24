<?php
namespace target\support\Helpers\CashFlows;

use Illuminate\Http\Request;
use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use target\support\Models\OrderType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Exception;

class DGPayTHB extends CashFlowBase
{

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
        $bankNumber = request()->bank_number;

        if (empty($bankNumber)) {
            throw new Exception('bank number is empty', 2002);
        }

        $orderType = OrderType::find($order->order_type_id);
        $info = json_decode($agentCashFlow->info, true);
        $type = 0;

        $domain = $info['APIUrl'];
        $apiKey = [REDACTED]'apiKey'];
        $secretKey = $info['secretKey'];
        $clientId = $info['clientId'];
        $merchantId = $info['merchantId'];

        $timestamp = time();
        $url = $domain .'/api/v1/jwt/create';
        $header = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
        ];
        $params = [
            'secretKey' => $secretKey,
            'timestamp' => $timestamp,
            'payload' => [
                'merchantId' => $merchantId,
                'clientId' => $clientId,
            ]
        ];

        $sender = new VendorSend([
            'json_post' => true
        ]);
        $response = $sender->send(
            'DGPay2_jwt_API',
            $url,
            'POST',
            $params,
            $header
        );

        $responseData = json_decode($response, true);

        Log::debug(get_class($this) . '_THB_' . $order->order_no, [
            'url' => $url,
            'method' => 'POST',
            'params' => $params
        ]);

        $signature = $responseData['signature'];

        $url = $domain . '/api/v1/deposit/create';
        $params = [
            'clientId'=> $clientId,
            'merchantId'=> $merchantId,
            'transactionId' => $order->order_no,
            'bankAccountNumber'=> $bankNumber,
            'bankName'=> $orderType->key,
            // 'name'=> 'เฮง ร่ำรวย',
            'amount'=> (float) $order->amount,
            'callbackUrl' => $callbackUrl,
            'type' => 'QR',
            'timeout' => 5,
            'signature' => $signature,
            'timestamp' => $timestamp,
        ];


        $response = $sender->send(
            'DGPay2_deposit_API',
            $url,
            'POST',
            $params,
            $header
        );

        $responseData = json_decode($response, true);

        Log::debug(get_class($this) . '_THB_' . $order->order_no, [
            'url' => $url,
            'method' => 'POST',
            'params' => $params
        ]);

        if (isset($responseData['status']) && $responseData['status'] == 'success') {
            $order->save();
            $type = 11;
            $qrcode = $responseData['data']['qrcode'];
        } else {
            throw new Exception($responseData['message'], $responseData['code']);
        }
        
        return [
            'type' => $type,
            'code' => $qrcode
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
            $this->validate($request, [
                'status' => 'required',
                'transactionId' => 'required',
                'amount' => 'numeric',
            ]);

            $agentCashFlow = $this->getAgentCashFlow($agent_cash_flow_id);
            $info = json_decode($agentCashFlow->info, true);
            
            if ($params['merchantId'] != $info['merchantId']) {
                throw new Exception('sign error', 2002);
            }

            $order_no = $params['transactionId'];
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
            $order->third_party_order_no = $params['referenceId'];
            if ($order->status != 0) {
                throw new Exception('order status : ' . $order->status . ' not 0', 2002);
            }

            switch ($params['status']) {
                case 'completed':
                    $order->status = 1;
                    break;
                case 'expired':
                    $order->status = 2;
                    break;
                default:
                    break;
            }

            $order->remark = json_encode($params);
            if (!$order->save()) {
                throw new Exception('system error', 2002);
            }
            DB::commit();
            return new CallbackResponse(
                1,
                json_encode([
                    'code' => "0",
                    'description' => "Success"
                ]),
                $order
            );
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
}
