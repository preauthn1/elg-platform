<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\QuickPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use QuickPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'QUICKPAY';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        $clientId = $this->info['CLIENT_ID'] ?? null;

        // 優先使用已查詢好的 order，避免重複查詢 DB
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        $params = [
            'uid' => $clientId,
            'orderid' => $transactionNo,
            'channel' => 712,
            'notify_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'amount' => $withdrawalOrder->amount,
            'userip' => Common::getIp(),
            'timestamp' => time(),
            'custom' => '',
            'bank_account' => $withdrawalOrder->account_name,
            'bank_no' => $withdrawalOrder->account_no,
            'bank_id' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'bank_province' => '',
            'bank_city' => '',
            'bank_sub' => '',
            'user_name' => $withdrawalOrder->account_name,
        ];
        $params['sign'] = $this->generateSignature($params, $this->info['SECRET_KEY']);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/applyfor', $params),
            // 嚴格判斷：必須 status == 10000 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? null) == 10000,
                thirdPartyOrderNo: (string) ($result['result']['transactionid'] ?? ''),
                errorMessage: $this->resolveStatusMessage((int) ($result['status'] ?? 0)),
            ),
        );
    }
}
