<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Concerns;

/**
 * 解析 provider 配置檔（Data/providers/{Name}.php）的 info_overrides。
 *
 * deposit / withdrawal 區塊各自支援選填 info_overrides（以幣別代碼為 key），
 * 用於單一 credentials 模板無法表達的 per-binding 值——同一幣別在代收/代付
 * 可能需要相反配置（如 DgPay 代付 MYR 須 PROVIDER_ID=5/10，代收 MYR 必須
 * 維持 0 走 channel key 動態解析），故以「金流類型 × 幣別」為粒度。
 *
 * 呼叫端將返回值 merge 進 credentials 模板；僅影響「建新 binding / 補缺 key」
 * 的模板組裝，既有 agent_cash_flows.info 的值仍依呼叫端既有 merge 語意優先。
 */
trait MergesProviderInfoOverrides
{
    /**
     * @param  array  $providerConfig  provider 配置完整陣列（loadProviderConfigArray 返回值）
     * @param  int  $cashFlowType  金流類型（1=代收、3=代付）
     * @param  string  $currencyCode  幣別代碼（如 MYR）
     * @return array 對應覆寫 key-value；無配置或配置非陣列時為空陣列
     */
    protected function resolveInfoOverrides(array $providerConfig, int $cashFlowType, string $currencyCode): array
    {
        $section = $cashFlowType === 3 ? 'withdrawal' : 'deposit';
        $overrides = $providerConfig[$section]['info_overrides'][$currencyCode] ?? [];

        return is_array($overrides) ? $overrides : [];
    }

    /**
     * 將解析出的 info_overrides 併入 credentials 模板並輸出套用訊息。
     *
     * @param  array  $credentials  credentials 模板（provider config credentials + 預設值）
     * @param  array  $providerConfig  provider 配置完整陣列
     * @param  int  $cashFlowType  金流類型（1=代收、3=代付）
     * @param  string  $currencyCode  幣別代碼（如 MYR）
     * @return array 套用覆寫後的 credentials；無覆寫時原樣返回
     */
    protected function applyInfoOverrides(array $credentials, array $providerConfig, int $cashFlowType, string $currencyCode): array
    {
        $overrides = $this->resolveInfoOverrides($providerConfig, $cashFlowType, $currencyCode);

        if ($overrides === []) {
            return $credentials;
        }

        $this->line(sprintf('  ✓ 已套用 info_overrides：%s', implode(', ', array_keys($overrides))));

        return array_merge($credentials, $overrides);
    }
}
