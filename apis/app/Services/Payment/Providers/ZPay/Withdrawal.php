<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\ZPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use ZPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'ZPAY';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 將內部銀行代碼映射為 ZPay BankId
     * 詳細對應表請參閱 ZPay 文件
     */
    protected function mapBankId(string $bankCode): string
    {
        return $bankCode;
    }

    /**
     * 處理 ZPay 代付
     *
     * @param  WithdrawalRequestData  $request  代付請求資料
     * @return WithdrawalResponseData 代付響應資料
     */
    public function handle(WithdrawalRequestData $request): WithdrawalResponseData
    {
        // 優先使用已查詢好的 order，避免重複查詢 DB
        try {
            $withdrawalOrder = $this->validatePayoutOrder($request->order);
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        $credentials = $this->getMerchantCredentials();
        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        // 生成當前時間戳
        $dateTime = date('YmdHis');

        // 生成 SecretParam 簽名
        $secretParam = $this->generateSecretParamSign(
            $transactionNo,
            $dateTime,
            (float) $withdrawalOrder->amount,
            $credentials->secretKey
        );

        // 映射銀行代碼
        $bankId = $this->mapBankId($withdrawalOrder->bank_code);

        $params = [
            'Token' => $credentials->token,
            'SecretParam' => $secretParam,
            'BankId0' => $bankId,
            'AccountName0' => $withdrawalOrder->account_name,
            'AccountNo0' => $withdrawalOrder->account_no,
            'ToCurrency' => 'MYR',
            'RequestAmount' => sprintf('%.2f', $withdrawalOrder->amount),
            'UserReference' => $transactionNo,
            'Remark' => sprintf('Withdrawal for order %s', $withdrawalOrder->order_no),
            'CallBackUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
        ];

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: fn (): array => $this->sendJsonRequest('/ApiPBO/ApiCreateAutoPayout.aspx', $params),
            // ZPay Payout API 成功條件：Status === 0；成功時以 UserReference（transactionNo）作為三方訂單號
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['Status'] ?? null) === 0,
                thirdPartyOrderNo: $transactionNo,
                errorMessage: $result['Message'] ?? 'Unknown error',
            ),
        );
    }
}
