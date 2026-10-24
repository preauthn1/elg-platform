<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepositCallback extends AbstractMethod
{
    use ZPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string|array $notifySuccessText = 'OK';

    protected string|array $notifyFailText = 'FAIL';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理 ZPay 代收回調
     *
     * @param  Request  $request  回調請求
     * @return DepositCallbackResponseData 回調響應
     *
     * @throws ApiException 當簽名驗證失敗時
     */
    public function handle(Request $request): DepositCallbackResponseData
    {
        // ZPay 回調參數驗證
        $input = $request->validate([
            'referenceId' => 'required|string',
            'itemDesc' => 'required|string',
            'status' => 'required|integer|between:1,8',
            'transactionId' => 'required|integer',
            'amount' => 'required|numeric',
            'currency' => 'required|string',
            'sourceBank' => 'nullable|string',
            'sourceAccountNo' => 'nullable|string',
            'sourceAccountName' => 'nullable|string',
            'transactionDate' => 'required|string',
        ]);

        $credentials = $this->getMerchantCredentials();

        try {
            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($input['referenceId']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            $extraContent = Common::safeJsonDecode($order?->extra_content);
            $storedItemDesc = (string) ($extraContent['zpay_item_desc'] ?? '');
            if ($storedItemDesc === '') {
                throw new ApiException(sprintf('%s deposit callback itemDesc missing', $this->getDriverName()));
            }

            if (!$this->verifyCallbackItemDesc($storedItemDesc, $credentials->secretKey, $input['itemDesc'])) {
                throw new ApiException(
                    sprintf('%s deposit callback signature verification failed', $this->getDriverName())
                );
            }

            // 解析狀態碼
            $statusEnum = ZPayStatusCodeEnum::fromValue((int) $input['status']);

            // 根據狀態處理訂單
            if ($statusEnum->isSuccess()) {
                // 狀態 1 (ON) - 更新訂單為成功
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: (string) $input['transactionId'],
                    callbackData: $input,
                    amount: (string) $input['amount']
                );
            } elseif ($statusEnum->isFailed()) {
                // 狀態 (VOIDED,FAILED,REJECTED,FORFEIT,REFUNDED) - 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: (string) $input['transactionId'],
                    callbackData: $input
                );
            } else {
                // 其他狀態 (PROCESSING, PENDING)
                DB::rollBack();

                return DepositCallbackResponseData::from([
                    'response' => $this->successResponse(),
                    'order' => $order,
                ]);
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            BotNotify::channel('error')->send(
                sprintf('%s deposit callback error: %s', $this->getDriverName(), $e->getMessage())
            );

            throw $e;
        }
    }
}
