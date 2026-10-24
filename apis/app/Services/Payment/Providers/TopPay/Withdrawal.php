<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TopPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use TopPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'TopPay';

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

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/disbursement/cash', $params),
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['code'] ?? null) === '9999',
                thirdPartyOrderNo: $result['data']['platOrderNum'] ?? '',
                errorMessage: $result['msg'] ?? 'Unknown error',
            ),
        );
    }

    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        $thirdBankCode = $this->convertPayoutBankCode($withdrawalOrder->bank_code);

        $params = [
            'mchNo' => $this->info[$this->merchantCodeField],
            'orderNum' => $transactionNo,
            'amount' => (int) $withdrawalOrder->amount,
            'bankCode' => $thirdBankCode,
            'bankCard' => $withdrawalOrder->account_no ?? '',
            'accountName' => $withdrawalOrder->account_name,
            'description' => 'Withdrawal',
            'feeType' => 1,
            'downNotifyUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'timestamp' => (string) now()->getTimestampMs(),
        ];

        $params['sign'] = $this->generateSign($params);

        return $params;
    }
}
