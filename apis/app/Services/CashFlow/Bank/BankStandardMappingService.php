<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 補配軌：banks.standard_bank_id 的 alias 自動回填（tier 3）、人工複核佇列與既有值重驗（repair）。
 *
 * 匹配資料源＝standard_banks（name＋aliases，config 權威經 SyncStandardBanksSeeder 落表），
 * keyword 候選讀各幣別分檔 app/Console/Commands/CashFlow/Data/banks/{幣別}.php。信心度分級沿用專案慣例：
 * name 精確=100、alias 精確=95（兩者自動回填）；keyword=80、相似度 ≤89（人工複核佇列）。
 * 自動回填的範圍＝存活列（status=1 且 deleted_at IS NULL），歷史列等跨站彙總需求出現再補；
 * 人工複核套用（applyReviewed）只檢查未映射與未標刪，不檢查 status，可回填 inactive 列。
 * 回填是 NULL→值的加法操作，逐筆寫 bank_mapping_logs 審計，反向即依審計置回 NULL；
 * no-match 的處置是補 config aliases 再重跑，不在本 Service 內猜。
 *
 * ⚠️ bank_mapping_logs.action 的實際值域是 auto_fill / reviewed_fill / reverted / **repair**——
 * 建表 migration（2026_08_12_100000）的欄位註解寫於 repair 之前，只列前三個，以此處為準。
 * repair 與回填的分工：回填只碰 NULL（whereNull 護欄），repair 只碰既有值（樂觀鎖比對原值）。
 *
 * @knowledge rules/standard-bank-list
 */
class BankStandardMappingService
{
    private const CONF_NAME = 100;

    private const CONF_ALIAS = 95;

    private const CONF_KEYWORD = 80;

    private const SIMILARITY_MIN = 70;

    private const SIMILARITY_CAP = 89;

    /**
     * tier 3 候選（唯讀）：未映射存活列以 name/alias 精確對 standard_banks。
     *
     * @return Collection<int, array{bank_id: int, standard_bank_id: int, confidence: int, matched_by: string}>
     */
    public function tier3Candidates(): Collection
    {
        $lookup = $this->buildStandardLookup();

        return $this->unmappedLiveBanks()
            ->map(function (object $bank) use ($lookup): ?array {
                $match = $this->matchExact($lookup, (int) $bank->currency_id, (string) $bank->name);

                return $match === null ? null : [
                    'bank_id' => (int) $bank->id,
                    'standard_bank_id' => $match['standard_bank_id'],
                    'confidence' => $match['confidence'],
                    'matched_by' => $match['matched_by'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * tier 3 自動回填＋審計。回傳實際回填筆數（whereNull 護欄，併發已填者跳過）。
     */
    public function fillTier3(): int
    {
        $filled = 0;
        foreach ($this->tier3Candidates() as $candidate) {
            $updated = DB::table('banks')
                ->where('id', $candidate['bank_id'])
                ->whereNull('standard_bank_id')
                ->update(['standard_bank_id' => $candidate['standard_bank_id']]);

            if ($updated === 1) {
                $this->writeAudit($candidate['bank_id'], $candidate['standard_bank_id'], 'alias', $candidate['confidence'], $candidate['matched_by'], 'auto_fill');
                $filled++;
            }
        }

        return $filled;
    }

    /**
     * repair 候選（唯讀）：已映射存活列中，既有 standard_bank_id 與 name/alias 精確比對結果不符者。
     *
     * 為何需要這一軌：standard_banks 補進細分條目後（Syariah 子行、Corporate Account、POSB 等），
     * 早年以粗粒度 registry 回填的既有值就成了錯的，而 fillTier3 的 whereNull 護欄只填空、碰不到已有值。
     * 名稱無精確命中者一律不列入——無從判斷正確值時保留既有值，不猜也不清空。
     *
     * @return Collection<int, array{bank_id: int, from_standard_bank_id: int, standard_bank_id: int, confidence: int, matched_by: string}>
     */
    public function repairCandidates(): Collection
    {
        $lookup = $this->buildStandardLookup();

        return $this->liveBanks(mapped: true)
            ->map(function (object $bank) use ($lookup): ?array {
                $match = $this->matchExact($lookup, (int) $bank->currency_id, (string) $bank->name);
                if ($match === null || $match['standard_bank_id'] === (int) $bank->standard_bank_id) {
                    return null;
                }

                return [
                    'bank_id' => (int) $bank->id,
                    'from_standard_bank_id' => (int) $bank->standard_bank_id,
                    'standard_bank_id' => $match['standard_bank_id'],
                    'confidence' => $match['confidence'],
                    'matched_by' => $match['matched_by'],
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * repair 改正＋審計。回傳實際改正筆數。
     *
     * where('standard_bank_id', 候選算出時讀到的值) 是樂觀鎖：該列若已被他處改動就跳過，
     * 與 fillTier3 的 whereNull 護欄同一個用意——不覆蓋計算基礎之外的狀態。
     */
    public function repairMismatched(): int
    {
        $repaired = 0;
        foreach ($this->repairCandidates() as $candidate) {
            $updated = DB::table('banks')
                ->where('id', $candidate['bank_id'])
                ->where('standard_bank_id', $candidate['from_standard_bank_id'])
                ->update(['standard_bank_id' => $candidate['standard_bank_id']]);

            if ($updated === 1) {
                $this->writeAudit($candidate['bank_id'], $candidate['standard_bank_id'], 'alias', $candidate['confidence'], $candidate['matched_by'], 'repair');
                $repaired++;
            }
        }

        return $repaired;
    }

    /**
     * 人工複核候選（唯讀）：tier 3 之後仍未映射者，以 keyword／相似度產生每列最佳候選。
     *
     * @return list<array{bank_id: int, agent_group_id: int, currency_code: string, bank_name: string, candidate_standard_bank_id: int, candidate_code: string, candidate_name: string, confidence: int, matched_by: string}>
     */
    public function reviewCandidates(): array
    {
        $standards = $this->loadStandards();
        $lookup = $this->buildStandardLookup();
        $keywords = $this->loadConfigKeywords();
        $currencyCodes = DB::table('currencies')->pluck('code', 'id');

        $rows = [];
        foreach ($this->unmappedLiveBanks() as $bank) {
            // exact 可命中者屬 tier3 自動回填範疇，不進人工佇列（dry-run 匯出時 tier3 未寫入，靠此排除）；
            // exact 歧義者 matchExact 回 null，自然落入複核
            if ($this->matchExact($lookup, (int) $bank->currency_id, (string) $bank->name) !== null) {
                continue;
            }

            $best = $this->bestFuzzyCandidate(
                $standards[(int) $bank->currency_id] ?? [],
                $keywords,
                $this->normalizeName((string) $bank->name),
            );
            if ($best === null) {
                continue;
            }

            $rows[] = [
                'bank_id' => (int) $bank->id,
                'agent_group_id' => (int) $bank->agent_group_id,
                'currency_code' => (string) ($currencyCodes[(int) $bank->currency_id] ?? $bank->currency_id),
                'bank_name' => (string) $bank->name,
                'candidate_standard_bank_id' => $best['standard_bank_id'],
                'candidate_code' => $best['code'],
                'candidate_name' => $best['name'],
                'confidence' => $best['confidence'],
                'matched_by' => $best['matched_by'],
            ];
        }

        return $rows;
    }

    /**
     * 套用人工裁決列（approve 過的複核 CSV 列）：驗證仍未映射＋standard 存在後寫入＋審計。
     *
     * @param  list<array{bank_id: int, standard_bank_id: int, confidence: int}>  $rows
     * @return array{applied: int, skipped: list<string>}
     */
    public function applyReviewed(array $rows): array
    {
        $applied = 0;
        $skipped = [];

        foreach ($rows as $row) {
            if (!DB::table('standard_banks')->where('id', $row['standard_bank_id'])->exists()) {
                $skipped[] = "bank {$row['bank_id']}：standard_bank {$row['standard_bank_id']} 不存在";

                continue;
            }

            $updated = DB::table('banks')
                ->where('id', $row['bank_id'])
                ->whereNull('standard_bank_id')
                ->whereNull('deleted_at')
                ->update(['standard_bank_id' => $row['standard_bank_id']]);

            if ($updated !== 1) {
                $skipped[] = "bank {$row['bank_id']}：已映射、已標刪或不存在";

                continue;
            }

            $this->writeAudit($row['bank_id'], $row['standard_bank_id'], 'reviewed', $row['confidence'], 'manual', 'reviewed_fill');
            $applied++;
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * 批次審計（tier 1/2 的 UPDATE JOIN 前先以同源 query 取回填對，由命令呼叫）。
     *
     * @param  list<array{bank_id: int, standard_bank_id: int}>  $pairs
     */
    public function auditBatch(array $pairs, string $tier, string $matchedBy): void
    {
        foreach ($pairs as $pair) {
            $this->writeAudit($pair['bank_id'], $pair['standard_bank_id'], $tier, self::CONF_NAME, $matchedBy, 'auto_fill');
        }
    }

    /**
     * 建立路徑即時解析（Phase 2 防再生）：新 banks 列落庫前以 name/alias 精確匹配。
     * lookup 以實例記憶化——同一命令跑批建多列時只建一次查找表。
     *
     * @return array{standard_bank_id: int, confidence: int, matched_by: string}|null null＝無精確命中或歧義
     */
    public function resolveOnCreate(int $currencyId, string $name): ?array
    {
        $this->lookupMemo ??= $this->buildStandardLookup();

        return $this->matchExact($this->lookupMemo, $currencyId, $name);
    }

    /**
     * 建立路徑的審計落帳（呼叫端於新列取得 id 後補記）。
     */
    public function auditFill(int $bankId, int $standardBankId, string $tier, int $confidence, string $matchedBy): void
    {
        $this->writeAudit($bankId, $standardBankId, $tier, $confidence, $matchedBy, 'auto_fill');
    }

    // ---------------------------------------------------------------------
    // 內部
    // ---------------------------------------------------------------------

    /** @var array<int, array{name: array<string, list<int>>, alias: array<string, list<int>>}>|null */
    private ?array $lookupMemo = null;

    /** @return Collection<int, \stdClass> */
    private function unmappedLiveBanks(): Collection
    {
        return $this->liveBanks(mapped: false);
    }

    /**
     * 存活列（status=1 且 deleted_at IS NULL），依 standard_bank_id 是否已有值分流：
     * mapped=false 供 tier 3 回填、mapped=true 供 repair 重驗。
     *
     * @return Collection<int, \stdClass>
     */
    private function liveBanks(bool $mapped): Collection
    {
        return DB::table('banks')
            ->when(
                $mapped,
                static fn ($query) => $query->whereNotNull('standard_bank_id'),
                static fn ($query) => $query->whereNull('standard_bank_id'),
            )
            ->whereNull('deleted_at')
            ->where('status', 1)
            ->get(['id', 'agent_group_id', 'currency_id', 'name', 'standard_bank_id']);
    }

    /**
     * standard_banks 查找表：currency_id → ['name' => [key => ids], 'alias' => [key => ids]]。
     * 同 key 對到多個 standard id＝歧義，匹配時跳過不自動回填。
     *
     * @return array<int, array{name: array<string, list<int>>, alias: array<string, list<int>>}>
     */
    private function buildStandardLookup(): array
    {
        $lookup = [];
        foreach ($this->loadStandards() as $currencyId => $standards) {
            foreach ($standards as $standard) {
                $lookup[$currencyId]['name'][$this->normalizeName($standard['name'])][] = $standard['standard_bank_id'];
                foreach ($standard['aliases'] as $alias) {
                    $lookup[$currencyId]['alias'][$this->normalizeName($alias)][] = $standard['standard_bank_id'];
                }
            }
        }

        return $lookup;
    }

    /**
     * @return array<int, list<array{standard_bank_id: int, code: string, name: string, aliases: list<string>}>>
     */
    private function loadStandards(): array
    {
        $standards = [];
        foreach (DB::table('standard_banks')->where('status', 1)->get(['id', 'currency_id', 'code', 'name', 'aliases']) as $row) {
            $aliases = json_decode((string) ($row->aliases ?? '[]'), true);
            $standards[(int) $row->currency_id][] = [
                'standard_bank_id' => (int) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'aliases' => is_array($aliases) ? $aliases : [],
            ];
        }

        return $standards;
    }

    /**
     * config keywords：standard code（含幣別前綴，與 standard_banks.code 同格式）→ keywords
     * （比對用小寫）。此處與 SyncStandardBanksSeeder 都直接沿用 config code——兩處格式若
     * 不一致，bestFuzzyCandidate 以 DB code 查此表會靜默回空、keyword tier 整層失效。
     *
     * @return array<string, list<string>>
     */
    private function loadConfigKeywords(): array
    {
        $keywords = [];

        foreach (glob(app_path('Console/Commands/CashFlow/Data/banks/*.php')) ?: [] as $path) {
            if (preg_match('/^[A-Z]{3}$/', basename($path, '.php')) !== 1) {
                continue;
            }

            foreach (require $path as $entry) {
                $keywords[(string) $entry['code']] = array_map(mb_strtolower(...), $entry['keywords'] ?? []);
            }
        }

        return $keywords;
    }

    /**
     * @param  array<int, array{name: array<string, list<int>>, alias: array<string, list<int>>}>  $lookup
     * @return array{standard_bank_id: int, confidence: int, matched_by: string}|null
     */
    private function matchExact(array $lookup, int $currencyId, string $name): ?array
    {
        $key = $this->normalizeName($name);
        // 「唯一命中」看的是有幾家 standard 擁有這個鍵，不是鍵出現幾次：分檔慣例常同時列
        // 'VietABank' 與 'VIETABANK'，正規化後同鍵、同家，去重前會被誤判成兩家爭用而整筆對不到。
        $byName = array_values(array_unique($lookup[$currencyId]['name'][$key] ?? []));
        if (count($byName) === 1) {
            return ['standard_bank_id' => $byName[0], 'confidence' => self::CONF_NAME, 'matched_by' => 'name'];
        }

        $byAlias = array_values(array_unique($lookup[$currencyId]['alias'][$key] ?? []));
        if (count($byAlias) === 1) {
            return ['standard_bank_id' => $byAlias[0], 'confidence' => self::CONF_ALIAS, 'matched_by' => 'alias'];
        }

        return null;
    }

    /**
     * @param  list<array{standard_bank_id: int, code: string, name: string, aliases: list<string>}>  $standards
     * @param  array<string, list<string>>  $keywords
     * @return array{standard_bank_id: int, code: string, name: string, confidence: int, matched_by: string}|null
     */
    private function bestFuzzyCandidate(array $standards, array $keywords, string $normalizedName): ?array
    {
        $best = null;
        foreach ($standards as $standard) {
            $score = $this->fuzzyScore($standard, $keywords[$standard['code']] ?? [], $normalizedName);
            if ($score !== null && ($best === null || $score['confidence'] > $best['confidence'])) {
                $best = [
                    'standard_bank_id' => $standard['standard_bank_id'],
                    'code' => $standard['code'],
                    'name' => $standard['name'],
                    'confidence' => $score['confidence'],
                    'matched_by' => $score['matched_by'],
                ];
            }
        }

        return $best;
    }

    /**
     * @param  array{name: string}  $standard
     * @param  list<string>  $keywords
     * @return array{confidence: int, matched_by: string}|null
     */
    private function fuzzyScore(array $standard, array $keywords, string $normalizedName): ?array
    {
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($normalizedName, $keyword)) {
                return ['confidence' => self::CONF_KEYWORD, 'matched_by' => 'keyword'];
            }
        }

        similar_text($normalizedName, $this->normalizeName($standard['name']), $percent);
        if ($percent >= self::SIMILARITY_MIN) {
            return ['confidence' => min((int) round($percent), self::SIMILARITY_CAP), 'matched_by' => 'similarity'];
        }

        return null;
    }

    /** 剝 '(CUR) - ' 前綴＋trim＋case-fold，對齊 banks 命名慣例（(CUR) - {Name}）。 */
    private function normalizeName(string $name): string
    {
        return mb_strtolower(trim((string) preg_replace('/^\([A-Z]{3}\)\s*-\s*/', '', trim($name))));
    }

    private function writeAudit(int $bankId, int $standardBankId, string $tier, int $confidence, string $matchedBy, string $action): void
    {
        DB::table('bank_mapping_logs')->insert([
            'connection' => (string) config('database.default'),
            'bank_id' => $bankId,
            'standard_bank_id' => $standardBankId,
            'tier' => $tier,
            'confidence' => $confidence,
            'matched_by' => $matchedBy,
            'action' => $action,
        ]);
    }
}
