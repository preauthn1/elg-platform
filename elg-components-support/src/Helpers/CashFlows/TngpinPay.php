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

/**
 * Tngpin 禮物卡（Telco PIN）充值
 *
 * 流程：
 *  1. 使用者下單時僅提供 PIN 碼，「面額未知」——此時無法決定充值金額。
 *  2. send() 帶 PIN 與 merchant_ref(= order_no) 呼叫三方 /submitPIN，wait_result=false 走非同步。
 *  3. 三方核實 PIN 後以 callback 回報，「此時才得知禮物卡面額(amount)」與最終狀態(status)。
 *     - status 1：成功，依回調 amount 計算點數入帳。
 *     - status 2：失敗。
 *     - status 3：處理中，維持訂單 status=0 等待下一次 callback。
 *
 * 安全性備註：因面額由三方回調決定、無法以「訂單預期金額」事前交叉驗證，
 * 故簽章正確性與來源 IP 白名單是僅有的真實性保證。
 * 簽章規格：md5(payment_key + transaction_id + merchant_ref)。
 * 註：merchant_ref 為 submitPIN 當下送出值，本系統曾由「商戶號」改為「order_no」，
 *     故驗章相容新舊兩種來源（在途舊單以商戶號簽章），詳見 checkSign()。
 */
class TngpinPay extends CashFlowBase
{
    /** 金額運算小數位數 */
    private const SCALE = 4;

    /** 記 log / 寫 DB 時需遮罩的敏感欄位 */
    private const SENSITIVE_KEYS = ['payment_key', 'payment_pass', 'pin'];

    /** @var array */
    private $rules_callback = [
        'status' => 'required',
        'amount' => 'required|numeric|min:0',
        'transaction_id' => 'required',
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
     * @throws Exception
     */
    public function send(AgentCashFlow $agentCashFlow, Order $order, string $callbackUrl, string $returnUrl): array
    {
        $request = request();
        $info = json_decode($agentCashFlow->info, true);
        $url = $info['APIUrl'] . '/submitPIN';

        $orderType = OrderType::query()->where('id', $order->order_type_id)->first();
        if (!$orderType) {
            throw new Exception('can not find order type', 2002);
        }

        $params = [
            'payment_key' => $info['ApiKey'],
            'payment_pass' => $info['ApiPass'],
            'telco' => $orderType->key,
            'pin' => $request->pin,
            'merchant_ref' => $order->order_no,
            'ip_address' => $order->ip,
            'wait_result' => false
        ];

        Log::debug(get_class($this) . '_' . $order->id, [
            'url' => $url,
            'method' => 'POST',
            'params' => $this->maskSensitive($params)
        ]);

        if (!$order->save()) {
            Log::error(get_class($this) . '_send_save_failed', ['order_id' => $order->id]);
            return ['type' => 9, 'code' => 998, 'message' => __('param.fail')];
        }

        $code = 999; //前端會返回支付頁
        $message = __('param.success');

        // PIN 為空＝首次進頁，僅回支付頁不打三方
        if ($request->pin != '') {
            $sender = new VendorSend(['json_post' => true]);
            $response = $sender->send('TelcoPay_API', $url, 'POST', $params, ['Content-Type: application/json']);
            $responseData = json_decode($response, true);
            /*
             * status
             * 1 Success
             * 2 Failed
             * 3 Processing (Callback will be sent once completed)
             */
            if (is_array($responseData) && ($responseData['result'] ?? null) === 'success') {
                $transactionId = $responseData['transaction_id'] ?? '';
                $order->third_party_order_no = $transactionId;
                $order->remark = json_encode([
                    'transaction_id' => $transactionId,
                    'request' => $this->maskSensitive($params),
                    'response' => $response,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $order->save();
                if (($responseData['status'] ?? null) === '2') {
                    $code = 998; //前端會維持在原頁
                    $message = '[ER-' . ($responseData['detail_message'] ?? '') . '] ' . __('param.fail');
                }
            } else {
                $code = 998; //前端會維持在原頁
                $errorMessage = is_array($responseData) ? ($responseData['message'] ?? '') : '';
                $message = '[ER-' . $errorMessage . '] ' . __('param.fail');
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
     * @param  Request  $request
     * @return CallbackResponse
     * @throws Exception
     */
    public function callback(Request $request): CallbackResponse
    {
        $params = $request->all();
        DB::beginTransaction();
        try {
            $this->validate($request, $this->rules_callback);

            /** @var Order|null $order */
            $order = Order::query()
                ->where(function ($query) use ($request) {
                    $query->where('order_no', $request->transaction_id);
                    $query->orWhere('third_party_order_no', $request->transaction_id);
                })
                ->lockForUpdate()
                ->first();

            if (!$order) {
                throw new Exception('can not find order', 2002);
            }

            $agentCashFlow = $this->getAgentCashFlow($order->agent_cash_flow_id);
            $agentCashFlowOrderType = DB::table('agent_cash_flow_order_types')
                ->where('agent_cash_flow_id', $order->agent_cash_flow_id)
                ->where('order_type_id', $order->order_type_id)
                ->first();
            $info = json_decode($agentCashFlow->info, true);

            $this->guardCallbackIp($request, $info, $params);

            if ($this->checkSign($params, $order->order_no, $info)) {
                throw new Exception('sign error', 2002);
            }

            Log::debug(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params
            ]);

            // 已處理過的訂單：APIs 在發起代收當下已經判斷過狀態
            if ($order->status != 0) {
                DB::rollBack();
                if ($order->status == 1) {
                    return $this->ack(true, 'Success', $order);
                }
                throw new Exception('order status not 0', 2002);
            }

            $status = (string) $request['status'];

            // 處理中：維持訂單 status=0，等待下一次 callback
            if ($status === '3') {
                Log::info(get_class($this) . '_callback_processing', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'callback_status' => $status,
                    'message' => 'Order still processing, waiting for next callback'
                ]);
                $this->appendRemark($order, $params, ['processing_callback_at' => date('Y-m-d H:i:s')]);
                $this->saveOrThrow($order);
                DB::commit();
                return $this->ack(true, 'Processing', $order);
            }

            if ($status === '1') {
                $this->applySuccess($order, (string) $request['amount'], $agentCashFlow, $agentCashFlowOrderType);
            } elseif ($status === '2') {
                $order->status = 2;
            } else {
                // 未知狀態，標記為失敗
                Log::warning(get_class($this) . '_callback_unknown_status', [
                    'order_id' => $order->id,
                    'order_no' => $order->order_no,
                    'callback_status' => $status
                ]);
                $order->status = 2;
            }

            $this->appendRemark($order, $params);
            $this->saveOrThrow($order);
            DB::commit();

            return $this->ack($order->status == 1, 'Success', $order);
        } catch (Exception $e) {
            DB::rollBack();
            Log::error(get_class($this) . '_callback', [
                'method' => $_SERVER['REQUEST_METHOD'],
                'url' => $request->fullUrl(),
                'params' => $params,
                'exception' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            // 對外只回 message，不外洩檔案路徑/行號（避免內部結構洩漏）
            return new CallbackResponse(
                0,
                json_encode([
                    'status' => -1,
                    'message' => $e->getMessage()
                ]),
                null
            );
        }
    }

    /**
     * 成功入帳：面額由三方回調決定，依 rate 計算點數並扣稅（bcmath 避免浮點誤差）
     * @throws Exception
     */
    private function applySuccess(
        Order $order,
        string $amount,
        AgentCashFlow $agentCashFlow,
        $agentCashFlowOrderType
    ): void {
        if (!$agentCashFlowOrderType) {
            throw new Exception('can not find agent cash flow order type', 2002);
        }

        $amount = bcadd('0', $amount, self::SCALE); // 從回調 JSON 取得的數值正規化
        $point = bcmul($amount, (string) $agentCashFlow->rate, self::SCALE);
        $taxPercentage = bcmul($point, (string) $agentCashFlowOrderType->tax_percentage, self::SCALE);
        $tax = bcadd((string) $agentCashFlowOrderType->tax, $taxPercentage, self::SCALE);
        $point = bcsub($point, $tax, self::SCALE);

        $order->amount = $amount;
        $order->tax = $tax;
        $order->point = $point;
        $order->status = 1;
    }

    /**
     * 來源 IP 白名單（設定於 agent_cash_flows.info 的 CallbackAllowIps，支援陣列或逗號分隔字串）
     * 面額由回調決定無法事前交叉驗證，故來源控制是關鍵防線；未設定則不啟用此檢查。
     * @throws Exception
     */
    private function guardCallbackIp(Request $request, array $info, array $params): void
    {
        $allowIps = $info['CallbackAllowIps'] ?? [];
        if (is_string($allowIps)) {
            $allowIps = array_filter(array_map('trim', explode(',', $allowIps)));
        }

        if (!empty($allowIps) && !in_array($request->ip(), $allowIps, true)) {
            Log::warning(get_class($this) . '_callback_ip_rejected', [
                'ip' => $request->ip(),
                'allow_ips' => $allowIps,
                'url' => $request->fullUrl(),
                'params' => $params
            ]);
            throw new Exception('ip not allowed', 2002);
        }
    }

    /**
     * 驗章：md5(payment_key + transaction_id + merchant_ref)
     *
     * merchant_ref 取「submitPIN 當下送出的值」，三方據此簽章。本系統的 merchant_ref
     * 規則曾調整過，故驗章需相容新舊兩種來源：
     *   - 新單（現行）：merchant_ref = $order->order_no（純數字）
     *   - 舊單（相容）：merchant_ref = $info['Merchant']（商戶號，如 "Yes2Win"）
     * 上版前送出、上版後才回來的「在途舊單」會以商戶號簽章，故此處同時接受兩者；
     * 待舊單在途回調全數消化後，可移除商戶號相容分支。
     *
     * 兩個候選值皆為伺服器端已知（order_no 與設定檔商戶號），皆不取回調帶進來的
     * merchant_ref，故同時接受不會降低安全性（攻擊者仍需 ApiKey 才能偽造任一簽章）。
     *
     * @param  array   $params       回調參數（含 signature、transaction_id）
     * @param  string  $merchantRef  該筆訂單的 order_no（現行規則的權威來源）
     * @param  array   $info         金流商設定（含 ApiKey、Merchant）
     * @return bool  true 代表驗章失敗
     */
    private function checkSign(array $params, string $merchantRef, array $info): bool
    {
        $sign_msg = strtolower((string) ($params['signature'] ?? ''));
        $transactionId = (string) ($params['transaction_id'] ?? '');

        // 候選 merchant_ref：現行 order_no，及相容在途舊單的商戶號
        $candidates = [$merchantRef];
        if (!empty($info['Merchant'])) {
            $candidates[] = (string) $info['Merchant'];
        }

        foreach ($candidates as $candidate) {
            $expected = strtolower(md5($info['ApiKey'] . $transactionId . $candidate));
            // 用 hash_equals 防 timing attack
            if (hash_equals($expected, $sign_msg)) {
                return false; // 任一規則驗章成功即通過
            }
        }

        return true; // 兩種規則皆不符 → 驗章失敗
    }

    /**
     * 回調 ACK：對三方回應固定 status='0' 表示已成功接收處理
     */
    private function ack(bool $success, string $message, Order $order): CallbackResponse
    {
        return new CallbackResponse(
            $success ? 1 : 0,
            json_encode([
                'status' => '0',
                'message' => $message
            ]),
            $order
        );
    }

    /**
     * 將回調內容寫入 remark.response，並可附加額外欄位
     */
    private function appendRemark(Order $order, array $params, array $extra = []): void
    {
        $remark = json_decode((string) $order->remark, true) ?: [];
        $remark['response'] = $params;
        foreach ($extra as $key => $value) {
            $remark[$key] = $value;
        }
        $order->remark = json_encode($remark);
    }

    /**
     * @throws Exception
     */
    private function saveOrThrow(Order $order): void
    {
        if (!$order->save()) {
            throw new Exception('system error', 2002);
        }
    }

    /**
     * 遮罩敏感欄位（帳密、PIN）後回傳副本，供 log / remark 使用
     */
    private function maskSensitive(array $params): array
    {
        foreach (self::SENSITIVE_KEYS as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $params[$key] = '***';
            }
        }
        return $params;
    }
}
