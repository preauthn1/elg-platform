<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VtPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use VtPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'AK';

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
            performRequest: fn (): array => $this->sendRequest($this->info['API_URL'] . '/SH/sendWithdraw', $params),
            // error_code 為 200 視為成功（兼容字串 "200"）
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: (int) ($result['error_code'] ?? 0) === 200,
                thirdPartyOrderNo: $result['data']['order_id'] ?? '',
                errorMessage: $result['message'] ?? 'Unknown error',
            ),
        );
    }

    /**
     * 構建代付請求參數
     *
     * 根據 VtPay API 文檔：/SH/sendWithdrawal
     * 必填參數：sh_order_no, withdraw_amount, bank_code, bank_account, bank_account_name, notify_url
     */
    private function buildPayoutParams($withdrawalOrder, string $transactionNo): array
    {
        return [
            // 必填：商戶訂單號
            'sh_order_no' => $transactionNo,

            // 必填：提現金額（確保為兩位小數格式）
            'order_amount' => Common::numFloor($withdrawalOrder->amount, 2),

            // 必填：銀行代碼（通過 WithdrawalPayoutTrait 的 convertPayoutBankCode 轉換）
            'bank_code' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),

            // 必填：銀行帳號
            'bank_account' => $withdrawalOrder->account_no,

            // 必填：銀行帳戶名稱
            'bank_user_name' => $withdrawalOrder->account_name,

            // 必填：異步通知商戶地址
            'notify_url' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),

            'operate_type' => 'HAND', // 人工提现

            // 可選：備註
            'note' => '',
        ];
    }
}
