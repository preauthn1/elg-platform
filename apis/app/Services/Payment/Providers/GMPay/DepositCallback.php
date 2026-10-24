<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GMPay;

use App\Enums\General\HttpCode;
use App\Enums\OrderStatusEnum;
use App\Exceptions\DepositOrderException;
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

/**
 * GMPay 代收回調（standalone，不繼承 MajesticPay）。
 *
 * GMPay V1.5 與 MajesticPay 的代收回調機制已分歧（工單 #2435）：
 * - 驗簽走 `GMPayTrait::checkHash`（transaction_reference + amount + currency）。
 * - 帶 `origin_amount` 時，以實付 `amount` 重算 orders.amount / orders.point（湊零頭自動入帳），
 *   MajesticPay 則走 is_repush 人工機制、無 origin_amount。
 *
 * @knowledge rules/payment-callback-common-checks
 */
class DepositCallback extends AbstractMethod
{
    use GMPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

    /**
     * GMPay V1.5 湊零頭調整的自動入帳上限（實付 amount − 原始 origin_amount）。
     * 超出此範圍視為異常，轉人工確認，避免三方誤送放大金額直接上分。
     */
    private const PAID_AMOUNT_ADJUSTMENT_CAP = '1.00';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        // 憑證未配置時簽名的 secret／key 段為空、簽名可離線計算，
        // 攻擊者可偽造成功回調直接上分 ⇒ 先擋掉整個回調。
        // 對外沿用與簽名錯誤相同的回應，不洩露憑證未配置（真實原因由守衛記 log）。
        if ($this->findMissingCredentials(['SECRET_KEY'], 'deposit callback') !== []) {
            return $this->buildFailResponse('Invalid signature');
        }

        // 驗證請求參數格式
        $input = $request->validate($this->depositCallbackRules());

        // 驗證簽名
        if (!$this->checkHash($input, $this->info['SECRET_KEY'])) {
            return $this->buildFailResponse('Invalid signature');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['ref_no']);

            // 額外驗證：確保 third_party_order_no 與回調一致
            if ($order && $order->third_party_order_no !== $input['transaction_reference']) {
                DB::rollBack();
                Log::warning($this->getDriverName() . ' callback transaction_reference mismatch', [
                    'order_no' => $input['ref_no'],
                    'expected' => $order->third_party_order_no,
                    'received' => $input['transaction_reference'],
                ]);

                return $this->buildFailResponse('Transaction reference mismatch');
            }

            try {
                // 檢查訂單狀態（冪等）
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                Log::warning($this->getDriverName() . ' callback rejected by order status', [
                    'orderNo' => $input['ref_no'],
                    'code' => $e->getCode(),
                    'error' => $e->getMessage(),
                ]);

                // 已處理過的訂單：返回成功避免重複回調
                if ($e->getCode() === HttpCode::HTTP_OK->value) {
                    return $this->buildSuccessResponse();
                }

                return $this->buildFailResponse($e->getMessage());
            }

            // 判斷訂單狀態
            $orderStatus = $this->determineOrderStatus($input, $order);

            $updatePayload = [
                'status' => $orderStatus,
                'remark' => Common::safeJsonEncode($input),
            ];

            // GMPay V1.5：帶 origin_amount 時，趕在上分前以實付 amount 重算入帳金額。
            $orderStatus = $this->applyOriginAmountAdjustment($input, $order, $updatePayload, $orderStatus);

            // 更新訂單
            $order->update($updatePayload);

            DB::commit();

            return $orderStatus === OrderStatusEnum::FAILED->value
                ? $this->buildFailResponse('Order processing failed', $order)
                : $this->buildSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' callback exception', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }

    /**
     * 代收回調的驗證規則（含 GMPay V1.5 的 origin_amount）。
     *
     * @return array<string, string>
     */
    private function depositCallbackRules(): array
    {
        return [
            'status' => 'required|string',
            'currency' => 'required|string',
            'amount' => 'required|string',
            'hash' => 'nullable|string',
            'ref_no' => 'required|string',
            'platform_charge' => 'required|string',
            'transaction_reference' => 'required|string',
            'updated_at' => 'required|string',
            'is_sandbox' => 'required|integer|in:0,1',
            'is_repush' => 'nullable|integer|in:0,1',
            'repush_amount' => 'nullable|string',
            'telco' => 'nullable|string',
            'origin_amount' => 'nullable|string',
        ];
    }

    /**
     * 判斷訂單狀態
     *
     * @param  array<string, mixed>  $input
     */
    private function determineOrderStatus(array $input, Order $order): int
    {
        // Sandbox 模式：直接標記成功
        if ($input['is_sandbox'] === 1) {
            return OrderStatusEnum::SUCCESS->value;
        }

        // 補推金額（金額不符需人工確認）
        if (($input['is_repush'] ?? 0) !== 0) {
            return OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value;
        }

        return $input['status'] === 'completed'
            ? OrderStatusEnum::SUCCESS->value
            : OrderStatusEnum::FAILED->value;
    }

    /**
     * GMPay V1.5：以回調實付 amount 重算 orders.amount / orders.point。
     *
     * 三方為避免金額重複會將實付金額微調（origin_amount = 原始下單、amount = 實付），兩者小幅差異屬正常。
     * 回調 hash 已簽章 amount（transaction_reference + amount + currency），採信 amount 入帳；
     * 防護僅比對 callback 內自洽的 origin_amount ↔ amount 差距是否在合理範圍，不與我方 order.amount 互比
     * （湊零頭下 origin_amount 未必逐分等於 order.amount）。趕在 controller 上分前覆寫，讓上分依更新後的 point。
     *
     * @param  array<string, mixed>  $input  已驗證的回調欄位
     * @param  array<string, mixed>  $updatePayload  訂單更新負載（by-reference 覆寫 amount / point / status）
     * @param  int  $orderStatus  determineOrderStatus 判定的狀態
     * @return int 最終訂單狀態（成功 SUCCESS；逾越門檻 WAITING_MANUAL_DEPOSIT）
     */
    private function applyOriginAmountAdjustment(array $input, Order $order, array &$updatePayload, int $orderStatus): int
    {
        // 僅在回調成功且帶 origin_amount（V1.5）時介入；否則沿用原判定。
        if ($orderStatus !== OrderStatusEnum::SUCCESS->value || !isset($input['origin_amount'])) {
            return $orderStatus;
        }

        $paidAmount = Common::numAdd('0', (string) $input['amount'], 4);
        $originAmount = Common::numAdd('0', (string) $input['origin_amount'], 4);

        // 防護：實付 amount 為 origin_amount 的湊零頭上調，應落在 origin_amount ~ origin_amount + CAP；
        // 逾越範圍（含實付小於原始）視為異常，轉人工確認，避免三方誤送放大金額直接上分。
        $upperBound = Common::numAdd($originAmount, self::PAID_AMOUNT_ADJUSTMENT_CAP, 4);
        $withinRange = Common::numCompare($paidAmount, $originAmount, 4) >= 0
            && Common::numCompare($paidAmount, $upperBound, 4) <= 0;

        if (!$withinRange) {
            Log::warning($this->getDriverName() . ' callback paid amount out of range, routing to manual', [
                'orderNo' => $order->order_no,
                'orderId' => $order->id,
                'originAmount' => $originAmount,
                'paidAmount' => $paidAmount,
                'cap' => self::PAID_AMOUNT_ADJUSTMENT_CAP,
            ]);

            BotNotify::channel('warn')->send(sprintf(
                '%s 代收回調實付金額逾越自動入帳範圍，轉人工確認。訂單號：%s，原始金額：%s，實付金額：%s',
                $this->getDriverName(),
                $order->order_no,
                $originAmount,
                $paidAmount
            ));

            $updatePayload['status'] = OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value;

            return OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value;
        }

        // 以實付 amount 重算入帳：orders.amount = 實付、orders.point = amount × rate
        // （GMPay tax=0，維持 order.point = order.amount × order.rate 的不變式）。
        $updatePayload['amount'] = $paidAmount;
        $updatePayload['point'] = Common::numMul($paidAmount, (string) $order->rate, 4);

        return OrderStatusEnum::SUCCESS->value;
    }

    /**
     * 建構成功響應
     */
    private function buildSuccessResponse(?Order $order = null): DepositCallbackResponseData
    {
        return DepositCallbackResponseData::from([
            'response' => 'OK',
            'order' => $order,
            'httpStatusCode' => HttpCode::HTTP_OK->value,
        ]);
    }

    /**
     * 建構失敗響應
     */
    private function buildFailResponse(string $message, ?Order $order = null): DepositCallbackResponseData
    {
        return DepositCallbackResponseData::from([
            'response' => 'Failed',
            'errorMessage' => $message,
            'order' => $order,
            'httpStatusCode' => HttpCode::HTTP_BAD_REQUEST->value,
        ]);
    }
}
