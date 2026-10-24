<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\MaxPay;

use App\Exceptions\ApiException;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use MaxPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo('MAX');
        $currency = $this->agentCashFlow->currency->code ?? '';
        $amount = sprintf('%.2f', $withdrawalOrder->amount);

        $methodType = $this->convertPayoutBankCode($withdrawalOrder->bank_code);
        if (empty($methodType)) {
            return $this->createFailureResponse('Unsupported bank code: ' . $withdrawalOrder->bank_code);
        }

        $requestData = [
            'api_key' => $this->info['API_KEY'],
            'amount' => $amount,
            'currency' => $currency,
            'hash' => $this->generateHash($this->info['API_KEY'], $transactionNo, $amount, $this->info['SECRET_KEY']),
            'bank_name' => $methodType,
            'bank_holder_name' => $withdrawalOrder->account_name,
            'bank_account' => $withdrawalOrder->account_no,
            'callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'ref_no' => $transactionNo,
        ];

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $requestData,
            // 例外分類原則：只有「結構完整且業務碼明確拒絕（status=false）」才交 interpretResult 判 -2；
            // 其餘（連線例外 / HTTP 非 2xx / 結構缺失 / status=true 卻缺單號）一律在此拋 → -1（結果未知，訂單停留 21）
            performRequest: function () use ($requestData): array {
                $result = $this->sendRequestRaw('/api/create_payout', $requestData);

                // 2xx 但解不出布林業務 status（body 非 JSON / 缺鍵 / null / 非布林型別）＝結構缺失：
                // 文檔僅定義布林 true/false（api.md），非布林無依據斷定未建單 → 結果未知
                if (!is_bool($result['status'] ?? null)) {
                    throw new ApiException(sprintf('%s unparseable 2xx response', $this->getDriverName()));
                }

                // status=true＝三方已受理：缺 transaction_reference＝結構異常，不能排除已進單
                if ($result['status'] === true && $this->extractTransactionReference($result) === '') {
                    throw new ApiException(sprintf('%s accepted (status=true) but payload missing transaction_reference', $this->getDriverName()));
                }

                return $result;
            },
            // 到得了這裡的只有兩種形狀：status=false 的明確拒絕（→ -2），或帶 transaction_reference 的受理
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? false) === true,
                thirdPartyOrderNo: $this->extractTransactionReference($result),
                errorMessage: sprintf('%s API error: %s', $this->getDriverName(), $result['message'] ?? 'Unknown error'),
            ),
        );
    }

    /**
     * 取代付受理單號：PDF 記載於 result.transaction_reference、codebase 既有讀取為
     * data.transaction_reference（見 docs/integrations/payments/MaxPay/api.md 差異說明），
     * 實際路徑未經連線測試定案前雙路徑容錯，避免其一為真時成功單被誤判為結果未知。
     */
    private function extractTransactionReference(array $result): string
    {
        return (string) ($result['data']['transaction_reference'] ?? $result['result']['transaction_reference'] ?? '');
    }
}
