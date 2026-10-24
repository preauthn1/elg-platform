<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GlobePay;

use App\Exceptions\ApiException;
use App\Exceptions\DepositOrderException;
use App\Facades\BotNotify;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\DepositCallbackResponseData;
use App\Services\Payment\Traits\DepositCallbackTrait;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DepositCallback extends AbstractMethod
{
    use DepositCallbackTrait;
    use GlobePayTrait;

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
            // 驗證必要欄位
            if (!isset($requestData['Data']) || !isset($requestData['Signature'])) {
                throw new ApiException('Missing required callback parameters');
            }

            // 解密 Data
            $decryptedData = $this->aesDecrypt($requestData['Data'], $this->info[self::FIELD_AES_KEY]);
            $data = json_decode($decryptedData, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new ApiException('Failed to parse callback data');
            }

            Log::info($this->getDriverName() . ' deposit callback received', [
                'data' => $data,
            ]);

            // 驗證 RSA 簽名
            $isValidSignature = $this->verifyRsaSignature(
                $decryptedData,
                $requestData['Signature'],
                $this->info[self::FIELD_RSA_PUBLIC_KEY]
            );

            if (!$isValidSignature) {
                throw new ApiException('Invalid signature');
            }

            /**
             * {
             * 'MerchantCode': 'TestTerprogff',
             * 'CurrencyCode': 'MYR',
             * 'MerchantTransactionId': '202602119145897801',
             * 'Status': 6,
             * 'Amount': 100.00,
             * 'NetAmount': 99.00,
             * 'Remark': null,
             * 'PaymentMethodCode': 'DN',
             * 'BankReferenceNo': null,
             * 'UniqueReferenceNo': null
             * }
             */
            // 檢查必要欄位
            if (!isset($data['MerchantTransactionId']) || !isset($data['Status']) || !isset($data['Amount'])) {
                throw new ApiException('Invalid callback data structure');
            }

            DB::beginTransaction();

            // 查詢訂單（加鎖）
            $order = $this->findAndLockDepositOrder($data['MerchantTransactionId']);

            try {
                // 檢查訂單狀態
                $this->checkDepositOrderStatus($order);
            } catch (DepositOrderException $e) {
                DB::rollBack();

                return $this->handleDepositOrderException($e, $order);
            }

            // 訂單狀態判斷（6 = Success）
            if ((int) $data['Status'] === 6) {
                // 更新訂單為成功（會自動檢查金額是否匹配）
                $this->updateDepositOrderSuccess(
                    order: $order,
                    thirdPartyOrderNo: $data['TransactionId'] ?? '',
                    callbackData: $data,
                    amount: $data['Amount']
                );
            } else {
                // 更新訂單為失敗
                $this->updateDepositOrderFailed(
                    order: $order,
                    thirdPartyOrderNo: $data['TransactionId'] ?? '',
                    callbackData: $data
                );
            }

            DB::commit();

            return $this->buildDepositSuccessResponse($order);
        } catch (Exception $e) {
            DB::rollBack();

            Log::error($this->getDriverName() . ' deposit callback exception', [
                'order_no' => $data['MerchantTransactionId'] ?? 'unknown',
                'error' => $e->getMessage(),
            ]);

            BotNotify::channel('error')->send($e->getMessage());

            throw $e;
        }
    }
}
