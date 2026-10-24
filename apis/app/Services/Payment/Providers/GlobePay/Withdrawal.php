<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\GlobePay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Traits\WithdrawalPayoutTrait;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use GlobePayTrait;
    use WithdrawalPayoutTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'GlobePay';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理代付請求
     */
    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        // 優先使用已查詢好的 order，避免重複查詢 DB
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/api/Withdrawal/SubmitWithdrawal', $params),
            interpretResult: function (array $result): PayoutResult {
                // 驗證返回結果
                if (($result['isSuccess'] ?? null) !== true) {
                    return new PayoutResult(isSuccess: false, errorMessage: $result['errorMessage'] ?? 'Unknown error');
                }

                // 檢查是否有資料
                if (empty($result['data'])) {
                    return new PayoutResult(isSuccess: false, errorMessage: 'No data returned');
                }

                return new PayoutResult(
                    isSuccess: true,
                    thirdPartyOrderNo: $result['data']['transactionId'] ?? '',
                );
            },
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        return [
            'MerchantCode' => $this->info[self::FIELD_MERCHANT_CODE],
            'MerchantTransactionId' => $transactionNo,
            'MerchantClientId' => $withdrawalOrder->owner->username,
            'CurrencyCode' => $this->info[self::FIELD_CURRENCY],
            'Amount' => sprintf('%.2f', $withdrawalOrder->amount),
            'DestinationClientBankCode' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'DestinationClientAccountNumber' => $withdrawalOrder->account_no ?? '',
            'DestinationClientAccountHolderName' => $withdrawalOrder->account_name ?? '',
            'DestinationClientProvince' => '',
            'DestinationClientCity' => '',
            'DestinationClientBranch' => '',
            'NotifyUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'ReturnUrl' => Common::withdrawalQueryUrl($this->agentCashFlow->id),
            'IPAddress' => Common::getIp(),
            'PaymentMethodCode' => 'WD',
            'DestinationClientBranchCode' => '',
        ];
    }
}
