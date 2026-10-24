<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\BillionPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use BillionPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'true';

    protected string|array $notifyFailText = 'fail';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * @throws \Throwable
     * @throws DepositOrderException
     * @throws ApiException
     */
    public function handle(Request $request): DepositCallbackResponseData
    {
        // 驗證請求參數格式
        $input = $request->validate([
            'transaction_reference_id' => 'required|string',
            'bank_reference_id' => 'nullable',
            'customer_id' => 'required|string',
            'method' => 'required|string', // card
            'currency' => 'required|string',
            'amount' => 'required|string', // Deposit Amount
            'actual_amount' => 'required|string', // Actual Received Amount
            'fee' => 'required|string', // Fee
            'status' => 'required|string',
            'bank' => 'required|string',
            'bank_account_name' => 'required|string',
            'bank_account_number' => 'required|string',
            'full_name' => 'required|string',
            'signature' => 'required|string',
        ]);

        // 驗證簽名
        if ($this->checkSign($input, $this->info['SECRET_KEY']) === false) {
            throw new ApiException('Signature verification failed');
        }

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['transaction_reference_id']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            $status = $input['status'] ?? '';
            $thirdPartyOrderNo = (string) ($input['bank_reference_id'] ?? '');

            if ($status === 'completed') {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input,
                    amount: $input['actual_amount']
                );
            } elseif ($status === 'pending') {
                // 處理中，不更新訂單狀態，只記錄日誌
                Log::info($this->getDriverName() . ' deposit order processing', [
                    'orderNo' => $input['transaction_reference_id'],
                    'thirdPartyOrderNo' => $thirdPartyOrderNo,
                    'status' => $status,
                ]);
                DB::rollBack();

                return DepositCallbackResponseData::from([
                    'order' => $order,
                    'response' => $this->notifySuccessText,
                    'errorMessage' => 'Order is processing',
                ]);
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $thirdPartyOrderNo,
                    callbackData: $input
                );
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
