<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayPro;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Facades\Log;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use PayProTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'PayPro';

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
            performRequest: fn (): array => $this->sendRequest('/v1/paypro/payOut', $params),
            // 嚴格判斷：必須 code === '0000' 才視為成功
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['code'] ?? null) === '0000',
                thirdPartyOrderNo: $result['data']['platFormTradeNo'] ?? '',
                errorMessage: $result['msg'] ?? ($result['data']['desc'] ?? 'Unknown error'),
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo): array
    {
        $mobile = '62812345678';
        $email = 'user@example.invalid';

        $params = [
            'timestamp' => $this->getTimestamp(),
            'tradeNo' => $transactionNo,
            'amount' => (float) Common::numFloor($withdrawalOrder->amount, 0), // PayPro 要求 float，不支持小數，轉為整數
            'customerName' => $withdrawalOrder->account_name,
            'mobile' => $mobile,
            'email' => $email,
            'bankAccountNo' => $withdrawalOrder->account_no,
            'bankCode' => $this->convertPayoutBankCode($withdrawalOrder->bank_code ?? ''),
            'notifyUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
        ];

        Log::debug($this->getDriverName() . ' 代付請求參數', $this->maskSensitiveParams($params));

        return $params;
    }
}
