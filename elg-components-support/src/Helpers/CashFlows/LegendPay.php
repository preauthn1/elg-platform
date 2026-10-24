<?php

namespace target\support\Helpers\CashFlows;

use target\support\Helpers\VendorSend;
use target\support\Models\AgentCashFlow;
use target\support\Models\CallbackResponse;
use target\support\Models\Order;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LegendPay extends CashFlowBase
{
    /**
     * @param  AgentCashFlow  $agentCashFlow
     * @param  Order  $order
     * @param  string  $callbackUrl
     * @param  string  $returnUrl
     * @return array [
     *      "type" : 1  // 0失敗, 1 轉址, 2 QRCode, 3 Form Post
     *      "url" : string
     *      "html": string
     * ]
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl): array
    {
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['API_URL'] . '/LegendPay/payin';
        $method = 'POST';
        $params = [
            'mode' => '0',
            'random' => Str::random(16),
            'orderAmount' => $order->amount,
            'orderId' => $order->order_no,
            'param' => '', // 商户自定义回传参数
            'callbackUrl' => $callbackUrl,
            'returnUrl' => $returnUrl,
            'ts' => (int) (microtime(true) * 1000), // 毫秒时间戳
        ];

        Log::debug(sprintf('%s_order_%s', class_basename($this), $order->id), [
            'class' => get_class($this),
            'order_id' => $order->id,
            'url' => $url,
            'method' => $method,
            'params' => $params,
        ]);

        $headers = [
            'Content-Type: application/x-www-form-urlencoded',
            'l-token: ' . $info['API_TOKEN'],
            'l-sign: ' . $this->generateSign($params, $info),
        ];
        $sender = new VendorSend();
        $response = $sender->send('LegendPay_API', $url, $method, $params, $headers);
        $result = json_decode($response, true);

        if ($result['code'] === 1000 && isset($result['data']['payUrl'])) {
            return [
                'type' => 1,
                'url' => $result['data']['payUrl'],
            ];
        }

        Log::error(sprintf('%s_order_%s_error', class_basename($this), $order->id), [
            'class' => get_class($this),
            'order_id' => $order->id,
            'response' => $response,
        ]);

        return ['type' => 0, 'url' => ''];
    }

    /**
     * 三方回調
     * @param  Request  $request
     * @return CallbackResponse
     * @throws Exception
     */
    public function callback(Request $request): CallbackResponse
    {
        $params = $request->all();

        Log::debug(class_basename($this) . '_callback', [
            'method' => $_SERVER['REQUEST_METHOD'],
            'url' => $request->fullUrl(),
            'params' => $params,
        ]);

        // 驗證參數
        $validator = Validator::make($params, [
            'amount' => 'required|string',
            'commission' => 'required|string',
            'orderAmount' => 'required|string',
            'orderId' => 'required|string',
            'param' => 'required|string',
            'sign' => 'required|string',
            'status' => 'required|string', // 入款状态（0:待支付,1:成功,2:失败）
        ]);

        if ($validator->fails()) {
            throw new Exception($validator->errors()->first(), 2002);
        }

        // 取得支付方式設定
        $agentCashFlow = $this->getAgentCashFlow($request->agent_cash_flow_id);

        if (!$agentCashFlow) {
            throw new Exception(sprintf('Cashflow %s not enabled', class_basename($this)), 2002);
        }

        // 驗證簽名
        $info = json_decode($agentCashFlow->info, true);

        if ($this->checkSign($params, $info)) {
            throw new Exception('Signature verification failed', 2002);
        }

        DB::beginTransaction();
        try {
            $order = Order::where('order_no', $params['orderId'])->lockForUpdate()->first();

            if (null === $order) {
                DB::rollBack();
                throw new Exception(sprintf('Order %s does not exist', $params['orderId']), 2002);
            }

            // $agentCashFlow = $this->getAgentCashFlow($order->agent_cash_flow_id);
            // $agentCashFlowOrderType = DB::table('agent_cash_flow_order_types')
            //     ->where('agent_cash_flow_id', $order->agent_cash_flow_id)
            //     ->where('order_type_id', $order->order_type_id)
            //     ->first();

            // 狀態 0:建立,1:成功,2:失敗,3:己上分,4:待手動確認上分
            // 檢查訂單是否已處理過
            if ($order->status !== 0) {
                DB::rollBack();
                throw new Exception('The order has been processed', 2002);
            }

            // 更新訂單
            if ($params['status'] === '1') {
                if ($this->padDecimal($order->amount, 4) === $this->padDecimal($params['amount'], 4)) {
                    // 成功
                    $order->status = 1;
                } else {
                    // 金額不符需手動確認
                    $order->status = 4;
                }
            } else {
                // 失敗
                $order->status = 2;
            }
            $order->remark = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            if (!$order->save()) {
                DB::rollBack();

                Log::error(class_basename($this) . '_callback_save_error', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                ]);

                throw new Exception('System error', 2002);
            }

            DB::commit();
            if (in_array($order->status, [1, 4])) {
                return new CallbackResponse(1, 'success', $order);
            } else {
                return new CallbackResponse(0, 'failure', $order);
            }
        } catch (Exception $e) {
            DB::rollBack();

            Log::debug(class_basename($this) . '_callback_fail', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $e->getMessage(),
            ]);

            return new CallbackResponse(
                0,
                json_encode([
                    'status' => -1,
                    'message' => 'Success',
                ]),
                null
            );
        }
    }

    /**
     * @param $params
     * @param $info
     * @return string
     */
    private function generateSign($params, $info): string
    {
        // 只移除 null 與空字串，保留 0 與 '0'
        $filtered = array_filter($params, function ($value) {
            return $value !== null && $value !== '';
        });

        // 若回傳欄位名稱為 signature，排除它
        unset($filtered['sign']);

        ksort($filtered);

        $sign = rawurldecode(http_build_query($filtered));
        $sign = $sign . '&key=' . $info['API_SECRET'];
        return strtoupper(md5($sign));
    }

    /**
     * @param $params
     * @param $info
     * @return bool
     */
    private function checkSign($params, $info): bool
    {
        $sign = $params['sign'] ?? '';
        $verifySign = $this->generateSign($params, $info);
        return $sign !== $verifySign;
    }

    /**
     * 將金額字串補零到指定小數位
     */
    private function padDecimal(string $amount, int $decimals = 4): string
    {
        if (strpos($amount, '.') === false) {
            return $amount . '.' . str_repeat('0', $decimals);
        }
        [$int, $frac] = explode('.', $amount, 2);
        $frac = substr($frac . str_repeat('0', $decimals), 0, $decimals);
        return $int . '.' . $frac;
    }
}
