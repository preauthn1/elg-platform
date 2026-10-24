<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Helpers\Common;
use App\Services\CashFlow\Bank\BankStandardMappingService;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 回填 `banks.standard_bank_id`：tier1/2 直配與兩跳、tier3 alias 精確比對；信心度 <95 產人工複核佇列。
 *
 * 【分類】Bank — 可複用維運工具，長期保留。
 *
 * ## 退役條件
 *
 * **不設退役條件。** 只要 `standard_banks` registry 還會更新（新增條目、改名、換幣別前綴），
 * 既有 `banks.standard_bank_id` 就需要回填與 `--repair` 重驗。
 *
 * 🔴 **registry 發版≠對應生效**：`cash-flow:seed-standard-banks`／`app:upgrade` 只換 registry，
 * `banks.standard_bank_id` 不會自己跟上，必須另跑本命令。
 *
 * @knowledge rules/standard-bank-list
 */
class SyncBankMappingsCommand extends Command
{
    protected $signature = 'bank:sync-mappings
                            {--dry-run : 僅預覽不寫入}
                            {--export-review= : 匯出人工複核候選 CSV（信心度 <95）至指定檔名（storage/app 下）}
                            {--apply-reviewed= : 套用已裁決的複核 CSV（approve 欄 =1/y 者寫入），與同步互斥}
                            {--repair : 重驗既有 standard_bank_id，與 standard_banks 不符者按 name/alias 精確比對改正，與同步互斥}';

    protected $description = '回填 banks.standard_bank_id：tier1/2 直配與兩跳、tier3 alias 精確（自動）；<95 產人工複核佇列；--repair 重驗既有值';

    public function handle(BankStandardMappingService $mapping): int
    {
        if ($this->option('apply-reviewed') !== null) {
            return $this->runApplyReviewed($mapping);
        }

        if ($this->option('repair')) {
            return $this->runRepair($mapping);
        }

        return $this->runSync($mapping);
    }

    // ---------------------------------------------------------------------
    // 重驗既有映射（--repair）
    // ---------------------------------------------------------------------

    /**
     * 獨立模式，刻意不兼做 tier1/2/3 回填：回填處理 NULL、repair 處理既有值，
     * 兩者的風險與可逆性不同（改既有值會動到已在服務中的分組依據），分開跑才好各自預覽與稽核。
     */
    private function runRepair(BankStandardMappingService $mapping): int
    {
        $candidates = $mapping->repairCandidates();

        if ($candidates->isEmpty()) {
            $this->info('  既有 standard_bank_id 與 standard_banks 全數相符，無需改正');

            return self::SUCCESS;
        }

        $standardCodes = DB::table('standard_banks')->pluck('code', 'id');
        $rows = $candidates
            ->groupBy(static fn (array $candidate): string => sprintf(
                '%s → %s',
                $standardCodes[$candidate['from_standard_bank_id']] ?? ('#' . $candidate['from_standard_bank_id']),
                $standardCodes[$candidate['standard_bank_id']] ?? ('#' . $candidate['standard_bank_id']),
            ))
            ->map(static fn (Collection $group): array => [
                (string) $group->count(),
                implode(',', array_slice($group->pluck('bank_id')->all(), 0, 10)) . ($group->count() > 10 ? ',…' : ''),
            ])
            ->sortKeys()
            ->map(static fn (array $cells, string $transition): array => [$transition, ...$cells])
            ->values()
            ->all();

        $this->table(['現值 → 應為', '筆數', 'bank_id'], $rows);
        $this->info(sprintf('  待改正合計：%d 筆', $candidates->count()));

        if ($this->option('dry-run')) {
            $this->info('  [dry-run] 未寫入');

            return self::SUCCESS;
        }

        try {
            $repaired = DB::transaction(static fn (): int => $mapping->repairMismatched());
        } catch (Throwable $e) {
            Log::error('sync-mappings repair failed', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return self::FAILURE;
        }

        $this->info(sprintf('  已改正：%d 筆（差額＝候選算出後已被他處改動，樂觀鎖跳過）', $repaired));

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------------
    // 同步主流程（tier 1/2/3 ＋ 可選複核匯出）
    // ---------------------------------------------------------------------

    private function runSync(BankStandardMappingService $mapping): int
    {
        // preview 與 fill 共用同一組 query builder（preview 用 count()、fill 用 update()），
        // dry-run 印出的可回填數保證等於實跑實際 update 的列數（含 tier3：已扣除 tier1/2 命中集合）。
        $group0 = $this->group0Query()->count();
        $groupN = $this->previewGroupPositive();
        $tier12Hits = $this->tier12HitIds();
        $tier3 = $mapping->tier3Candidates()
            ->reject(static fn (array $candidate): bool => in_array($candidate['bank_id'], $tier12Hits, true))
            ->count();

        $this->table(
            ['層級', '可回填筆數'],
            [
                ['tier1 group=0 (currency_id,code)→standard_banks', (string) $group0],
                ['tier2 group>0 兩跳→group=0→standard_banks', (string) $groupN],
                ['tier3 name/alias 精確→standard_banks', (string) $tier3],
            ],
        );

        if ($this->option('dry-run')) {
            $this->exportReviewIfRequested($mapping);
            $this->info('  [dry-run] 未寫入');

            return self::SUCCESS;
        }

        try {
            $tier3Filled = 0;
            DB::transaction(function () use ($mapping, &$tier3Filled): void {
                $this->fillWithAudit($mapping);
                $tier3Filled = $mapping->fillTier3();
            });
            $this->info(sprintf('  tier3 alias 自動回填：%d', $tier3Filled));
        } catch (Throwable $e) {
            Log::error('sync-mappings failed', ['error' => $e->getMessage(), 'trace' => Common::formatTrace($e)]);

            return self::FAILURE;
        }

        $this->exportReviewIfRequested($mapping);

        // 殘餘統計的 liveness 口徑＝deleted_at IS NULL（沿用已退役 AuditGroupBanksCommand 的定義）。
        $remaining = DB::table('banks')->whereNull('standard_bank_id')->whereNull('deleted_at')->count();
        $this->info(sprintf('  完成；仍未映射的存活 banks：%d', $remaining));

        return self::SUCCESS;
    }

    /**
     * tier1/2 批次回填；UPDATE JOIN 前以同源 query 取回填對寫審計（比照 bank_dedup_logs 慣例）。
     */
    private function fillWithAudit(BankStandardMappingService $mapping): void
    {
        $mapping->auditBatch($this->pairs($this->group0Query(), 'b.id', 'sb.id'), 'code_direct', 'exact_code');
        // DB::raw('sb.id') 為欄位對欄位引用（靜態欄名），非使用者輸入，無 SQL Injection 風險。
        $this->group0Query()->update(['b.standard_bank_id' => DB::raw('sb.id')]);

        $mapping->auditBatch($this->pairs($this->codeHopQuery(), 'child.id', 'code_res.std_id'), 'code_hop', 'hop_code');
        $this->codeHopQuery()->update(['child.standard_bank_id' => DB::raw('code_res.std_id')]);

        // whereNull('child.standard_bank_id') 使本跳只補 code-hop 未命中者。
        $mapping->auditBatch($this->pairs($this->nameHopQuery(), 'child.id', 'name_res.std_id'), 'name_hop', 'hop_name');
        $this->nameHopQuery()->update(['child.standard_bank_id' => DB::raw('name_res.std_id')]);
    }

    /**
     * tier1/2 將命中的 bank id 聯集（preview 用，供 tier3 計數扣除重疊）。
     *
     * @return list<int>
     */
    private function tier12HitIds(): array
    {
        $union = $this->group0Query()->select('b.id')
            ->union($this->codeHopQuery()->select('child.id'))
            ->union($this->nameHopQuery()->select('child.id'));

        return DB::query()->fromSub($union, 'u')->pluck('id')->map(static fn ($id): int => (int) $id)->all();
    }

    /**
     * @return list<array{bank_id: int, standard_bank_id: int}>
     */
    private function pairs(Builder $query, string $bankIdColumn, string $stdIdColumn): array
    {
        return $query->select([$bankIdColumn . ' as bank_id', DB::raw($stdIdColumn . ' as standard_bank_id')])
            ->get()
            ->map(static fn (object $row): array => [
                'bank_id' => (int) $row->bank_id,
                'standard_bank_id' => (int) $row->standard_bank_id,
            ])
            ->all();
    }

    private function exportReviewIfRequested(BankStandardMappingService $mapping): void
    {
        $file = $this->option('export-review');
        if ($file === null || $file === '') {
            return;
        }

        $rows = $mapping->reviewCandidates();
        $path = storage_path('app/' . basename((string) $file));

        $handle = fopen($path, 'w');
        fputcsv($handle, ['bank_id', 'agent_group_id', 'currency_code', 'bank_name', 'candidate_standard_bank_id', 'candidate_code', 'candidate_name', 'confidence', 'matched_by', 'approve']);
        foreach ($rows as $row) {
            fputcsv($handle, [...array_values($row), '']);
        }
        fclose($handle);

        $this->info(sprintf('  人工複核候選 %d 筆已匯出：%s', count($rows), $path));
    }

    // ---------------------------------------------------------------------
    // 套用人工裁決 CSV
    // ---------------------------------------------------------------------

    private function runApplyReviewed(BankStandardMappingService $mapping): int
    {
        $path = storage_path('app/' . basename((string) $this->option('apply-reviewed')));
        if (!is_file($path)) {
            $this->error("找不到複核 CSV：{$path}");

            return self::FAILURE;
        }

        $rows = $this->parseReviewedCsv($path);
        $this->info(sprintf('已核准（approve=1/y）待套用：%d 筆', count($rows)));

        if ($this->option('dry-run')) {
            $this->info('  [dry-run] 未寫入');

            return self::SUCCESS;
        }

        $result = DB::transaction(static fn (): array => $mapping->applyReviewed($rows));

        foreach ($result['skipped'] as $reason) {
            $this->warn("  跳過 {$reason}");
        }
        $this->info(sprintf('  套用完成：%d 筆（跳過 %d）', $result['applied'], count($result['skipped'])));

        return self::SUCCESS;
    }

    /**
     * @return list<array{bank_id: int, standard_bank_id: int, confidence: int}>
     */
    private function parseReviewedCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);
        $rows = [];
        while (($line = fgetcsv($handle)) !== false) {
            $row = array_combine($header, $line);
            if (!in_array(mb_strtolower(trim((string) ($row['approve'] ?? ''))), ['1', 'y', 'yes'], true)) {
                continue;
            }
            $rows[] = [
                'bank_id' => (int) $row['bank_id'],
                'standard_bank_id' => (int) $row['candidate_standard_bank_id'],
                'confidence' => (int) $row['confidence'],
            ];
        }
        fclose($handle);

        return $rows;
    }

    // ---------------------------------------------------------------------
    // 共用 query builder factory（preview 與 fill 同源，杜絕漂移）
    // ---------------------------------------------------------------------

    /**
     * group>0 可回填數 = code-hop 命中的 child ∪ name-hop 命中的 child（去重，同一 child 只計一次）。
     * 對應實跑：fillWithAudit 先寫 code 命中者、name-hop 只補其餘（whereNull 天然排除已寫者），
     * 兩者相加恰為兩集合的聯集大小，故此處以 UNION 去重後 count 等於實際 update 列數。
     */
    private function previewGroupPositive(): int
    {
        $union = $this->codeHopQuery()->select('child.id')
            ->union($this->nameHopQuery()->select('child.id'));

        return DB::query()->fromSub($union, 'u')->count();
    }

    /**
     * group=0 driving：存活 group=0 且尚未映射者，以 (currency_id, code) 直接對 standard_banks。
     * standard_banks UNIQUE(code, currency_id) 保證每筆 b 至多命中一列 sb，count 等於 update 列數。
     */
    private function group0Query(): Builder
    {
        return DB::table('banks as b')
            ->join('standard_banks as sb', fn ($j) => $j
                ->on('sb.currency_id', '=', 'b.currency_id')
                ->on('sb.code', '=', 'b.code'))
            ->where('b.agent_group_id', 0)
            ->whereNull('b.standard_bank_id')
            ->whereNull('b.deleted_at');
    }

    /**
     * group>0 code-hop：child 以 (currency_id, code) 對到 group=0 base 解析出的 standard_bank_id。
     * code_res 為每個 (currency_id, code) 一列的物化衍生表（standard_banks 唯一鍵背書，無歧義，
     * 且 GROUP BY 強制物化，避開 MySQL 更新目標表自參照的 1093 限制）。
     */
    private function codeHopQuery(): Builder
    {
        return DB::table('banks as child')
            ->joinSub($this->codeResolution(), 'code_res', fn ($j) => $j
                ->on('code_res.currency_id', '=', 'child.currency_id')
                ->on('code_res.code', '=', 'child.code'))
            ->where('child.agent_group_id', '>', 0)
            ->whereNull('child.standard_bank_id')
            ->whereNull('child.deleted_at');
    }

    /**
     * group>0 name-hop：child 以 (currency_id, name) 對到 group=0 base 解析出的 standard_bank_id。
     * name_res 僅保留「該 (currency_id, name) 在存活 group=0 base 解析出唯一 standard_bank_id」者
     * （HAVING COUNT(DISTINCT sb.id) = 1）；若歧義（>1 distinct）則不產列 → child 不回填、留 NULL。
     */
    private function nameHopQuery(): Builder
    {
        return DB::table('banks as child')
            ->joinSub($this->nameResolution(), 'name_res', fn ($j) => $j
                ->on('name_res.currency_id', '=', 'child.currency_id')
                ->on('name_res.name', '=', 'child.name'))
            ->where('child.agent_group_id', '>', 0)
            ->whereNull('child.standard_bank_id')
            ->whereNull('child.deleted_at');
    }

    /**
     * (currency_id, code) → standard_bank_id，來源限存活 group=0 base。
     */
    private function codeResolution(): Builder
    {
        return DB::table('banks as base')
            ->join('standard_banks as sb', fn ($j) => $j
                ->on('sb.currency_id', '=', 'base.currency_id')
                ->on('sb.code', '=', 'base.code'))
            ->where('base.agent_group_id', 0)
            ->whereNull('base.deleted_at')
            ->groupBy('base.currency_id', 'base.code')
            ->select('base.currency_id', 'base.code', DB::raw('MIN(sb.id) as std_id'));
    }

    /**
     * (currency_id, name) → 唯一 standard_bank_id，來源限存活 group=0 base，僅取無歧義者。
     */
    private function nameResolution(): Builder
    {
        return DB::table('banks as base')
            ->join('standard_banks as sb', fn ($j) => $j
                ->on('sb.currency_id', '=', 'base.currency_id')
                ->on('sb.code', '=', 'base.code'))
            ->where('base.agent_group_id', 0)
            ->whereNull('base.deleted_at')
            ->groupBy('base.currency_id', 'base.name')
            ->havingRaw('COUNT(DISTINCT sb.id) = 1')
            ->select('base.currency_id', 'base.name', DB::raw('MIN(sb.id) as std_id'));
    }
}
