<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * 自動化金流配置命令
 *
 * 功能說明：
 * - 根據當前資料庫連線自動識別環境
 * - 執行 SyncCashFlowsCommand 同步金流配置
 * - 自動為該環境的代理商執行 AddAgentCashFlow
 *
 * 使用範例：
 *   # 同步 YeePay 代收代付（VND）到當前環境的所有代理
 *   php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND
 *
 *   # 只同步到正式代理
 *   php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND --production
 *
 *   # 只同步到測試代理
 *   php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND --test
 *
 *   # 乾跑模式（不寫入資料庫）
 *   php artisan cash-flow:auto-sync --cashflow=79,80 --currency=VND --dry-run
 */
class AutoSyncCashFlowCommand extends Command
{
    /**
     * 命令簽名
     *
     * @var string
     */
    protected $signature = 'cash-flow:auto-sync
                            {--cashflow=* : 金流商 ID，多個用逗號分隔}
                            {--currency= : 幣別 ID}
                            {--production : 只處理正式代理}
                            {--test : 只處理測試代理}
                            {--dry-run : 乾跑模式，不寫入資料庫}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '自動化金流配置：同步金流商並建立代理配置';

    /**
     * 執行命令
     */
    public function handle(): int
    {
        $this->info('自動化金流配置開始執行...');
        $this->newLine();

        // 1. 識別當前環境
        $environment = $this->detectEnvironment();
        if (!$environment) {
            $this->error('無法識別當前資料庫環境');

            return self::FAILURE;
        }

        $this->info(sprintf('當前環境: %s', $environment['name']));
        $this->newLine();

        // 確認環境，避免誤操作
        if (!$this->confirmEnvironment($environment['name'])) {
            $this->warn('❌ 已取消執行');

            return self::FAILURE;
        }
        $this->newLine();

        // 2. 解析參數
        $cashFlowIds = $this->parseCashFlowIds();
        if (empty($cashFlowIds)) {
            $this->error('請指定金流商 ID (--cashflow=79,80)');

            return self::FAILURE;
        }

        $currencyInput = $this->option('currency');
        if (!$currencyInput) {
            $this->error('請指定幣別代碼或 ID (--currency=VND 或 --currency=3)');

            return self::FAILURE;
        }

        // 轉換幣別參數（支援幣別代碼或 ID）
        $currencyId = $this->parseCurrencyInput($currencyInput);
        if ($currencyId === null) {
            $this->error(sprintf('無效的幣別: %s', $currencyInput));
            $this->line('支援的幣別: MYR(1), CNY(2), VND(3), THB(4), IDR(5), INR(6)');

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');
        if ($isDryRun) {
            $this->warn('⚠️  乾跑模式已啟用，執行後將不會寫入資料庫');
            $this->newLine();
        }

        // 3. 取得要處理的代理清單
        $agentConfigs = $this->getAgentConfigs($environment['config']);
        if (empty($agentConfigs)) {
            $this->warn('沒有要處理的代理');

            return self::SUCCESS;
        }

        $agentIds = array_column($agentConfigs, 'id');
        $this->info(sprintf('將處理 %d 個代理: %s', count($agentIds), implode(', ', $agentIds)));
        $this->newLine();

        // 4. 執行 SyncCashFlowsCommand
        $this->info('步驟 1: 同步金流商配置...');
        $syncResult = $this->executeSyncCashFlows($cashFlowIds, $currencyId, $isDryRun);

        if ($syncResult !== 0) {
            $this->error('金流商配置同步失敗');

            return self::FAILURE;
        }

        $this->newLine();

        // 5. 為每個代理執行 AddAgentCashFlow
        $this->info('步驟 2: 建立代理金流配置...');
        $stats = $this->executeAddAgentCashFlow($agentConfigs, $cashFlowIds, $currencyId, $isDryRun);

        // 6. 顯示統計
        $this->displayStatistics($stats);

        $this->newLine();
        $this->info('✅ 自動化金流配置完成！');

        return self::SUCCESS;
    }

    /**
     * 識別當前資料庫環境
     */
    protected function detectEnvironment(): ?array
    {
        $connection = config('database.default');
        $database = DB::connection()->getDatabaseName();

        $this->line(sprintf('資料庫連線: %s', $connection));
        $this->line(sprintf('資料庫名稱: %s', $database));
        $this->newLine();

        // 載入環境配置
        $configFile = app_path('Console/Commands/CashFlow/Data/site_environments.php');
        if (!file_exists($configFile)) {
            $this->error(sprintf('環境配置文件不存在: %s', $configFile));

            return null;
        }

        $environments = require $configFile;

        // 根據 DB_CONNECTION 識別環境
        if (isset($environments[$connection])) {
            return [
                'name' => $connection,
                'config' => $environments[$connection],
            ];
        }

        // 如果無法識別，詢問用戶
        $this->warn(sprintf('無法自動識別環境 (%s)，請手動選擇:', $connection));
        $environmentNames = array_keys($environments);
        $selected = $this->choice('選擇環境', $environmentNames, 0);

        return [
            'name' => $selected,
            'config' => $environments[$selected],
        ];
    }

    /**
     * 確認當前環境
     *
     * @param  string  $environmentName  環境名稱
     * @return bool 是否確認繼續執行
     */
    protected function confirmEnvironment(string $environmentName): bool
    {
        // 高風險環境標記
        $productionEnvironments = ['VWorld', 'Tpower', 'Me77', 'Yes2Win', 'Alfawin77', 'VPlus33'];

        $isProduction = in_array($environmentName, $productionEnvironments);

        if ($isProduction) {
            $this->warn('⚠️  警告: 這是正式環境！');
        }

        $confirmed = $this->confirm(
            sprintf('確認要在 %s 環境執行此命令嗎？', $environmentName),
            !$isProduction  // 開發環境預設 Yes，正式環境預設 No
        );

        return $confirmed;
    }

    /**
     * 解析金流商 ID
     */
    protected function parseCashFlowIds(): array
    {
        $option = $this->option('cashflow');

        if (empty($option)) {
            return [];
        }

        // 支援陣列或逗號分隔的字串
        if (is_array($option)) {
            $idsString = implode(',', $option);
        } else {
            $idsString = $option;
        }

        $ids = array_map('intval', explode(',', $idsString));
        $ids = array_filter($ids, fn ($id) => $id > 0);

        return array_values($ids);
    }

    /**
     * 取得要處理的代理配置清單
     *
     * @return array 格式：[['id' => 4249, 'type' => 'production'], ...]
     */
    protected function getAgentConfigs(array $config): array
    {
        $productionOnly = (bool) $this->option('production');
        $testOnly = (bool) $this->option('test');

        $agentConfigs = [];

        if ($testOnly) {
            // 只處理測試代理（status = 1）
            $testAgents = $config['test_agents'] ?? [];
            foreach ($testAgents as $agentId) {
                $agentConfigs[] = [
                    'id' => $agentId,
                    'type' => 'test',
                    'status' => 1,  // 測試代理預設啟用
                ];
            }
        } elseif ($productionOnly) {
            // 只處理正式代理（status = 0）
            $productionAgents = $config['production_agents'] ?? [];
            foreach ($productionAgents as $agentId) {
                $agentConfigs[] = [
                    'id' => $agentId,
                    'type' => 'production',
                    'status' => 0,  // 正式代理預設停用，需手動審核
                ];
            }
        } else {
            // 處理所有代理
            $productionAgents = $config['production_agents'] ?? [];
            foreach ($productionAgents as $agentId) {
                $agentConfigs[] = [
                    'id' => $agentId,
                    'type' => 'production',
                    'status' => 0,  // 正式代理預設停用
                ];
            }

            $testAgents = $config['test_agents'] ?? [];
            foreach ($testAgents as $agentId) {
                $agentConfigs[] = [
                    'id' => $agentId,
                    'type' => 'test',
                    'status' => 1,  // 測試代理預設啟用
                ];
            }
        }

        return $agentConfigs;
    }

    /**
     * 執行 SyncCashFlowsCommand
     */
    protected function executeSyncCashFlows(array $cashFlowIds, int $currencyId, bool $isDryRun): int
    {
        $idsString = implode(',', $cashFlowIds);

        $options = [
            '--ids' => $idsString,
            '--currency' => $currencyId,
        ];

        if ($isDryRun) {
            $options['--test'] = true;
        }

        return Artisan::call('cash-flow:sync', $options, $this->output);
    }

    /**
     * 為每個代理執行 AddAgentCashFlow
     */
    protected function executeAddAgentCashFlow(
        array $agentConfigs,
        array $cashFlowIds,
        int $currencyId,
        bool $isDryRun
    ): array {
        $stats = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'skipped' => 0,
        ];

        foreach ($agentConfigs as $agentConfig) {
            $agentId = $agentConfig['id'];
            $agentType = $agentConfig['type'];
            $agentStatus = $agentConfig['status'];

            $this->newLine();
            $typeLabel = $agentType === 'production' ? '正式' : '測試';
            $statusLabel = $agentStatus === 0 ? '停用' : '啟用';
            $this->comment(sprintf(
                '處理代理: %d (%s代理, 預設狀態: %s)',
                $agentId,
                $typeLabel,
                $statusLabel
            ));

            foreach ($cashFlowIds as $cashFlowId) {
                $stats['total']++;

                $options = [
                    '--agent' => $agentId,
                    '--cashflow' => $cashFlowId,
                    '--currency' => $currencyId,
                    '--status' => $agentStatus,  // 傳遞 status 參數
                ];

                if ($isDryRun) {
                    $options['--dry-run'] = true;
                }

                try {
                    $result = Artisan::call('agent:add-agent-cash-flow', $options, $this->output);

                    if ($result === 0) {
                        $stats['success']++;
                    } else {
                        $stats['failed']++;
                    }
                } catch (\Exception $e) {
                    $stats['failed']++;
                    $this->error(sprintf('  ❌ 執行失敗: %s', $e->getMessage()));
                }
            }
        }

        return $stats;
    }

    /**
     * 解析幣別輸入（支援幣別代碼或 ID）
     *
     * @param  string|int  $input  幣別輸入（如：VND, 3, MYR, 1）
     * @return int|null 幣別 ID，無效則返回 null
     */
    protected function parseCurrencyInput(string|int $input): ?int
    {
        // 如果是數字，直接驗證是否存在
        if (is_numeric($input)) {
            $currencyId = (int) $input;
            $exists = \App\Models\Currency::where('id', $currencyId)->exists();

            return $exists ? $currencyId : null;
        }

        // 如果是幣別代碼（如 VND, MYR），查找對應的 ID
        $currencyCode = strtoupper((string) $input);
        $currency = \App\Models\Currency::where('code', $currencyCode)->first();

        return $currency ? $currency->id : null;
    }

    /**
     * 顯示統計資訊
     */
    protected function displayStatistics(array $stats): void
    {
        $this->newLine();
        $this->info('執行統計:');

        $this->table(
            ['項目', '數量'],
            [
                ['總計', $stats['total']],
                ['成功', $stats['success']],
                ['失敗', $stats['failed']],
                ['略過', $stats['skipped']],
            ]
        );
    }
}
