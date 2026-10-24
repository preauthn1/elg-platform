<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\DgPay;

use App\Enums\OrderStatusEnum;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use DgPayTrait;

    const ERROR_CODE = -100;

    protected AgentCashFlow $agentCashFlow;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    // {
    //     "remark": "Auto Approve By System",
    //     "status": "20",
    //     "data": "{\"orderId\":\"202511275929184104@dgpay_171\",\"merchantOrderId\":\"202511275929184104\",\"amount\":30.0000,\"processAmount\":30.0000,\"chargeFee\":0.7800,\"status\":20,\"remark\":\"Auto Approve By System\",\"data\":\"{\\\"code\\\":\\\"0\\\",\\\"refId\\\":\\\"202511275929184104@dgpay_171\\\",\\\"paymentUrl\\\":\\\"https://example.invalid/axis-detail-myr.html?orderNo\\\\u003d202511275929184104@dgpay_171\\\\u0026lang\\\\u003dEN\\\\u0026currency\\\\u003dMYR\\\\u0026bank\\\\u003d2060\\\"}\"}",
    //     "orderId": "202511275929184104@dgpay_171",
    //     "chargeFee": "0.7800",
    //     "processAmount": "30.0000",
    //     "amount": "30.0000",
    //     "securityToken": "824ad6613acb2f9c94e6360ed55f066d",
    //     "merchantOrderId": "202511275929184104"
    //   }
    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時 verifyCallbackSignature() 的 opCode 與 secretKey 段皆為空、
        // 簽名可離線計算 ⇒ 先擋掉整個回調。雙鍵相容故傳解析後的值而非鍵名。
        $missingCredentials = $this->findMissingCredentialValues([
            'MERCHANT' => $this->resolveOpCode(),
            'MERCHANT_KEY' => $this->resolveSecretKey(),
        ], 'deposit callback');

        if ($missingCredentials !== []) {
            return $this->responseError('credentials not configured');
        }

        // 驗證請求參數格式
        $input = $request->validate([
            'orderId' => 'required|string',
            'amount' => 'required|string',
            'status' => 'required|string',
        ]);

        // 官方回調簽名驗證（硬拒）：md5(opCode + orderId + amount + status + secretKey)
        $verifyParams = $input + ['securityToken' => (string) $request->input('securityToken', '')];
        if (!$this->verifyCallbackSignature($verifyParams)) {
            return $this->responseError('signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 解析並查詢訂單（加鎖）
            $orderNo = $this->parseOrderId($input['orderId']);
            $order = $this->findAndLockDepositOrder($orderNo);

            // 訂單不存在
            if (!$order) {
                DB::rollBack();

                return $this->responseError('Order not found');
            }

            // DgPay 特例回調冪等規則：
            // 1. 允許「失敗 → 成功」重新回調：
            //    成功回調（status=20）與失敗回調均允許在 CREATED 或 FAILED 狀態下繼續處理。
            // 2. 禁止任何回調覆寫終態成功訂單：
            //    訂單已處於終態成功（SUCCESS / DEPOSIT_FINISHED）時，
            //    無論何種回調狀態，一律返回成功響應，不執行任何狀態變更。
            $terminalSuccessStates = [
                OrderStatusEnum::SUCCESS->value,
                OrderStatusEnum::DEPOSIT_FINISHED->value,
            ];

            if (in_array($order->status, $terminalSuccessStates, true)) {
                DB::rollBack();

                return $this->responseSuccess($order);
            }

            // 訂單狀態判斷
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
            if ($input['status'] === '20') {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $input['orderId'],
                    callbackData: $input,
                    amount: $input['amount']
                );
            } elseif ($input['status'] === '-10' || $input['status'] === '-20') {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $input['orderId'],
                    callbackData: $input
                );
            } else {
                // 其他的狀態保持不變，只記錄回調數據
                $order->update([
                    'third_party_order_no' => $input['orderId'],
                    'remark' => Common::safeJsonEncode($input),
                ]);
            }

            DB::commit();

            return $this->responseSuccess($order);
        } catch (Exception $e) {
            DB::rollBack();

            $code = $e->getCode();
            $errorMessage = $e->getMessage();

            if ($code == self::ERROR_CODE) {
                // 可預期的錯誤
                return $this->responseError($errorMessage);
            } else {
                // 不可預期的錯誤
                $message = 'dgpay deposit callback error: ' . $errorMessage;
                Log::error($message, [
                    'message' => $e->getMessage(),
                    'trace' => Common::formatTrace($e),
                ]);
                BotNotify::channel('error')->send($message);

                return $this->responseError('system error');
            }
        }
    }

    private function parseOrderId(string $orderId): string
    {
        $array = explode('@', $orderId);
        if (count($array) != 2) {
            throw new Exception('order_id_format_error', self::ERROR_CODE);
        }
        $order_no = $array[0];
        $merchant = $array[1];
        if ($merchant == $this->resolveOpCode()) {
            return $order_no;
        } else {
            throw new Exception('order_id_merchant_error', self::ERROR_CODE);
        }
    }

    private function responseSuccess(Order $order): DepositCallbackResponseData
    {
        return DepositCallbackResponseData::from([
            'response' => [
                'code' => '0',
                'description' => 'Success',
            ],
            'order' => $order,
        ]);
    }

    private function responseError(string $errorMessage, ?Order $order = null): DepositCallbackResponseData
    {
        return DepositCallbackResponseData::from([
            'response' => [
                'code' => '-100',
                'description' => $errorMessage,
            ],
            'errorMessage' => $errorMessage,
            'order' => $order,
        ]);
    }
}
