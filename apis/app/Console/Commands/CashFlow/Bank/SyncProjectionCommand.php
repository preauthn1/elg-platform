<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Helpers\Common;
use App\Services\CashFlow\Bank\BankProjectionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 全量投影：`banks`(group>0) → `agent_banks` 重建，並同步 `cash_flow_banks.standard_bank_id` 便利欄。
 *
 * 【分類】Bank — 可複用維運工具，長期保留。
 *
 * ## 歧義三分（2026-09-14 起）
 *
 * 同 (group, standard) 多存活列時以「啟用中的那列」定代表，輸出分三種：
 *
 * - **自動解歧義**：恰 1 列 `status=1` ⇒ 投影該列。⚠️ 這只表示投影選得出代表，
 *   **碼層的重複並沒有收斂掉**；清單看 `bank:converge-cross-code`。
 * - **歧義跳過 `multiple_active`**：≥2 列同時啟用 ⇒ 待人工裁定或 dedup 收斂。
 * - **歧義跳過 `no_active`**：多列存活但全部停用 ⇒ 該軟刪的未軟刪。
 *
 * 舊版是「多存活列一律整組跳過」（不看 status）。別拿改版前的「歧義組」數字與改版後對比，
 * 兩者口徑不同：VWorld 25 組在新口徑下是 17 組自動解 ＋ 8 組跳過。
 *
 * ## 退役條件
 *
 * **不設退役條件。** `agent_banks` 是投影表、`cash_flow_banks.standard_bank_id` 是便利欄，
 * 兩者都以 `banks` 為上游；只要上游還會變動就需要重新投影。
 *
 * ⚠️ 新建 `cash_flow_banks` 列的命令（如 `bank:add-cfb-rows`）不代寫該便利欄，
 * 跑完那類命令後應補跑一次本命令。
 */
class SyncProjectionCommand extends Command
{
    protected $signature = 'bank:sync-projection
                            {--dry-run : 僅預覽統計不寫入}';

    protected $description = '全量投影：banks(group>0)→agent_banks 重建＋cash_flow_banks.standard_bank_id 便利欄同步；附歧義組與映射缺口報告';

    public function handle(BankProjectionService $projection): int
    {
        // 分揀（純讀）與寫入共用同一結果／同一 query builder，dry-run 計數保證等於實跑寫入數
        $candidates = $projection->agentBankCandidates();
        $cfbDrift = $projection->cfbLinkDriftQuery()->count();
        $gap = $projection->gapReport();

        $this->table(
            ['項目', '筆數'],
            [
                ['agent_banks 投影列（全量重建）', (string) count($candidates['rows'])],
                ['自動解歧義組（多存活列但唯一啟用）', (string) count($candidates['resolved'])],
                ['歧義跳過組（同 group 同 standard 無唯一啟用列）', (string) count($candidates['skipped'])],
                ['cfb.standard_bank_id 待同步（含 drift 修正）', (string) $cfbDrift],
                ['未映射存活 banks（group>0）', (string) $gap['unmapped_alive_group_banks']],
                ['未映射存活 banks（group=0 基底）', (string) $gap['unmapped_alive_base_banks']],
            ],
        );

        foreach ($candidates['resolved'] as $resolved) {
            $this->line(sprintf(
                '  自動解歧義：group=%d standard_bank_id=%d bank_ids=[%s] → 取啟用列 %d（碼層重複仍在，清單見 bank:converge-cross-code）',
                $resolved['agent_group_id'],
                $resolved['standard_bank_id'],
                implode(',', $resolved['bank_ids']),
                $resolved['source_bank_id'],
            ));
        }

        foreach ($candidates['skipped'] as $skipped) {
            $this->warn(sprintf(
                '  歧義跳過：group=%d standard_bank_id=%d bank_ids=[%s] 原因=%s（%s）',
                $skipped['agent_group_id'],
                $skipped['standard_bank_id'],
                implode(',', $skipped['bank_ids']),
                $skipped['reason'],
                $skipped['reason'] === BankProjectionService::SKIP_MULTIPLE_ACTIVE
                    ? '多列同時啟用，代表無法自動決定，待人工裁定或 dedup 收斂'
                    : '多列存活但全部停用，無代表可選；該軟刪的未軟刪',
            ));
        }

        if ($this->option('dry-run')) {
            $this->info('  [dry-run] 未寫入');

            return self::SUCCESS;
        }

        try {
            [$written, $cfbChanged] = DB::transaction(static fn (): array => [
                $projection->rebuildAgentBanks($candidates['rows']),
                $projection->syncCfbStandardBankIds(),
            ]);
        } catch (Throwable $e) {
            Log::error('sync-projection failed', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return self::FAILURE;
        }

        $this->info(sprintf('  完成：agent_banks 重建 %d 列、cfb 便利欄異動 %d 列', $written, $cfbChanged));

        return self::SUCCESS;
    }
}
