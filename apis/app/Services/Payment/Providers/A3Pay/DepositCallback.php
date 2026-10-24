<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\A3Pay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Order;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A3Pay 代收回調（api.md Payin Callback）。
 *
 * 信封：TransactionId（三方單號）／MerchantTransactionId／Data（AES）／AdditionalInformationData／Signature／Version。
 * Data 解密後含 MerchantTransactionId／Status／Amount，簽名對解密後的 JSON 明文驗。
 *
 * Status 語意（api.md Payin Status）：6 成功、7 失敗、其餘（含 4 Verify Fail）為**非終態**，不動訂單只 ack、等終態回調再結單。
 *
 * 金額：api.md `payinActualAmount` 說明 SGD 同收款帳戶同金額時三方會把應付金額微幅上調唯一化（10 → 10.01），
 * 2026-09-24 dev 實測回調 `Amount` 帶的就是**實付金額**（下單 10、回調 10.01、NetAmount 9.78）。
 * 會員真的多付了，所以「往上 0.10 以內」的差額採用實付金額：依 OrderService 建單同一套公式重算
 * amount／tax／point 後再結單（Ap8Pay 回調覆寫 amount／point 的既有做法），會員多付多少就入帳多少，
 * payment_cost 在 updateMemberBalance 依最終 point 計算。少付、或多付超過 0.10 仍走共用層金額不符 → WAITING_MANUAL_DEPOSIT。
 *
 * @knowledge rules/provider-a3pay
 */
class DepositCallback extends AbstractMethod
{
    use A3PayTrait;

    private const int STATUS_SUCCESS = 6;

    private const int STATUS_FAIL = 7;

    /** 回調金額高於訂單金額在此範圍內視為同金額（三方唯一化上調，見 class docblock） */
    private const string AMOUNT_UPWARD_TOLERANCE = '0.10';

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        $requestData = $request->all();
        $data = null;

        try {
            if (!isset($requestData['Data'], $requestData['Signature'])) {
                throw new ApiException('Missing required callback parameters');
            }

            // 先解密，再對明文驗簽（api.md「Verify Signature」）
            $decryptedData = $this->aesDecrypt($requestData['Data'], $this->info[self::FIELD_AES_KEY]);
            $data = json_decode($decryptedData, true);

            if (!is_array($data)) {
                throw new ApiException('Failed to parse callback data');
            }

            Log::info($this->getDriverName() . ' deposit callback received', ['data' => $data]);

            if (!$this->verifyRsaSignature($decryptedData, $requestData['Signature'], $this->info[self::FIELD_RSA_PUBLIC_KEY])) {
                throw new ApiException('Invalid signature');
            }

            if (!isset($data['MerchantTransactionId'], $data['Status'], $data['Amount'])) {
                throw new ApiException('Invalid callback data structure');
            }

            // 外部數值入境即正規化；非數值直接拒絕，不讓它流進金額比對
            if (!is_numeric($data['Amount'])) {
                throw new ApiException('Invalid callback amount');
            }

            $amount = Common::numAdd('0', (string) $data['Amount'], 4);

            DB::beginTransaction();

            $order = $this->findAndLockDepositOrder((string) $data['MerchantTransactionId']);

            try {
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            $status = (int) $data['Status'];

            // 非終態（4 Verify Fail、其餘 Processing）：不動訂單、只 ack
            if ($status !== self::STATUS_SUCCESS && $status !== self::STATUS_FAIL) {
                DB::rollBack();

                Log::info($this->getDriverName() . ' deposit callback non-final status, keep order CREATED', [
                    'order_no' => $order->order_no,
                    'status' => $status,
                ]);

                return $this->buildDepositSuccessResponse($order);
            }

            // 三方單號在信封層 TransactionId，解密後的 Data 內沒有
            $thirdPartyOrderNo = (string) ($requestData['TransactionId'] ?? '');

            if ($status === self::STATUS_SUCCESS) {
                // 三方唯一化上調（往上 ≤ 0.10）：會員實付較多，先把訂單改成實付金額再結單；其餘不符由共用層轉人工確認單
                if ($this->isUpwardAdjustment((string) $order->amount, $amount, $order->order_no)) {
                    $order->update($this->recalculateForActualAmount($order, $amount));
                }

                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $data,
                    amount: $amount
                );
            } else {
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $data
                );
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Throwable $e) {
            // 接 Throwable 而非 Exception：金額比對拋的 ValueError 屬 Error，漏接會讓交易與鎖留到連線結束
            DB::rollBack();

            Log::error($this->getDriverName() . ' 代收回調處理失敗', [
                'order_no' => $data['MerchantTransactionId'] ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 回調金額是否為三方唯一化上調：0 < 回調金額 − 訂單金額 ≤ AMOUNT_UPWARD_TOLERANCE。
     * 少付、或多付超過門檻都回 false，交共用層當金額不符處理。
     */
    private function isUpwardAdjustment(string $orderAmount, string $callbackAmount, string $orderNo): bool
    {
        $diff = Common::numSub($callbackAmount, $orderAmount, 4);

        if (Common::numCompare($diff, '0', 4) <= 0 || Common::numCompare($diff, self::AMOUNT_UPWARD_TOLERANCE, 4) > 0) {
            return false;
        }

        Log::info($this->getDriverName() . ' 回調金額為三方唯一化上調，訂單改以實付金額入帳', [
            'order_no' => $orderNo,
            'order_amount' => $orderAmount,
            'callback_amount' => $callbackAmount,
            'diff' => $diff,
        ]);

        return true;
    }

    /**
     * 以實付金額重算訂單金額欄位，公式與 OrderService 建單一致：
     * point = amount × rate；tax = 商戶固定手續費 + point × 商戶浮動手續費率；point = point − tax。
     * rate 沿用訂單建單時的值（匯率不因回調改變）。
     *
     * @return array{amount: string, tax: string, point: string}
     */
    private function recalculateForActualAmount(Order $order, string $actualAmount): array
    {
        $rate = Common::numAdd('0', (string) ($order->rate ?? 1), 9);
        $grossPoint = Common::numMul($actualAmount, $rate, 4);
        $percentage = Common::numMul($grossPoint, (string) ($this->agentCashFlow->tax_percentage ?? 0), 4);
        $tax = Common::numAdd((string) ($this->agentCashFlow->tax ?? 0), $percentage, 4);

        return [
            'amount' => Common::numFloor($actualAmount, 4),
            'tax' => $tax,
            'point' => Common::numSub($grossPoint, $tax, 4),
        ];
    }
}
