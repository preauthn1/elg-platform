<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Services\CashFlow\Bank\AvailableBanksAlignService;
use Illuminate\Console\Command;

/**
 * 對齊代付 ACF 的 available_banks BankCode_ELG 至 canonical banks.code（per-entry type-aware name-hop）。
 * 預設 dry-run 報表；--execute 才寫、必填 --agent-group 或 --cash-flow 限範疇；
 * unmatched/conflict 只記錄不改，待人工。VWorld 等兩套碼白標禁盲跑；execute 應避開 sync（maintenance window）。
 *
 * 「VWorld 兩套碼」當時的樣貌（2026-07 盤點快照；2026-09 複查時這四筆 ACF 已全部對齊成 canonical 碼，
 * 現場找不到這兩套碼了，留下只為說明問題長什麼樣，動手前一律重查現況）：GMPay cf 74 的 ACF 235 用縮寫碼
 * BOC／Citi／HLB／MBB／TBL／LiquidPay，ACF 294、296 用全名碼 Bank Of China／Citibank／Hong Leong／
 * Maybank／TrustBank／Liquid Pay；GMPay 的 providers Data 檔只涵蓋縮寫碼那套，盲跑 sync 會把全名碼
 * 那 6 筆下架。
 *
 * 【分類】Bank — 銀行收斂軌①的對齊工具，兩套碼並存期間專用（退役條件見下方）。
 *
 * ## 退役條件
 *
 * 軌① `bank:rename-bank` 收斂完成、每個代理群組只剩一套碼之後，`BankCode_ELG` 與
 * canonical `banks.code` 天然一致，本命令沒有標的；`--prune-duplicate-entries` 要清的
 * 死項目也是「兩套碼並存」的產物，同時消失。
 */
class AlignAvailableBanksCommand extends Command
{
    protected $signature = 'cash-flow:align-available-banks
                            {--agent-group= : 限定代理群組}
                            {--cash-flow= : 限定金流商}
                            {--execute : 實際寫入（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}
                            {--prune-duplicate-entries : canonical 已在同 ACF 的死 entry，若 Third 相同且無攔阻中舊碼單則整個 entry 刪除（否則維持 conflict）}';

    protected $description = '對齊代付 ACF 的 available_banks BankCode_ELG 至 canonical banks.code（預設 dry-run）';

    public function handle(AvailableBanksAlignService $service): int
    {
        $agentGroupId = $this->option('agent-group') !== null ? (int) $this->option('agent-group') : null;
        $cashFlowId = $this->option('cash-flow') !== null ? (int) $this->option('cash-flow') : null;

        // execute 必填範疇：避免無界批次改寫 production JSON
        if ($this->option('execute') && $agentGroupId === null && $cashFlowId === null) {
            $this->error('--execute 需指定 --agent-group 或 --cash-flow 限定範疇（避免全站批次改寫）。');

            return self::FAILURE;
        }

        $prune = (bool) $this->option('prune-duplicate-entries');
        $items = $service->detectDrift($agentGroupId, $cashFlowId, $prune);

        if ($items->isEmpty()) {
            $this->info('未發現 available_banks drift，無需對齊。');

            return self::SUCCESS;
        }

        $this->table(
            ['agent_cash_flow_id', 'cash_flow_id', 'currency_id', 'old_code', 'resolution', 'new_code', 'detail'],
            $items->map(fn (array $i): array => [
                $i['agent_cash_flow_id'],
                $i['cash_flow_id'],
                $i['currency_id'] ?? 'NULL',
                $i['old_code'],
                $i['resolution'],
                $i['new_code'] ?? '-',
                $i['detail'] ?? '-',
            ])->all()
        );

        $alignable = $items->where('resolution', 'align')->count();
        $unmatched = $items->where('resolution', 'unmatched')->count();
        $conflict = $items->where('resolution', 'conflict')->count();
        $prunable = $items->where('resolution', 'prune')->count();
        $this->line(sprintf('可對齊 %d、可刪重複 entry %d、unmatched %d、conflict %d', $alignable, $prunable, $unmatched, $conflict));

        if (!$this->option('execute')) {
            $this->info('dry-run 模式，未寫入。加 --execute（並帶 --agent-group/--cash-flow）以實際對齊。');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm('確認對齊？此操作將改寫 available_banks（unmatched/conflict 不動、僅記錄）', false)) {
            $this->info('已取消，未寫入。');

            return self::SUCCESS;
        }

        $summary = $service->align($agentGroupId, $cashFlowId, $prune);

        $this->info(sprintf(
            '對齊完成：align %d、prune %d、unmatched %d、conflict %d（詳見 available_banks_align_logs）',
            $summary['aligned'],
            $summary['pruned'],
            $summary['unmatched'],
            $summary['conflict']
        ));

        if ($summary['unmatched'] > 0 || $summary['conflict'] > 0) {
            $this->warn('⚠️ 有 unmatched/conflict 待人工處理，請查 available_banks_align_logs。');
        }

        return self::SUCCESS;
    }
}
