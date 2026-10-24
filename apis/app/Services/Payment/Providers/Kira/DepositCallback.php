<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Kira;

use App\Enums\OrderStatusEnum;
use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use KiraTrait;

    protected array $info;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(Request $request): DepositCallbackResponseData
    {
        $symmetricKey = $this->info['CLIENT_SYMMETRIC_KEY'] ?? null;
        if (!$symmetricKey) {
            throw new ApiException(sprintf('%s configuration missing: CLIENT_SYMMETRIC_KEY', $this->getDriverName()));
        }

        $input = $request->validate([
            'encryptedData' => 'required|string',
        ]);

        $encryptedData = $input['encryptedData'];
        if (empty($encryptedData)) {
            Log::warning($this->getDriverName() . ' Deposit Callback missing encryptedData', [
                'request' => $request->all(),
            ]);
            throw new ApiException('Missing encryptedData in callback');
        }

        try {
            $callbackData = $this->decryptCallbackData($encryptedData);
        } catch (\Exception $e) {
            Log::error($this->getDriverName() . ' Deposit Callback decryption failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        Log::info($this->getDriverName() . ' Deposit Callback Received', [
            'status' => $callbackData['data']['status'] ?? null,
            'order_id' => $callbackData['data']['order']['id'] ?? null,
            'transaction_id' => $callbackData['data']['transaction']['id'] ?? null,
        ]);

        // 取得訂單資訊
        $orderData = $callbackData['data']['order'] ?? [];
        $transactionData = $callbackData['data']['transaction'] ?? [];
        $status = $callbackData['data']['status'] ?? '';

        $orderNo = $orderData['id'] ?? null;
        if (!$orderNo) {
            throw new ApiException('Missing order id in callback data');
        }

        DB::beginTransaction();
        try {
            // 查詢並鎖定訂單
            $order = $this->findAndLockDepositOrder($orderNo);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 解析狀態
            $resultStatus = match (strtoupper($status)) {
                'SUCCESS' => OrderStatusEnum::SUCCESS->value,
                'PENDING' => OrderStatusEnum::CREATED->value,
                'FAILED' => OrderStatusEnum::FAILED->value,
                default => OrderStatusEnum::FAILED->value,
            };

            // 三方訂單號
            $thirdPartyOrderNo = (string) ($transactionData['id'] ?? '');

            if ($resultStatus === OrderStatusEnum::SUCCESS->value) {
                $callbackAmount = $transactionData['amountPaid'] ?? null;
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $callbackData,
                    amount: $callbackAmount,
                );
            } elseif ($resultStatus === OrderStatusEnum::FAILED->value) {
                $this->updateDepositOrderFailed($order, $thirdPartyOrderNo, $callbackData);
            } else {
                // PENDING 狀態
                $order->update([
                    'status' => $resultStatus,
                    'third_party_order_no' => $thirdPartyOrderNo ?: $order->third_party_order_no,
                    'remark' => Common::safeJsonEncode($callbackData),
                ]);
            }

            DB::commit();

            Log::info($this->getDriverName() . ' Deposit Callback Processed', [
                'order_id' => $order->id,
                'order_no' => $orderNo,
                'status' => $resultStatus,
                'third_party_order_no' => $thirdPartyOrderNo,
                'amount_paid' => $transactionData['amountPaid'] ?? null,
            ]);

            return DepositCallbackResponseData::from([
                'order' => $order,
                'response' => $this->notifySuccessText,
                'httpStatusCode' => 200,
                'errorMessage' => null,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' Deposit Callback Exception', [
                'order_no' => $orderNo,
                'error' => $e->getMessage(),
                'trace' => Common::formatTrace($e),
            ]);

            throw $e;
        }
    }
}
