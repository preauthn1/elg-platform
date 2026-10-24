<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\EpicPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Carbon;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use EpicPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'Epic';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        $action = 'sPayout';

        // 優先使用已查詢好的 order，避免重複查詢 DB
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $transactionNo = Common::genTransactionNo($this->orderPrefix);
        $params = $this->buildPayoutParams($withdrawalOrder, $transactionNo, $action);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendRequest('/a/' . $action, $params),
            // 嚴格判斷：必須 status === 0 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? null) === 0,
                errorMessage: $result['msg'] ?? 'Unknown error',
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo, string $action): array
    {
        $params = [
            'mCode' => $this->info['MERCHANT_CODE'],
            'ts' => Carbon::now()->timestamp,
            'bCode' => $this->convertPayoutBankCode($withdrawalOrder->bank_code), // 银行代码
            'trxid' => $transactionNo,
            'user' => $withdrawalOrder->owner->username,
            'amt' => sprintf('%.2f', $withdrawalOrder->amount), // 保留兩位小數
            'bAccNo' => $withdrawalOrder->account_no,
            'bAccName' => $withdrawalOrder->account_name,
            'notifyUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
        ];

        $params['token'] = $this->generateToken($params, $action, $this->info['SECRET_KEY']);

        return $params;
    }
}
