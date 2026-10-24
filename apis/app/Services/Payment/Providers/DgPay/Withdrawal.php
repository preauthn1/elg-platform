<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\DgPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\Member;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class Withdrawal extends AbstractMethod
{
    protected InfoData $infoData;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'DG';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function setInfo(array $info): void
    {
        parent::setInfo($info);

        $this->infoData = InfoData::mapInfo($info);
    }

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
        $endpoint = $this->infoData->version === 'V2' ? '/v2/withdraw' : '/withdraw';

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest($endpoint, $params),
            // DgPay 成功條件：code === '0'（兼容數字 0）；refId 為三方代付訂單號
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: (string) ($result['code'] ?? '') === '0',
                thirdPartyOrderNo: (string) ($result['refId'] ?? ''),
                errorMessage: (string) ($result['description'] ?? $result['providerMsg'] ?? 'Unknown error'),
            ),
        );
    }

    /**
     * 構建代付請求參數（FORM-DATA）。
     *
     * 依 info.VERSION 分流：
     * - V2（SGD）：/v2/withdraw，帶 recipientInfo，無 providerId/providerType/data。
     * - V1（MYR/IDR/其他）：/withdraw，帶 providerId/providerType/data。
     *
     * 參照舊系統 App\ThirdPartyApi\DgPayIntegrationHelper::RequestWithdrawalOrder。
     * orderId 帶我方交易號（transaction_no），回調以此比對 WithdrawalPayoutRecord。
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        $currency = strtoupper($this->infoData->currency);

        if ($this->infoData->version === 'V2') {
            return [
                'opCode' => $this->infoData->merchant,
                'orderId' => $transactionNo,
                'currency' => $currency,
                'amount' => $withdrawalOrder->amount,
                'callbackUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
                'reqDateTime' => Carbon::now()->toFrontendTime()->toDateTimeString(),
                'recipientInfo' => json_encode(
                    $this->buildRecipientInfo($withdrawalOrder),
                    JSON_UNESCAPED_SLASHES
                ),
            ];
        }

        return [
            'orderId' => $transactionNo,
            'providerId' => $this->infoData->providerId,
            'providerType' => $this->infoData->providerType,
            'currency' => $currency,
            'amount' => $withdrawalOrder->amount,
            'callbackUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'reqDateTime' => Carbon::now()->toFrontendTime()->toDateTimeString(),
            'opCode' => $this->infoData->merchant,
            'data' => json_encode(
                $this->buildChannelData($withdrawalOrder, $currency),
                JSON_UNESCAPED_SLASHES
            ),
        ];
    }

    /**
     * V2 代付收款人資訊（官方 SGD V2 §3.2）。
     *
     * 全名塞 receiverFirstName、receiverLastName 留空，不硬拆。
     *
     * @return array<string, string>
     */
    private function buildRecipientInfo(WithdrawalOrder $withdrawalOrder): array
    {
        $username = Member::find($withdrawalOrder->member_id)?->username ?? '';

        return [
            'receiverFirstName' => (string) $withdrawalOrder->account_name,
            'receiverLastName' => '',
            'receiverNo' => (string) $withdrawalOrder->account_no,
            'username' => $username,
            'bankCode' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
        ];
    }

    /**
     * 依幣別/通道構建 data 內層欄位（三方代付收款人資訊）。
     *
     * 參照舊系統 DgPayIntegrationHelper::getRequestData：
     * - MYR Manual（providerId=10, providerType=10）：bankName / accNo / accName
     * - MYR Auto：toBankCode / toAccNo / toAccName
     * - IDR：accNo / bankCode
     * - 其餘（SGD / THB 等）：bankName / accNo / accName
     *
     * @return array<string, string>
     */
    private function buildChannelData(WithdrawalOrder $withdrawalOrder, string $currency): array
    {
        $bankCode = $this->convertPayoutBankCode($withdrawalOrder->bank_code);

        return match ($currency) {
            'MYR' => $this->infoData->providerId === 10 && $this->infoData->providerType === 10
                ? [
                    // Manual 要傳完整銀行名稱而不是銀行代碼
                    'bankName' => $withdrawalOrder->bank_name,
                    'accNo' => $withdrawalOrder->account_no,
                    'accName' => $withdrawalOrder->account_name,
                ]
                : [
                    // Auto
                    'toBankCode' => $bankCode,
                    'toAccNo' => $withdrawalOrder->account_no,
                    'toAccName' => $withdrawalOrder->account_name,
                ],
            'IDR' => [
                'accNo' => $withdrawalOrder->account_no,
                'bankCode' => $bankCode,
            ],
            default => [
                'bankName' => $bankCode,
                'accNo' => $withdrawalOrder->account_no,
                'accName' => $withdrawalOrder->account_name,
            ],
        };
    }

    /**
     * 發送 FORM-DATA 請求並附上 MD5 簽名。
     */
    protected function sendRequest(string $endpoint, array $params): array
    {
        $action = $this->getDriverName() . '_withdrawal';

        $url = rtrim($this->infoData->apiUrl, '/') . $endpoint;
        $params['securityToken'] = $this->generateSignature($params);

        $result = Http::logAction($action)
            ->asForm()
            ->post($url, $params)
            ->throw()
            ->json();

        return $result;
    }

    /**
     * MD5 簽名（小寫），依版本分流。
     *
     * V1：orderId + providerId + providerType + currency + amount + reqDateTime + opCode + merchantKey
     * V2：opCode + orderId + currency + amount + callbackUrl + reqDateTime + recipientInfo + merchantKey
     *
     * payload 與簽名共用同一欄位值，確保三方驗簽通過。
     */
    protected function generateSignature(array $params): string
    {
        if ($this->infoData->version === 'V2') {
            $string = $params['opCode']
                . $params['orderId']
                . $params['currency']
                . $params['amount']
                . $params['callbackUrl']
                . $params['reqDateTime']
                . $params['recipientInfo']
                . $this->infoData->merchantKey;
        } else {
            $string = $params['orderId']
                . $params['providerId']
                . $params['providerType']
                . $params['currency']
                . $params['amount']
                . $params['reqDateTime']
                . $params['opCode']
                . $this->infoData->merchantKey;
        }

        return strtolower(md5($string));
    }
}
