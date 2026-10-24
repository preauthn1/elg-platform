<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * 銀行表拆分第一期：banks（權威）→ agent_banks／cash_flow_banks.standard_bank_id 單向投影。
 *
 * 冪等全量投影：banks 每站僅千級列數，全量重建便宜且不殘留 drift；
 * 15 個寫入口多為 DB::table() 批次 update、Observer 蓋不到，故不採增量。
 * 零消費者階段投影表可棄可重建；寫入權威反轉（各白標改用同一份銀行主檔）前本 Service 是
 * agent_banks 唯一寫入者。
 *
 * 後續期的順序（尚未開工）：第二期先拿 withdrawal/banks 做 shadow-read 影子比對試點；之後才是
 * 讀取灰度切換、sync／guard／dedup 維運命令圈重寫、最後寫入權威反轉。不要跳過影子比對直接切讀取。
 */
readonly class BankProjectionService
{
    /** 跳過原因：同 (group, standard) 有 ≥2 列啟用中，代表無法自動決定。 */
    public const SKIP_MULTIPLE_ACTIVE = 'multiple_active';

    /** 跳過原因：同 (group, standard) 多列存活但全部停用，無代表可選。 */
    public const SKIP_NO_ACTIVE = 'no_active';

    /**
     * 分揀 agent_banks 投影候選（純讀）。dry-run 與實跑共用本方法，
     * 保證「dry-run 計數＝實跑寫入數」不變式。
     *
     * 來源：agent_group_id>0、存活（deleted_at IS NULL）、已映射（standard_bank_id NOT NULL）。
     *
     * 同 (agent_group_id, standard_bank_id) 多筆存活列時，以「啟用中的那列」定代表：
     *
     * - 恰 1 列 status=1 ⇒ 投影該列，另記一筆進 resolved（碼層重複仍在，只是不擋投影）
     * - ≥2 列 status=1   ⇒ skipped，reason=multiple_active（真歧義，非人判斷不可）
     * - 0 列 status=1    ⇒ skipped，reason=no_active（全停用，無代表可選）
     *
     * ⚠️ 舊版是「多筆存活列一律整組跳過」（不看 status）。VWorld 實測 25 組歧義裡有 17 組
     * 其實只有一列是啟用的——擋住投影的不是兩家銀行在打架，是分揀規則沒看 status。
     * 改後那 17 組自動有代表，剩 4 組兩列都活（SGD_HSBC 企業戶／個人戶）與 4 組全停用。
     *
     * resolved 不是裝飾：碼層的重複沒有因為投影通過就消失。少了這一桶，命令輸出會從
     * 「25 組歧義」變成「8 組」，讀的人會誤以為另外 17 組已經處理完。碼層收斂清單的權威
     * 出處仍是 bank:converge-cross-code（CrossCodeConvergenceService 刻意不看 status）。
     *
     * @return array{
     *     rows: list<array{agent_group_id: int, standard_bank_id: int, source_bank_id: int, min_amount: string, max_amount: string, status: int}>,
     *     skipped: list<array{agent_group_id: int, standard_bank_id: int, bank_ids: list<int>, reason: string}>,
     *     resolved: list<array{agent_group_id: int, standard_bank_id: int, source_bank_id: int, bank_ids: list<int>}>
     * }
     */
    public function agentBankCandidates(): array
    {
        $source = DB::table('banks')
            ->where('agent_group_id', '>', 0)
            ->whereNull('deleted_at')
            ->whereNotNull('standard_bank_id')
            ->orderBy('id')
            ->get(['id', 'agent_group_id', 'standard_bank_id', 'min_amount', 'max_amount', 'status']);

        $rows = [];
        $skipped = [];
        $resolved = [];
        foreach ($source->groupBy(static fn (object $bank): string => $bank->agent_group_id . ':' . $bank->standard_bank_id) as $group) {
            $bank = $group->first();

            if ($group->count() > 1) {
                $active = $group->where('status', 1);

                if ($active->count() !== 1) {
                    $skipped[] = [
                        'agent_group_id' => (int) $bank->agent_group_id,
                        'standard_bank_id' => (int) $bank->standard_bank_id,
                        'bank_ids' => self::bankIds($group),
                        'reason' => $active->isEmpty() ? self::SKIP_NO_ACTIVE : self::SKIP_MULTIPLE_ACTIVE,
                    ];

                    continue;
                }

                $bank = $active->first();
                $resolved[] = [
                    'agent_group_id' => (int) $bank->agent_group_id,
                    'standard_bank_id' => (int) $bank->standard_bank_id,
                    'source_bank_id' => (int) $bank->id,
                    'bank_ids' => self::bankIds($group),
                ];
            }

            $rows[] = [
                'agent_group_id' => (int) $bank->agent_group_id,
                'standard_bank_id' => (int) $bank->standard_bank_id,
                'source_bank_id' => (int) $bank->id,
                'min_amount' => (string) $bank->min_amount,
                'max_amount' => (string) $bank->max_amount,
                'status' => (int) $bank->status,
            ];
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'resolved' => $resolved];
    }

    /**
     * @param  Collection<int, stdClass>  $group
     * @return list<int>
     */
    private static function bankIds(Collection $group): array
    {
        return $group->pluck('id')->map(static fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * 全刪重建 agent_banks（呼叫端包 transaction）。回傳寫入列數。
     *
     * @param  list<array{agent_group_id: int, standard_bank_id: int, source_bank_id: int, min_amount: string, max_amount: string, status: int}>  $rows
     */
    public function rebuildAgentBanks(array $rows): int
    {
        // 批次寫入效能豁免：全量重建（千級列數）走 DB::table，AgentBank 逐筆 create 無必要
        DB::table('agent_banks')->delete();

        $now = now();
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('agent_banks')->insert(array_map(
                static fn (array $row): array => [...$row, 'created_at' => $now, 'updated_at' => $now],
                $chunk,
            ));
        }

        return count($rows);
    }

    /**
     * 「值會變」的 cfb 列（preview 用 count()、實跑用 update()，同源保證 preview＝實跑）。
     * NULL-safe 比較用 <=>，null→值、值→null、值→值三種 drift 都抓。
     * join 不濾 banks 軟刪：便利欄語意＝「bank_id 指向誰就投誰的 standard」，歷史連結照投。
     */
    public function cfbLinkDriftQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('cash_flow_banks as cfb')
            ->join('banks as b', 'b.id', '=', 'cfb.bank_id')
            ->whereRaw('NOT (cfb.standard_bank_id <=> b.standard_bank_id)');
    }

    /**
     * cfb.standard_bank_id 便利欄全量同步（呼叫端包 transaction）。回傳異動列數。
     */
    public function syncCfbStandardBankIds(): int
    {
        // DB::raw 為欄位對欄位引用（靜態欄名），非使用者輸入，無 SQL Injection 風險
        return $this->cfbLinkDriftQuery()->update(['cfb.standard_bank_id' => DB::raw('b.standard_bank_id')]);
    }

    /**
     * 映射缺口報告：存活未映射的 banks 列數（投影品質閘門的觀測面）。
     *
     * @return array{unmapped_alive_group_banks: int, unmapped_alive_base_banks: int}
     */
    public function gapReport(): array
    {
        $unmapped = DB::table('banks')
            ->whereNull('deleted_at')
            ->whereNull('standard_bank_id')
            ->selectRaw('SUM(agent_group_id > 0) as group_banks, SUM(agent_group_id = 0) as base_banks')
            ->first();

        return [
            'unmapped_alive_group_banks' => (int) ($unmapped->group_banks ?? 0),
            'unmapped_alive_base_banks' => (int) ($unmapped->base_banks ?? 0),
        ];
    }
}
