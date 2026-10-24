<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow;

use Illuminate\Console\Command;

/**
 * 列出 CashFlows 配置命令
 *
 * 功能說明：
 * - 讀取 Data/cash_flows.php 配置文件
 * - 以表格形式顯示所有金流商配置
 * - 支援篩選（代收/代付）
 * - 支援顯示詳細的 info 資訊
 *
 * 使用方式：
 *   php artisan cash-flow:list                    # 顯示所有金流商
 *   php artisan cash-flow:list --type=1           # 只顯示代收
 *   php artisan cash-flow:list --type=3           # 只顯示代付
 *   php artisan cash-flow:list --detail           # 顯示詳細資訊（包含 info）
 *   php artisan cash-flow:list --ids=77,78        # 只顯示指定 ID
 */
class ListCashFlows extends Command
{
    /**
     * 命令簽名
     *
     * @var string
     */
    protected $signature = 'cash-flow:list
                            {--type= : 篩選類型（1=代收, 3=代付）}
                            {--ids= : 指定要顯示的 ID，多個 ID 用逗號分隔}';

    /**
     * 命令描述
     *
     * @var string
     */
    protected $description = '列出 CashFlows 配置文件內容（依幣別展開）';

    /**
     * 執行命令
     */
    public function handle(): int
    {
        $this->info('CashFlows 配置列表');
        $this->newLine();

        // 載入配置文件
        $dataFile = app_path('Console/Commands/CashFlow/Data/cash_flows.php');

        if (!file_exists($dataFile)) {
            $this->error(sprintf('配置文件不存在: %s', $dataFile));

            return self::FAILURE;
        }

        $cashFlows = require $dataFile;

        if (empty($cashFlows)) {
            $this->warn('配置文件為空');

            return self::SUCCESS;
        }

        // 解析參數
        $filterType = $this->option('type');
        $filterIds = $this->parseIds();

        // 篩選資料
        $filtered = $this->filterCashFlows($cashFlows, $filterType, $filterIds);

        if (empty($filtered)) {
            $this->warn('沒有符合條件的金流商');

            return self::SUCCESS;
        }

        // 顯示統計
        $this->displayStatistics($cashFlows, $filtered, $filterType);

        // 展開並顯示表格
        $this->displayExpandedTable($filtered);

        return self::SUCCESS;
    }

    /**
     * 解析 IDs 參數
     */
    protected function parseIds(): ?array
    {
        $idsOption = $this->option('ids');

        if (empty($idsOption)) {
            return null;
        }

        return array_map('intval', explode(',', $idsOption));
    }

    /**
     * 篩選金流商
     */
    protected function filterCashFlows(array $cashFlows, ?string $type, ?array $ids): array
    {
        return array_filter($cashFlows, function ($item) use ($type, $ids) {
            // 篩選類型
            if ($type !== null && $item['type'] != $type) {
                return false;
            }

            // 篩選 ID
            if ($ids !== null && !in_array($item['id'], $ids, true)) {
                return false;
            }

            return true;
        });
    }

    /**
     * 顯示統計資訊
     */
    protected function displayStatistics(array $all, array $filtered, ?string $filterType): void
    {
        $totalCount = count($all);
        $filteredCount = count($filtered);

        $depositCount = count(array_filter($all, fn ($item) => $item['type'] == 1));
        $withdrawalCount = count(array_filter($all, fn ($item) => $item['type'] == 3));

        $this->info(sprintf('總計: %d 個金流商 (代收: %d, 代付: %d)',
            $totalCount,
            $depositCount,
            $withdrawalCount
        ));

        if ($filterType !== null) {
            $typeName = $filterType == 1 ? '代收' : '代付';
            $this->line(sprintf('篩選: %s (%d 個)', $typeName, $filteredCount));
        } elseif ($filteredCount < $totalCount) {
            $this->line(sprintf('顯示: %d 個', $filteredCount));
        }

        $this->newLine();
    }

    /**
     * 展開並顯示表格（依幣別展開）
     *
     * 同一金流商的多個幣別，只在第一筆顯示基本資訊（ID、三方代碼、名稱、類型）
     * 並顯示關聯的 agent_cash_flows ID
     */
    protected function displayExpandedTable(array $cashFlows): void
    {
        $rows = [];

        foreach ($cashFlows as $item) {
            $info = $item['info'] ?? [];

            // 查詢關聯的 agent_cash_flows
            $agentCashFlowIds = $this->getAgentCashFlowIds($item['id']);

            // 如果沒有 info 配置，顯示一筆記錄
            if (empty($info)) {
                $rows[] = [
                    $item['id'],
                    $item['third_party_code'] ?? '-',
                    $item['name'],
                    $this->formatType($item['type']),
                    '-',
                    '-',
                    '-',
                    '-',
                    $agentCashFlowIds,
                    $item['remark'] ?? '-',
                ];
            } else {
                // 根據幣別展開成多筆記錄
                $isFirstRow = true;
                foreach ($info as $currency => $config) {
                    if ($isFirstRow) {
                        // 第一筆顯示完整資訊
                        $rows[] = [
                            $item['id'],
                            $item['third_party_code'] ?? '-',
                            $item['name'],
                            $this->formatType($item['type']),
                            $currency,
                            $config['rate'] ?? '-',
                            $config['min_amount'] ?? '-',
                            $config['max_amount'] ?? '-',
                            $agentCashFlowIds,
                            $item['remark'] ?? '-',
                        ];
                        $isFirstRow = false;
                    } else {
                        // 後續幣別，基本資訊留空
                        $rows[] = [
                            '',  // ID 留空
                            '',  // 三方代碼留空
                            '',  // 名稱留空
                            '',  // 類型留空
                            $currency,
                            $config['rate'] ?? '-',
                            $config['min_amount'] ?? '-',
                            $config['max_amount'] ?? '-',
                            '',  // agent_cash_flows IDs 留空
                            '',  // Remark 留空
                        ];
                    }
                }
            }
        }

        $this->table(
            ['ID', '三方代碼', '名稱', '類型', '幣別', '匯率', '最小金額', '最大金額', 'Agent CashFlows', 'Remark'],
            $rows
        );
    }

    /**
     * 取得關聯的 agent_cash_flows IDs
     *
     * @param  int  $cashFlowId  CashFlow ID
     * @return string 以逗號分隔的 ID 列表，若無則返回 '-'
     */
    protected function getAgentCashFlowIds(int $cashFlowId): string
    {
        $agentCashFlows = \DB::table('agent_cash_flows')
            ->where('cash_flow_id', $cashFlowId)
            ->where('status', 1)
            ->pluck('id')
            ->toArray();

        if (empty($agentCashFlows)) {
            return '-';
        }

        return implode(', ', $agentCashFlows);
    }

    /**
     * 格式化類型
     */
    protected function formatType(int $type): string
    {
        return match ($type) {
            1 => '代收',
            2 => '人工開分',
            3 => '代付',
            4 => '自助洗分',
            default => sprintf('Unknown (%d)', $type),
        };
    }
}
