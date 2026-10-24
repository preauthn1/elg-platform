<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\Kira;

use App\Exceptions\ApiException;
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
    use KiraTrait;

    protected AgentCashFlow $agentCashFlow;

    protected array $info;

    protected string $orderPrefix = 'KIRA';

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

        // 檢查必要參數
        $requiredParams = ['API_URL', 'CLIENT_ID', 'CLIENT_SECRET', 'CLIENT_PRIVATE_KEY', 'CLIENT_SYMMETRIC_KEY', 'SERVER_PUBLIC_KEY'];
        foreach ($requiredParams as $param) {
            if (empty($this->info[$param])) {
                return $this->createFailureResponse('AgentCashFlow missing parameter: ' . $param);
            }
        }

        // 前置取得 access token：失敗＝代付請求未發出，直接明確失敗
        // （不建 payout 記錄；訂單轉 29，與明確拒絕同路徑，可重新發起）；token 以參數傳入
        // sendRequestRaw，關閉 performRequest 內二次取 token 的失敗窗口
        try {
            $token = [REDACTED];
        } catch (Throwable $e) {
            return $this->createFailureResponse($e->getMessage());
        }

        // 生成唯一的代付交易號
        $transactionNo = Common::genTransactionNo($this->orderPrefix);

        // 取得幣別
        $currency = $this->agentCashFlow->currency->code ?? '';

        // 取得methodType
        $methodType = $this->convertPayoutBankCode($withdrawalOrder->bank_code);
        if (empty($methodType)) {
            return $this->createFailureResponse('Unsupported bank code: ' . $withdrawalOrder->bank_code);
        }

        // 組建請求參數
        $requestData = [
            'order' => [
                'id' => $transactionNo,
                'amount' => sprintf('%.2f', $withdrawalOrder->amount),
                'currencyType' => $currency,
                'callbackUrl' => Common::withdrawalCallbackUrl($this->agentCashFlow->id),
            ],
            'recipient' => [
                'name' => $withdrawalOrder->account_name,
                'phone' => $withdrawalOrder->owner->mobile ?? '',
                'email' => trim($withdrawalOrder->owner->email ?? '') ?: 'user@example.invalid',
                'methodType' => $methodType,
                'methodValue' => $withdrawalOrder->account_no,
                'methodRef' => '',
            ],
        ];

        return $this->processPayout(
            $withdrawalOrder,
            $transactionNo,
            $requestData,
            // 例外分類原則：只有業務碼明確拒絕（見 isRejectedBusinessCode()）才交 interpretResult 判 -2、
            // 訂單轉 29 可重新發起；其餘（連線例外 / HTTP 非 2xx / 結構缺失 / code=200 後任何解析失敗）
            // 一律在此拋 → -1 REQUEST_FAILED，訂單停留 21 待回調或 resolve-payout 定案
            performRequest: function () use ($requestData, $token): array {
                // HTTP 非 2xx 由 sendRequestRaw 拋 ApiException，不判拒絕（見 isRejectedBusinessCode()）
                $result = $this->sendRequestRaw('/gateway/v1/withdrawRequest', $requestData, $token);

                // 2xx 但解不出數值業務碼（body 非 JSON / 缺鍵 / null / 非數值型別）＝結構缺失：
                // 非數值業務碼無依據斷定未建單 → 結果未知
                if (!is_numeric($result['code'] ?? null)) {
                    throw new ApiException(sprintf('%s unparseable 2xx response', $this->getDriverName()));
                }

                if ($result['code'] != 200) {
                    // 401＝token 失效：清快取，讓重新發起時換新 token（api.md §10）
                    if ((int) $result['code'] === 401) {
                        $this->clearAccessToken();
                    }

                    // 明確拒絕 → 交 interpretResult 判 -2（薄繼承 provider 覆寫的判定經 $this 動態分派生效）
                    if ($this->isRejectedBusinessCode((int) $result['code'])) {
                        return $result;
                    }

                    // 判定不是明確拒絕的碼無依據證明未建單 → 結果未知，訂單停留 21 待回調或人工定案
                    throw new ApiException(sprintf(
                        '%s ambiguous business code %s: %s',
                        $this->getDriverName(),
                        $result['code'],
                        $result['message'] ?? 'Unknown error'
                    ));
                }

                // code=200＝三方已受理：解密失敗（aesDecrypt 拋）或缺 transactionId 均屬結果未知
                $data = $this->decryptResultData($result);

                if (empty($data['data']['transactionId'] ?? '')) {
                    throw new ApiException(sprintf('%s accepted (code=200) but payload missing transactionId', $this->getDriverName()));
                }

                return $data;
            },
            // 到得了這裡的只有兩種形狀：業務碼拒絕信封（→ -2），或帶 transactionId 的解密內層（→ 受理）
            interpretResult: fn (array $result): PayoutResult => new PayoutResult(
                isSuccess: !empty($result['data']['transactionId'] ?? ''),
                thirdPartyOrderNo: $result['data']['transactionId'] ?? '',
                errorMessage: sprintf('%s API error: %s', $this->getDriverName(), $result['message'] ?? 'Unknown error'),
            ),
        );
    }

    /**
     * 業務碼是否為明確拒絕（確定不會出款，判 -2、訂單轉三方失敗(29)可重新發起）。
     *
     * KIRA 的業務碼只要不是 200 一律算拒絕：KIRAPAY 書面確認「只要不是 200，就表示銀行那裡不會出款，
     * 可以重發」（target/tickets#4393，見 api.md §10）。
     * 400 也算在內。文檔寫它含「訂單號重複」，但每次送出都用新產生的交易號、HTTP 請求也不自動重試，
     * 同一個交易號只會送一次；就算回「訂單號重複」，也不代表這次請求已在 KIRA 建單。
     *
     * 明確拒絕一律只看 KIRA 回應的業務碼（HTTP 2xx 且 body 帶數字 code）。HTTP 非 2xx（含 Cloudflare 的
     * 520、522）不判拒絕，一律視為結果未知（訂單停留 21）：代付只依業務碼判定明確拒絕（target/tickets#4393）。
     *
     * 明確拒絕屬各金流商政策（需該金流商書面／實測依據）：同源薄繼承 provider 必須自行覆寫本方法，不得沿用。
     */
    protected function isRejectedBusinessCode(int $code): bool
    {
        return $code !== 200;
    }
}
