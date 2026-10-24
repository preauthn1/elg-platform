<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\FlashPay;

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
    use FlashPayTrait;

    protected array $info;

    protected string $orderPrefix = 'FLASH';

    public function __construct(protected AgentCashFlow $agentCashFlow)
    {
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
            performRequest: fn (): array => $this->sendRequest('/payout', $params),
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status_code'] ?? null) === 0,
                thirdPartyOrderNo: $result['tran_token'] ?? '',
                errorMessage: $result['status_msg'] ?? 'Unknown error',
            ),
        );
    }

    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        $params = [
            'client_id' => $this->info['CLIENT_ID'],
            'currency' => $this->info['CURRENCY'],
            'tran_customer' => $withdrawalOrder->member->username,
            'bank_code' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'bank_account_name' => $withdrawalOrder->account_name,
            'bank_account_number' => $withdrawalOrder->account_no,
            'tran_amount' => (int) Common::numFloor($withdrawalOrder->amount, 0),
            'tran_unique' => $transactionNo,
            'tran_callback_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
        ];

        $params['tran_signature'] = $this->generateSign($params, $this->info['SECRET_KEY']);

        return $params;
    }
}
