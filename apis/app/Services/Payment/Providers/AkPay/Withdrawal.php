<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\AkPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use AkPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    // 訂單前綴
    protected string $orderPrefix = 'AK_';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
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

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/api/withdrawal/create', $params),
            interpretResult: function (array $result): PayoutResult {
                // data 在三方格式漂移時可能回成純量；以 is_array 守衛避免 reset() 拋 TypeError，
                // 確保「受理判定 success」不被「組錯誤訊息 data」拖累
                $dataErrors = $result['data'] ?? [];
                $firstFieldErrors = is_array($dataErrors) && !empty($dataErrors) ? reset($dataErrors) : [];
                $errorMessage = (is_array($firstFieldErrors) ? ($firstFieldErrors[0] ?? null) : null)
                    ?? $result['message'] ?? 'Unknown error';

                return new PayoutResult(
                    isSuccess: ($result['success'] ?? null) === true,
                    thirdPartyOrderNo: $result['data']['tx_id'] ?? '',
                    errorMessage: $errorMessage,
                );
            },
        );
    }

    /**
     * 構建代付請求參數
     *
     * @throws \Exception
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo): array
    {
        return [
            'merchant_code' => $this->info['MERCHANT_CODE'],
            'source_id' => $transactionNo,
            'payment_method' => 'Bank',
            'bank_code' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'account_holder_name' => $withdrawalOrder->account_name,
            'account_number' => $withdrawalOrder->account_no,
            'currency' => $this->info['CURRENCY'] ?? 'MYR',
            'amount' => Common::numFloor($withdrawalOrder->amount, 2), // 保留兩位小數
            'callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
        ];
    }
}
