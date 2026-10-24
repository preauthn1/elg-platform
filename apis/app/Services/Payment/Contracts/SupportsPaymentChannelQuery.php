<?php

declare(strict_types=1);

namespace App\Services\Payment\Contracts;

use App\Services\Payment\Data\PaymentChannelSnapshot;

/**
 * 商戶通道查詢能力（可選實作）：向三方查該商戶的通道、限額與支援銀行。
 *
 * ! instanceof 只表達「這個 Driver 類會不會」，不保證該 ACF 在三方端有開通此查詢——
 * ! 實作內必須先檢核本查詢所需的憑證欄位，缺欄拋 ApiException 而非送出必失敗的請求。
 *
 * 實作守則：唯讀；禁止跨 process 快取（人工排查要最新值、回應含 PII 不宜進共用 cache），
 * 同一請求內可 memoize；raw 進 DTO 前必過 Common::maskCredentials()。
 */
interface SupportsPaymentChannelQuery
{
    public function queryPaymentChannels(): PaymentChannelSnapshot;
}
