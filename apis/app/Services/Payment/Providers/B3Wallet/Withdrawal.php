<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\B3Wallet;

use App\Enums\WithdrawalTypeEnum;
use App\Helpers\Common;
use App\Models\AgentCashFlow;
use App\Models\WithdrawalOrder;
use App\Services\Payment\AbstractMethod;
use App\Services\Payment\Data\PayoutResult;
use App\Services\Payment\Data\WithdrawalRequestData;
use App\Services\Payment\Data\WithdrawalResponseData;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * @knowledge rules/provider-b3wallet
 */
class Withdrawal extends AbstractMethod
{
    use B3WalletTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'B3W';

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
        $url = rtrim($this->info[$this->apiUrlField], '/') . '/api/v1/payouts/create';

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $params,
            // B3 Wallet 對業務層拒絕（餘額不足、代碼錯誤等）回傳 4xx 帶結構化錯誤，
            // 屬於「已受理但拒絕」而非連線失敗，故不用 ->throw()，交給 interpretResult 判讀；
            // 只有 5xx / 連線例外才視為 REQUEST_FAILED
            performRequest: function () use ($url, $params, $transactionNo): array {
                $response = Http::logAction($this->getDriverName() . '_payout')
                    ->withHeaders([
                        'Authorization' => $this->authorizationHeader(),
                        'Idempotency-Key' => $transactionNo,
                    ])
                    ->asJson()
                    ->post($url, $params);

                if ($response->serverError()) {
                    throw new \Exception(sprintf('%s payout HTTP %d', $this->getDriverName(), $response->status()));
                }

                return $response->json() ?? [];
            },
            // 文件記載的 200/成功結構為扁平 Error(string|null)，但實測 422 業務拒絕
            // （如 INSUFFICIENT_BALANCE）回傳巢狀小寫 error:{code,message}，兩種格式都需解析
            interpretResult: function (array $result): PayoutResult {
                $nestedError = is_array($result['error'] ?? null) ? $result['error'] : [];
                $errorMessage = $result['Error']
                    ?? $nestedError['message']
                    ?? $nestedError['code']
                    ?? $result['message']
                    ?? null;

                return new PayoutResult(
                    isSuccess: isset($result['PayoutId']) && $errorMessage === null,
                    thirdPartyOrderNo: $result['PayoutId'] ?? '',
                    errorMessage: $errorMessage ?? '',
                );
            },
        );
    }

    /**
     * 構建代付請求參數
     *
     * 出金方式跟著會員所選的提款銀行走（與 CCPayment 同一套做法）：
     * - 加密貨幣（Bank.type=CRYPTO）→ `PayoutMethod=CRYPTO`，鏈別取自該銀行的三方代碼
     *   （`TRON` / `ETHEREUM` / `BSC`，大寫，小寫三方回 400），收款地址即會員填的 account_no。
     * - PayNow 電子錢包（Bank.type=GCASH 數值重用，非真的 GCASH 業務）→ `PayoutMethod=PAYNOW`，
     *   UEN/MOBILE 代理類型存於 example.invalid['proxy_type']（見銀行設定時的約定）。
     * - 其餘 → `BANK_TRANSFER`（三方預設）。
     *
     * 🔴 三種方式的欄位互斥：CRYPTO 傳 RecipientBank／RecipientAccount、BANK_TRANSFER 傳 PayNow 欄位，
     * 三方一律回 400，故各分支只帶自己那組欄位。
     */
    private function buildPayoutParams(WithdrawalOrder $withdrawalOrder, string $transactionNo): array
    {
        $bank = $withdrawalOrder->bank;
        $bankType = $bank ? (int) $bank->type : 0;

        $common = [
            'Amount' => (float) sprintf('%.2f', $withdrawalOrder->amount),
            // 多幣種錢包：不傳則走商戶預設幣別，會從錯的錢包扣款；CRYPTO 更是必填 USDT
            'Currency' => $this->agentCashFlow->currency->code,
            'Param' => $transactionNo,
            'CallbackUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
        ];

        if ($bankType === WithdrawalTypeEnum::CRYPTO->value) {
            return array_merge($common, [
                'PayoutMethod' => 'CRYPTO',
                'Chain' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
                'ToAddress' => $withdrawalOrder->account_no,
                'RecipientName' => $withdrawalOrder->account_name,
            ]);
        }

        if ($bankType === WithdrawalTypeEnum::GCASH->value) {
            return array_merge($common, [
                'PayoutMethod' => 'PAYNOW',
                'PayNowProxyType' => $bank->info['proxy_type'] ?? null,
                'PayNowProxy' => $withdrawalOrder->account_no,
                'RecipientName' => $withdrawalOrder->account_name,
            ]);
        }

        return array_merge($common, [
            'PayoutMethod' => 'BANK_TRANSFER',
            'RecipientBank' => $this->convertPayoutBankCode($withdrawalOrder->bank_code),
            'RecipientAccount' => $withdrawalOrder->account_no,
            'RecipientName' => $withdrawalOrder->account_name,
        ]);
    }
}
