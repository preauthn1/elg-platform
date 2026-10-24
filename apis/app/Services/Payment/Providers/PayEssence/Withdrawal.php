<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\PayEssence;

use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Facades\Http;
use Throwable;

class Withdrawal extends AbstractMethod
{
    use PayEssenceTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

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

        // 檢查 info 是否包含必要參數
        $checkParams = ['MERCHANT_CODE', 'SECRET_KEY', 'API_URL'];
        foreach ($checkParams as $param) {
            if (!isset($this->info[$param]) || $this->info[$param] === '') {
                return $this->createFailureResponse('AgentCashFlow missing parameter: ' . $param);
            }
        }

        $merchantCode = $this->info['MERCHANT_CODE'];
        $secretKey = $this->info['SECRET_KEY'];
        $apiUrl = $this->info['API_URL'];

        // 生成唯一的代付交易號
        $transactionNo = Common::genTransactionNo('PE');

        // 獲取銀行代碼和幣別代碼
        $toBankId = $this->convertPayoutBankCode($withdrawalOrder->bank_code);

        $currencyTypeId = $this->getCurrencyTypeId($withdrawalOrder);
        $isCrypto = $withdrawalOrder->bank->type === 3;

        // For Crypto Currency, please leave EMPTY.
        $accountName = $isCrypto ? '' : $withdrawalOrder->account_name;

        $extraFields = Common::safeJsonDecode($withdrawalOrder->memo);
        $bankIdentifierCode = $extraFields['ifsc'] ?? '';

        // 組建代付參數
        $params = [
            'MerchantCode' => $merchantCode,
            'MerchantTransNum' => $transactionNo,
            'CurrencyTypeId' => $currencyTypeId,
            'ToBankId' => $toBankId,
            'ToBankAccountName' => $accountName,
            'ToBankAccountNum' => $withdrawalOrder->account_no,
            'Amount' => sprintf('%.2f', $withdrawalOrder->amount),
        ];

        if (!empty($bankIdentifierCode)) {
            $params['BankIdentifierCode'] = $bankIdentifierCode;
        }

        // 生成簽名
        $amount = sprintf('%.2f', $withdrawalOrder->amount);
        $params['Signature'] = $this->generateWithdrawalSignature(
            $merchantCode,
            $secretKey,
            $transactionNo,
            $toBankId,
            $withdrawalOrder->account_no,
            (string) $currencyTypeId,
            $amount,
        );

        $url = rtrim($apiUrl, '/') . '/WebForm/Deposit_P2pPayout2_B2b.aspx';

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            performRequest: function () use ($url, $params): array {
                // PayEssence 採 inline form POST（非共用 sendRequest）；HTTP 非 2xx → 拋出 → REQUEST_FAILED
                $response = Http::timeout(30)->asForm()->post($url, $params);

                if (!$response->successful()) {
                    throw new \Exception(sprintf('PayEssence API Error: HTTP %d', $response->status()));
                }

                return $response->json() ?? [];
            },
            // 受理碼：35001 / 35002 / 35004（Result 可能為字串或整數，統一轉字串後嚴格比對）
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: in_array((string) ($result['Result'] ?? ''), ['35001', '35002', '35004'], true),
                thirdPartyOrderNo: $result['PayoutId'] ?? '',
                errorMessage: $this->getErrorMessage((string) ($result['Result'] ?? '')),
            ),
        );
    }

    /**
     * 獲取幣別代碼
     */
    private function getCurrencyTypeId(WithdrawalOrder $order): int
    {
        return match (true) {
            $this->agentCashFlow->currency->code === 'MYR' => 1,
            $this->agentCashFlow->currency->code === 'VND' => 8,
            $order->bank_code === 'ERC20' => 1001,
            $order->bank_code === 'TRC20' => 1002,
            default => throw new \Exception(sprintf('Unsupported currency: %s', $this->agentCashFlow->currency->code)),
        };
    }

    private function getErrorMessage(string $resultCode): string
    {
        return match ($resultCode) {
            '1101' => 'Merchant Code Not Defined',
            '1102' => 'Merchant Code Invalid',
            '1201' => 'Merchant Payout No. is not defined',
            '1202' => 'Invalid Merchant Payout No.',
            '1301' => 'Currency Code is not defined',
            '1302' => 'Invalid Currency Code',
            '1401' => 'Amount is not defined',
            '1402' => 'Invalid Amount',
            '1501' => 'Bank Name is not defined',
            '1502' => 'Invalid Bank Name',
            '1503' => 'Bank Name not matched',
            '1601' => 'Account Name is not defined',
            '1602' => 'Invalid Account Name',
            '1701' => 'Account Number is not defined',
            '1702' => 'Invalid Account Number',
            '1802' => 'Invalid Signature',
            '2301' => 'Bank ID is not defined',
            '2302' => 'Bank ID is invalid',
            '2401' => 'Account Name is not defined',
            '2402' => 'Account Name is invalid',
            '2501' => 'Account Number is not defined',
            '2502' => 'Account Number is invalid',
            '2701' => 'Merchant Payout Account is inactive',
            '2801' => 'Merchant IP Address Invalid',
            default => 'Payout request failed: ' . $resultCode,
        };
    }
}
