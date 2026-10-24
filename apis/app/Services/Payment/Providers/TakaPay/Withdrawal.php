<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TakaPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Throwable;

/**
 * @knowledge rules/payout-submit-and-status
 */
class Withdrawal extends AbstractMethod
{
    use TakaPayTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'TakaPay';

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
            return WithdrawalResponseData::from([
                'isSuccess' => false,
                'errorMessage' => $e->getMessage(),
            ]);
        }

        $credentials = $this->getMerchantCredentials();
        $currencyCode = $this->agentCashFlow->currency->code;
        $transactionTime = gmdate('Y-m-d H:i:s');
        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        // 從 CashFlowBank 取得 provider_bank_code（TakaPay 格式：method.code）。
        // 只取啟用中的列並以 id 排序：(cash_flow_id, currency_id, bank_code) 沒有唯一鍵，
        // 同一家銀行可能同時留著停用的舊列與啟用中的新列，不限縮會取到不確定的那一列。
        // bank_code 逐字相同才算命中：欄位 collation 不分大小寫，直接用 = 會把 MayBank 與 MAYBANK
        // 當同一個碼；與 WithdrawalPayoutTrait::lookupProviderBankCodeFromTable() 同一把尺。
        $cashFlowBank = \App\Models\CashFlowBank::query()
            ->where('cash_flow_id', $this->agentCashFlow->cash_flow_id)
            ->where('currency_id', $this->agentCashFlow->currency_id)
            ->whereRaw('BINARY bank_code = ?', [(string) $withdrawalOrder->bank_code])
            ->where('status', 1)
            ->orderBy('id')
            ->first();

        // 判空一律用 === ''，不用 empty()：部分金流商的合法代付銀行碼是字串 "0"
        // （例：PayEssence 加密貨幣代付），empty() 會把它當成空值誤擋。
        if ($cashFlowBank === null || (string) $cashFlowBank->provider_bank_code === '') {
            return WithdrawalResponseData::from([
                'isSuccess' => false,
                'errorMessage' => sprintf('Provider bank code not configured for bank: %s', $withdrawalOrder->bank_code),
            ]);
        }

        // paymentMethod/paymentCode: 從 provider_bank_code 拆解
        $paymentParts = $this->parsePaymentMethodAndCode($cashFlowBank->provider_bank_code);
        $paymentMethod = $paymentParts['paymentMethod'];
        $paymentCode = $paymentParts['paymentCode'];

        $notifyUrl = Common::withdrawalCallbackUrl($this->agentCashFlow->id);

        // 構建請求參數（按 API 文檔順序）
        $requestParams = [
            'merchantCode' => $credentials->merchantCode,
            'currencyCode' => $currencyCode,
            'amount' => sprintf('%.2f', $withdrawalOrder->amount), // 保留兩位小數
            'reference' => $transactionNo,
            'transactionTime' => $transactionTime,
            'paymentMethod' => $paymentMethod,
            'paymentCode' => $paymentCode,
            'recipientName' => $withdrawalOrder->account_name,
            'recipientAccount' => $withdrawalOrder->account_no,
            'notifyUrl' => $notifyUrl,
        ];

        // 生成 securityToken
        // MD5(merchantCode + currencyCode + amount + transactionNo + transactionTime +
        //     paymentMethod + paymentCode + recipientName + recipientAccount + notifyUrl + secretKey)
        $signFields = [
            $credentials->merchantCode,
            $currencyCode,
            sprintf('%.2f', $withdrawalOrder->amount),
            $transactionNo,
            $transactionTime,
            (string) $paymentMethod,
            $paymentCode,
            $withdrawalOrder->account_name,
            $withdrawalOrder->account_no,
            $notifyUrl,
        ];
        $requestParams['securityToken'] = $this->generateSecurityToken($signFields, $credentials->secretKey);

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $requestParams,
            performRequest: fn (): array => $this->sendRequest('/withdraw', $requestParams),
            // 嚴格判斷：必須 status === SUCCESS 才視為成功（TakaPay 未回傳 thirdPartyOrderNo）
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: ($result['status'] ?? null) === TakaPayStatusCodeEnum::SUCCESS->value,
                errorMessage: $result['description'] ?? 'Unknown error',
            ),
        );
    }
}
