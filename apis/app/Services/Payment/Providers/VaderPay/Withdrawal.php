<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\VaderPay;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use App\Services\Payment\Traits\WithdrawalPayoutTrait;
use Throwable;

/**
 * @knowledge rules/payout-submit-and-status
 */
class Withdrawal extends AbstractMethod
{
    use VaderPayTrait;
    use WithdrawalPayoutTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'VaderPay';

    public function __construct(AgentCashFlow $agentCashFlow)
    {
        $this->agentCashFlow = $agentCashFlow;
        $this->setInfo($agentCashFlow->info);
    }

    /**
     * 處理 VaderPay 代付
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
        $currencyCode = $this->agentCashFlow->currency->code;
        $transactionNo = Common::genTransactionNo($this->orderPrefix);

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
            return $this->createFailureResponse(
                sprintf('Provider bank code not configured for bank: %s', $withdrawalOrder->bank_code)
            );
        }

        $playerUsername = $withdrawalOrder->owner?->username ?? $withdrawalOrder->account_name;
        $playerIp = Common::getIp();
        $amount = sprintf('%.2f', (float) $withdrawalOrder->amount);

        $requestParams = [
            'merchant_code' => $credentials->merchantCode,
            'ref_id' => $transactionNo,
            'player_username' => $playerUsername,
            'player_ip' => $playerIp,
            'currency_code' => $currencyCode,
            'amount' => $amount,
            'bank_code' => $cashFlowBank->provider_bank_code,
            'beneficiary_account' => $withdrawalOrder->account_no,
            'beneficiary_name' => $withdrawalOrder->account_name,
            'hash' => $this->generatePayoutHash(
                $credentials->merchantCode,
                $transactionNo,
                $playerUsername,
                $playerIp,
                $currencyCode,
                $amount,
                $cashFlowBank->provider_bank_code,
                $withdrawalOrder->account_no,
                $withdrawalOrder->account_name,
                $credentials->apiKey
            ),
            'email' => $withdrawalOrder->owner?->email ?? '',
            'mobile' => $withdrawalOrder->owner?->mobile ?? '',
        ];

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $requestParams,
            performRequest: fn (): array => $this->sendJsonRequest('/Payout/Withdrawal', $requestParams),
            interpretResult: function (array $result): PayoutResult {
                // error_code 為 null 或 0 視為成功受理
                $errorCode = $result['error_code'] ?? null;

                return new PayoutResult(
                    isSuccess: $errorCode === null || (int) $errorCode === 0,
                    thirdPartyOrderNo: (string) ($result['invoice_number'] ?? ''),
                    errorMessage: $result['error_message'] ?? 'Withdrawal request failed',
                );
            },
        );
    }
}
