<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Maintenance;

use App\Models\AgentCashFlow;
use Illuminate\Console\Command;

/**
 * 正規化 agent_cash_flows.info 的限額欄位鍵名：MinAmount/MaxAmount → min_amount/max_amount。
 *
 * 【分類】Maintenance — 可複用維運工具，需要時才跑。
 *
 * ## 發生原因
 * `agent_cash_flows.info` 是 **schema-less 的 JSON 欄位**，舊系統（API_Agent，
 * Laravel 5.6）時期用 camelCase 鍵名 `MinAmount`／`MaxAmount`，APIs 統一改為
 * snake_case。JSON 欄位沒有 migration 可以一次改完，只能逐列讀出改寫，故需要本命令。
 *
 * **舊鍵名至今仍是「活的」相容路徑**——以下讀取端都還在做雙鍵 fallback，代表系統
 * 仍預期舊鍵可能出現（例如從舊系統匯入配置、或人工在 GUI 貼上舊格式 JSON）：
 * - `app/Services/AgentService.php:134-138`
 * - `app/Services/OrderTypeService.php:60-61`、`:113-114`
 * - `app/Console/Commands/CashFlow/AddAgentCashFlow.php:1863-1865`
 * - `app/Console/Commands/CashFlow/Maintenance/UpdateAgentCashFlowOrderTypeLimits.php`
 *
 * ## 修正目的
 * 把 `info` 頂層與 `info.available_banks[]` 每個元素內的 `MinAmount`／`MaxAmount`
 * 改名為 `min_amount`／`max_amount`，值不變、其餘鍵不動。
 *
 * 排除 `cash_flow_id` 5／6（AutoDeposit／AutoWithdrawal）——這兩支是 legacy 自動渠道，
 * info 結構與一般金流商不同，不套用本規則（執行時會回報排除筆數）。
 *
 * ## 觸發時機
 * 1. 從舊系統匯入／複製 agent_cash_flows 配置後
 * 2. 排查限額異常時，發現讀取端走了上述 fallback 分支
 * 3. 新白標建置完成後的配置健檢
 *
 * ## 冪等性與重跑安全性
 * **完全冪等，重跑安全。** 只在 `array_key_exists` 舊鍵時才改寫，且 `$newInfo !== $record->info`
 * 才實際寫入；已正規化的列完全不碰。支援 `--dry-run` 先看筆數。
 *
 * ## 退役條件
 * 當上列 4 處雙鍵 fallback **全部移除**（代表系統不再接受舊鍵）後，本命令即可刪除。
 * 在那之前它是持續有效的正規化工具，不屬於一次性 patch。
 *
 * ## 關聯
 * - 舊系統遷移背景：`~/Workspace/CLAUDE.md`「整合目標：舊專案 → APIs」
 *
 * 用法：
 *   php artisan fix:agent-cash-flow-info-keys --dry-run   # 預覽筆數
 *   php artisan fix:agent-cash-flow-info-keys             # 實際寫入
 */
class FixAgentCashFlowInfoKeys extends Command
{
    protected $signature = 'fix:agent-cash-flow-info-keys {--dry-run : 只預覽變更，不寫入資料庫}';

    protected $description = '將 agent_cash_flows.info 中的 MinAmount/MaxAmount 遷移為 min_amount/max_amount';

    public function handle(): int
    {
        $this->info('開始檢查 AgentCashFlow info 欄位鍵名...');
        $isDryRun = $this->option('dry-run');

        if ($isDryRun) {
            $this->warn('⚠️  DRY RUN 模式：不會修改資料庫');
        }

        $this->displayExcludedCount();

        $query = AgentCashFlow::whereNotIn('cash_flow_id', [5, 6]);
        $bar = $this->output->createProgressBar($query->count());
        $updatedCount = 0;

        $query->chunk(100, function ($records) use ($bar, &$updatedCount, $isDryRun) {
            foreach ($records as $record) {
                if (!is_array($record->info)) {
                    $bar->advance();

                    continue;
                }

                $newInfo = $this->migrateKeys($record->info);

                if ($newInfo !== $record->info) {
                    $updatedCount++;

                    if (!$isDryRun) {
                        try {
                            $record->update(['info' => $newInfo]);
                        } catch (\Exception $e) {
                            $this->error(sprintf('ID: %s 更新失敗: %s', $record->id, $e->getMessage()));
                        }
                    }
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine();

        $message = $isDryRun
            ? sprintf('預計將更新 %d 筆資料。', $updatedCount)
            : sprintf('任務完成！已更新 %d 筆資料。', $updatedCount);

        $this->info($message);

        return self::SUCCESS;
    }

    protected function displayExcludedCount(): void
    {
        $excludedCount = AgentCashFlow::whereIn('cash_flow_id', [5, 6])->count();

        if ($excludedCount > 0) {
            $this->info(sprintf('已排除 AutoDeposit/AutoWithdrawal：%d 筆', $excludedCount));
        }
    }

    protected function migrateKeys(array $info): array
    {
        $newInfo = $this->renameKeys($info, ['MinAmount' => 'min_amount', 'MaxAmount' => 'max_amount']);

        if (isset($newInfo['available_banks']) && is_array($newInfo['available_banks'])) {
            foreach ($newInfo['available_banks'] as $key => $bank) {
                $newInfo['available_banks'][$key] = $this->renameKeys(
                    $bank,
                    ['MinAmount' => 'min_amount', 'MaxAmount' => 'max_amount']
                );
            }
        }

        return $newInfo;
    }

    protected function renameKeys(array $data, array $keyMap): array
    {
        foreach ($keyMap as $oldKey => $newKey) {
            if (array_key_exists($oldKey, $data)) {
                $data[$newKey] = $data[$oldKey];
                unset($data[$oldKey]);
            }
        }

        return $data;
    }
}
