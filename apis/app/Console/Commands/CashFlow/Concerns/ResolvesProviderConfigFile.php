<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Concerns;

/**
 * 依 third_party_code 定位 provider 配置檔（Data/providers/{Name}.php）
 *
 * AddAgentCashFlow 與 SyncCashFlowsCommand 共用同一份定位邏輯，
 * 以 config/payment.php 為權威映射（如 DG → DgPay），避免兩處各自 hardcode 產生 drift。
 *
 * 「同 Driver 多商戶」情境（如 SPEEDPAY 與 PAYMATE 同走 PayMate\Driver）必須以檔名優先，
 * 否則兩者的 third_party_code 都會反解到同一個 provider 目錄名而讀到彼此的配置。
 */
trait ResolvesProviderConfigFile
{
    /**
     * 列出 providers 目錄下的檔名（不含 .php 副檔名）
     *
     * @return array<int, string>
     */
    protected function listProviderFileNames(string $providersDir): array
    {
        return array_map(
            fn (string $filename): string => basename($filename, '.php'),
            array_slice(scandir($providersDir), 2)
        );
    }

    /**
     * 依 third_party_code 解析 provider 配置檔名
     *
     * 解析流程：
     * 1. 移除 third_party_code 的 D_/W_ 前綴（例：W_DG -> DG, W_EPICPAY -> EPICPAY）
     * 2. 優先以大小寫不敏感比對 providers/ 目錄下的檔名（例：SPEEDPAY -> SpeedPay）——
     *    同一 Driver 供多商戶共用時，唯一能區分彼此配置檔的依據就是檔名
     * 3. Fallback：從 config/payment.php 反查 Driver class，再從 namespace 解析 provider 目錄名
     *    （例：config('payment.provider.DG') => App\Services\Payment\Providers\DgPay\Driver => DgPay）
     *
     * @param  array<int, string>  $existingProviderFiles  providers 目錄下的檔名（不含副檔名）
     * @return string|null 命中的 provider 檔名（不含副檔名），找不到返回 null
     */
    protected function resolveProviderFileName(string $thirdPartyCode, array $existingProviderFiles): ?string
    {
        // 移除 D_ 或 W_ 前綴
        $providerCode = preg_replace('/^[DW]_/', '', $thirdPartyCode);

        $providerName = array_values(array_filter(
            $existingProviderFiles,
            fn (string $f): bool => strtolower($f) === strtolower($providerCode)
        ))[0] ?? null;

        if ($providerName !== null) {
            return $providerName;
        }

        $providerClass = config('payment.provider.' . strtoupper($providerCode));
        if (is_string($providerClass)) {
            $parts = explode('\\', $providerClass);
            $providerName = $parts[count($parts) - 2] ?? null;
        }

        return ($providerName !== null && in_array($providerName, $existingProviderFiles, true))
            ? $providerName
            : null;
    }
}
