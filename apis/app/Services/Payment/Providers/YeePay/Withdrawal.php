<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\YeePay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use YeePayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'YEE';

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
            performRequest: fn (): array => $this->sendRequest($this->info['WITHDRAWAL_URL'], $params),
            // 嚴格判斷：必須 code === 1 才視為成功
            // 注意：YeePay 只在回調時才會提供三方訂單號 serial_number，代付請求時不會返回
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['code'] ?? null) === 1,
                errorMessage: $result['message'] ?? 'Unknown error',
            ),
        );
    }

    /**
     * 構建代付請求參數
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo): array
    {
        $params = [
            'uid' => $this->info['MERCHANT_UID'],
            'merchant_num' => $this->info['MERCHANT_NUM'],
            'order' => $transactionNo,
            'coin' => sprintf('%.0f', $withdrawalOrder->amount), // ! 不能有小數點
            'userinfo' => $withdrawalOrder->owner->username,
            'bank_name' => $this->convertPayoutBankCode($withdrawalOrder->bank_code), // 三方銀行代碼
            'target_bank' => $withdrawalOrder->account_no, // 會員銀行帳號
            'target_bank_user' => $withdrawalOrder->account_name, // 會員銀行收款人名稱
            'extend' => '',
            'order_date' => now()->toDateTimeString(),
            'notifyurl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            'user_ip' => Common::getIp(),
        ];

        $params['sign'] = $this->generateSign($params, $this->info['API_SECRET']);

        return $params;
    }
}
