<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\TrustPay;

use App\Enums\CurrencyEnum;
use App\Enums\General\HttpCode;
use App\Exceptions\ApiException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * @knowledge rules/currency-and-wallet-currency
 */
trait TrustPayTrait
{
    /**
     * 發送請求（API v1.0 為 application/x-www-form-urlencoded）
     */
    protected function sendRequest(string $url, array $params): array
    {
        $result = Http::logAction('TrustPay')
            ->asForm()
            ->throw()
            ->post($url, $params)
            ->json();

        return is_array($result) ? $result : [];
    }

    /**
     * 代收／代付請求簽名：SHA256(ref_no + amount + currency + secretKey)
     *
     * 文檔記載之拼接為 ref_no + amount + currency；商戶密鑰由後台提供，慣例上附加於末段參與雜湊。
     */
    protected function generateRequestSignature(string $refNo, string $amount, string $currency, string $secretKey): string
    {
        $signString = $refNo . $amount . $currency;

        return hash_hmac('sha256', $signString, $secretKey);
    }

    /**
     * 回調簽名：SHA256(transaction_reference + amount + currency + secretKey)
     */
    protected function generateCallbackSignature(string $transactionReference, string $amount, string $currency, string $secretKey): string
    {
        $signString = $transactionReference . $amount . $currency;

        return hash_hmac('sha256', $signString, $secretKey);
    }

    /**
     * 取這張單要送三方的幣別代碼。
     *
     * currency 是請求簽名的第三段（ref_no + amount + currency），與三方登記的商戶幣別不符會直接驗簽失敗，
     * 因此查不到對應時一律擋下不出單、不回退預設值。代付側的 banks.currency_id 可為 NULL、代收側的
     * order_types.currency_id 預設 0，兩者都由本方法統一擋：這是我方通道設定缺失、不是玩家請求錯，
     * 所以記 log 並回 500，讓排查的人能從 log 對到是哪條通道。
     *
     * @throws ApiException 幣別 id 為 NULL、0 或不在 CurrencyEnum 內時
     */
    protected function getCurrencyCode(?int $currencyId): string
    {
        $currency = $currencyId === null ? null : CurrencyEnum::tryFrom($currencyId);

        if ($currency === null) {
            Log::error('TrustPay 幣別設定缺失，未送出請求', [
                'agent_cash_flow_id' => $this->agentCashFlow->id,
                'currency_id' => $currencyId,
            ]);

            throw new ApiException(
                $this->getDriverName() . ' invalid currency id: ' . ($currencyId ?? 'null'),
                HttpCode::HTTP_INTERNAL_SERVER_ERROR->value,
            );
        }

        return $currency->name;
    }
}
