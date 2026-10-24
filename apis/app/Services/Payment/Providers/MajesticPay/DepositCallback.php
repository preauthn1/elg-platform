<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MajesticPay;

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

class DepositCallback extends AbstractMethod
{
    use MajesticPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'success';

    protected string|array $notifyFailText = 'fail';

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
        $input = $request->validate([
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
        ]);

        // 驗證簽名
        if (!$this->checkHash($input, $this->info['SECRET_KEY'])) {
            return $this->buildFailResponse('Invalid signature');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['ref_no']);

            // MajesticPay 額外驗證：確保 third_party_order_no 與回調一致
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
                // 檢查訂單狀態
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

            // 更新訂單
            $order->update([
                'status' => $orderStatus,
                'remark' => Common::safeJsonEncode($input),
            ]);

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
     * 判斷訂單狀態
     */
    private function determineOrderStatus(array $input, Order $order): int
    {
        // Sandbox 模式：直接標記成功
        if ($input['is_sandbox'] === 1) {
            return OrderStatusEnum::SUCCESS->value;
        }

        // 檢查是否為補推金額（金額不符需人工確認）
        if (($input['is_repush'] ?? 0) !== 0) {
            return OrderStatusEnum::WAITING_MANUAL_DEPOSIT->value;
        }

        // 根據狀態標記訂單
        return $input['status'] === 'completed'
            ? OrderStatusEnum::SUCCESS->value
            : OrderStatusEnum::FAILED->value;
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
