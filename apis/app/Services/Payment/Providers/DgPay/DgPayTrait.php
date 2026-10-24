<?php

declare(strict_types=1);

namespace App\Services\Payment\Providers\DgPay;

trait DgPayTrait
{
    /**
     * opCode（商戶代碼）：新組（DGPAY）SCREAMING_SNAKE 優先、fallback 舊組（DG）PascalCase。
     * 與 InfoData::mapInfo 的相容策略一致——回調鏈直讀 $this->info，未經 InfoData，故在此各自相容雙命名。
     */
    protected function resolveOpCode(): string
    {
        return $this->info['MERCHANT'] ?? $this->info['Merchant'] ?? '';
    }

    /**
     * secretKey（簽名 / 驗簽密鑰）：同 resolveOpCode 的雙鍵相容。
     */
    protected function resolveSecretKey(): string
    {
        return $this->info['MERCHANT_KEY'] ?? $this->info['MerchantKey'] ?? '';
    }

    /**
     * 回調驗簽（官方三份 §4.1 一致）：md5(opCode + orderId + amount + status + secretKey)。
     * 以回調實際帶回的 orderId 原值拼接（IDR 為 {交易號}@{opCode}）。
     */
    protected function verifyCallbackSignature(array $params): bool
    {
        $expected = strtolower(md5(
            $this->resolveOpCode()
            . ($params['orderId'] ?? '')
            . ($params['amount'] ?? '')
            . ($params['status'] ?? '')
            . $this->resolveSecretKey()
        ));

        return hash_equals($expected, (string) ($params['securityToken'] ?? ''));
    }
}
