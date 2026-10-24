<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Services\CashFlow\Bank\BankActiveDedupService;
use Illuminate\Console\Command;

/**
 * 偵測並（後續）清理 banks 表中 active 的重複列。
 *
 * 【分類】Bank — 銀行收斂工具（同碼重複），過渡期（退役條件見下方）。
 *
 * 重複定義：同自然鍵 (agent_group_id, type, currency_id, code) 有多於一筆 status=1 列。
 * 預設僅 dry-run 報表，不寫入任何資料。
 *
 * ## 退役條件
 *
 * `bank:guard-active-banks` 的 active 唯一約束（generated column ＋ partial unique）上線後，
 * 同碼多 active 列在 DB 層就無法再生 ⇒ 已建 guard 的白標本命令即無標的。
 * 六站全數建立 guard、且結構已由 migration 轉正之後，可連同 `bank:dedup-rollback` 一併刪除。
 *
 * ⚠️ 執行前務必逐站確認：本命令的「重複」判準是自然鍵，不看哪一列才是現行服務中的那列。
 */
class DedupActiveBanks extends Command
{
    protected $signature = 'bank:dedup-active
                            {--execute : 實際執行清理（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}
                            {--agent-group= : 只處理指定 agent_group_id（逗號分隔；預設整庫）}';

    protected $description = '偵測並清理 banks 表中 active 的重複列（預設 dry-run，僅報表不寫入）';

    public function handle(BankActiveDedupService $service): int
    {
        $agentGroupIds = $this->resolveAgentGroupIds();

        if ($agentGroupIds !== null) {
            $this->info(sprintf('範疇限定 agent_group_id：%s', implode(', ', $agentGroupIds)));
        }

        $groups = $service->detectDuplicateGroups($agentGroupIds);
        $shadowGroups = $service->detectInactiveShadowGroups($agentGroupIds);

        if ($groups->isEmpty() && $shadowGroups->isEmpty()) {
            $this->info('發現 0 組 active 重複銀行與 0 組 inactive shadow 重複，無需清理。');

            return self::SUCCESS;
        }

        if ($groups->isNotEmpty()) {
            $this->line(sprintf('active 重複（同碼多筆 active）：%d 組', $groups->count()));
            $this->table(
                ['agent_group_id', 'type', 'currency_id', 'code', 'survivor_id', 'loser_ids'],
                $groups->map(fn (array $group): array => [
                    $group['agent_group_id'],
                    $group['type'],
                    $group['currency_id'] ?? 'NULL',
                    $group['code'],
                    $group['survivor_id'],
                    implode(',', $group['loser_ids']),
                ])->all()
            );
        }

        if ($shadowGroups->isNotEmpty()) {
            $this->line(sprintf('inactive shadow 重複（同名 1 active + n inactive）：%d 組', $shadowGroups->count()));
            $this->table(
                ['agent_group_id', 'type', 'currency_id', 'name', 'code', 'survivor_id', 'shadow_ids'],
                $shadowGroups->map(fn (array $group): array => [
                    $group['agent_group_id'],
                    $group['type'],
                    $group['currency_id'] ?? 'NULL',
                    $group['name'],
                    $group['code'],
                    $group['survivor_id'],
                    implode(',', $group['loser_ids']),
                ])->all()
            );
        }

        if (!$this->option('execute')) {
            $this->info(sprintf(
                '發現 active 重複 %d 組、inactive shadow %d 組（dry-run 模式，未寫入）。加 --execute 以實際執行清理。',
                $groups->count(),
                $shadowGroups->count()
            ));

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm('確認執行清理？此操作將合併會員綁定並停用重複銀行', false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        // 先清 shadow 再清 active 重複：避免 active 清理剛停用的 loser 被本輪 shadow 偵測撿走標刪
        $shadowSummary = $service->executeShadowCleanup($agentGroupIds);

        $this->info(sprintf(
            'shadow 清理完成：%d 組，re-point %d、停用綁定 %d、實名件 re-point %d、標記刪除 %d。',
            $shadowSummary['groups'],
            $shadowSummary['repointed'],
            $shadowSummary['deactivated_bindings'],
            $shadowSummary['repointed_realname_requests'],
            $shadowSummary['soft_deleted_banks']
        ));

        $summary = $service->execute($agentGroupIds);

        $this->info(sprintf(
            '清理完成：%d 組，re-point %d、停用綁定 %d、實名件 re-point %d、停用銀行 %d、人工複核 %d。',
            $summary['groups'],
            $summary['repointed'],
            $summary['deactivated_bindings'],
            $summary['repointed_realname_requests'],
            $summary['deactivated_banks'],
            $summary['flagged']
        ));

        if ($summary['flagged'] > 0) {
            $this->warn(sprintf(
                '⚠️ 有 %d 組疑似 code 重用（名稱不符且 loser 有綁定）已跳過合併，請查 bank_dedup_logs 的 flagged_manual_review 人工處理。',
                $summary['flagged']
            ));
        }

        return self::SUCCESS;
    }

    /**
     * 解析 --agent-group 選項為 id 陣列；未指定回傳 null（整庫）。
     *
     * @return list<int>|null
     */
    private function resolveAgentGroupIds(): ?array
    {
        $raw = trim((string) $this->option('agent-group'));

        if ($raw === '') {
            return null;
        }

        return array_values(array_map(
            'intval',
            array_filter(array_map('trim', explode(',', $raw)), static fn (string $part): bool => $part !== ''),
        ));
    }
}
