<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Concerns;

/**
 * 解析「主幣」設定的 trait
 *
 * 提供兩個層級的查詢：
 *  - resolveEnvironmentMainCurrency()：DB_CONNECTION 對應的環境主幣（影響平台層 cash_flows / banks）
 *  - resolveAgentMainCurrency(int $agentId)：代理級主幣（影響代理層 agent_cash_flows），fallback 至環境主幣
 *
 * 設定來源：
 *  - app/Console/Commands/CashFlow/Data/site_environments.php — 環境主幣
 *  - app/Console/Commands/CashFlow/Data/agent_main_currencies.php — 代理主幣
 */
trait ResolvesMainCurrency
{
    /**
     * 取得當前 DB_CONNECTION 對應的環境主幣 ISO code，未設定回傳空字串。
     */
    protected function resolveEnvironmentMainCurrency(): string
    {
        $configFile = __DIR__ . '/../Data/site_environments.php';

        if (!file_exists($configFile)) {
            return '';
        }

        $environments = require $configFile;
        $connection = config('database.default');

        return (string) ($environments[$connection]['main_currency'] ?? '');
    }

    /**
     * 取得指定代理的主幣 ISO code，agent 級設定優先；未設定時 fallback 至環境主幣。
     */
    protected function resolveAgentMainCurrency(int $agentId): string
    {
        $configFile = __DIR__ . '/../Data/agent_main_currencies.php';

        if (file_exists($configFile)) {
            $map = require $configFile;
            $connection = config('database.default');
            $agentCurrency = (string) ($map[$connection][$agentId] ?? '');

            if ($agentCurrency !== '') {
                return $agentCurrency;
            }
        }

        return $this->resolveEnvironmentMainCurrency();
    }
}
