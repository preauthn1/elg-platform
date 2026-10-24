<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Bank;

use App\Models\Bank;
use App\Services\CashFlow\Bank\BankMismatchAuditor;
use App\Services\CashFlow\Bank\BankRepairService;
use App\Services\Database\TableBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 銀行 DB 修復命令
 *
 * 【分類】Bank — 銀行收斂軌①主力工具，⚠️ 部分模式非 sync-safe（退役條件見下方）。
 *
 * 兩類修復：
 * - mismatch  : DB row name 不等於 standard，rename
 * - duplicate : 多 row 解析到同 standard，合併
 *
 * 預設 audit-only。--apply 才寫入。
 *
 * ⚠️ `--apply` 強制要求 `--backup`（要跳過必須明示 `--skip-backup` 並二次確認）：
 * 備份表是校驗與回滾的唯一依據——改動後自動以備份表逐列校驗，疏失時走
 * `db:backup-rollback` 從備份表逐列還原（`bank:dedup-rollback` 消費的是
 * exact 白名單、與本命令的 `mismatch_*` 審計天然隔離，救不了這裡）。
 *
 * 使用範例：
 *   php artisan bank:fix-mismatch                              # 全 5 幣別 audit
 *   php artisan bank:fix-mismatch --currency=IDR               # 單一幣別 audit
 *   php artisan bank:fix-mismatch --currency=IDR --apply --dry-run   # 唯讀預演（不需備份）
 *   php artisan bank:fix-mismatch --currency=IDR --apply --backup --force
 *   php artisan bank:fix-mismatch --currency=IDR --apply --dry-run --prune-inactive-collisions  # 預演 prune 計畫
 *   php artisan bank:fix-mismatch --currency=IDR --apply --backup --prune-inactive-collisions --force
 *
 * `--prune-inactive-collisions`（2026-08-25 Codex 複查後定案；gate 4 於 2026-08-26 擴充）：
 * 撞鍵留置組中符合以下任一者，刪冗餘列後放行合併——
 * ①「恰一側 cfb `status=0`、另一側 `status=1`、且停用側 provider 碼有活列承接」⇒ 刪停用列
 * ②「兩側皆 `status=1` 且 provider 碼嚴格相同」⇒ 刪 duplicate 側（送三方的碼一樣，挑碼問題
 * 不存在；primary 側 bank_code 與合併後 banks.code 天然一致）。
 * 同組任一撞點不合格則整組零寫入留置（all-or-nothing）。刪除逐列審計
 * `mismatch_cfb_pruned`，完整 before 值在備份表，出錯以
 * `db:backup-rollback --recreate-missing` 重建（保留原 id，cfb 無 inbound FK——兩站已實查）。
 *
 * `--collision-keep=primary|duplicate`（2026-08-27 RB 拍板）＝**人工已挑碼**，覆寫上述 gate ①②
 * 的自動判準，撞點一律保留指定側的 cfb 列、刪另一側。用於「gate 判不出來、但人工有外部依據」的
 * 撞點；審計 detail 多記 `keep` 供事後與自動放行區分。硬性前置＝必須搭 `--prune-inactive-collisions`
 * 與 `--bank=`（各組挑碼方向不同，不限單一組等於盲套）。指定保留的那側 `status=0` 時仍 fail-closed。
 *
 *   php artisan bank:fix-mismatch --currency=IDR --apply --dry-run --force \
 *       --prune-inactive-collisions --bank=709 --collision-keep=duplicate
 *
 * `--include-inactive`（2026-08-27）＝連 `status=0` 的列一起稽核＝**停用列的命名盤點**。
 * 原始動機是「金流資料同時建測試組與正式組兩份、正式組先設 `status=0` 驗證通過才啟用」，
 * 想在上線前攔到 drift。⚠️ **實測不支持該動機**：TPower 三層的 `status=0` 列絕大多數是已
 * 退場的舊列（g674 VND 16 筆全是 2023-12-27 建的 `TPVNxx` 白標舊碼），故**不可當成上線前
 * 攔截工具**。**純報表、fail-closed 拒絕與 `--apply` 併用**；放行 status=0 時另排除軟刪列
 * 與「曾作為 loser 被收斂停用」的列（後者必然報 mismatch 且必然配成 duplicate＝正常退場）。
 *
 *   php artisan bank:fix-mismatch --agent-group=674 --include-inactive
 *
 * `--absorb-inactive=<banks.id>`（2026-09-07）＝人工點名一列停用列併入 `--primary=` 指定的服務中列。
 * auditor 只撈 `status=1` 且依 standard 分組，「停用列上還掛著活綁定、承接列名稱不同又沒回填
 * standard」的組（VWorld g70 `267`「Maybank」5 筆綁定 → `265`「Malayan Banking Berhad, Singapore
 * Branch」實例）永遠配不起來，而代付 ACF 的項目已改指承接列的碼、綁停用列的會員送單會查無碼被擋。
 * 本旗標跳過分組，其餘身分判準（type／currency／group／standard）與整套 FK 改指／cfb 碼同步／
 * ACF JSON 改寫／備份／校驗／審計原樣走，另記 `mismatch_inactive_merged`；曾作為 loser 被收斂過的
 * 停用列一律拒絕（綁定早已移轉）。強制搭 `--primary=` 與 `--apply`，拒絕與 `--bank`／
 * `--include-inactive`／`--collision-keep` 併用。
 *
 *   php artisan bank:fix-mismatch --absorb-inactive=267 --primary=265 --apply --dry-run --force
 *   php artisan bank:fix-mismatch --absorb-inactive=267 --primary=265 --apply --backup --force
 *
 * ## 退役條件
 *
 * 當 `cash-flow:sync` 改以 `standard_banks` 為單一來源、不再各金流商各自 seed base 列、
 * 也不再靠銀行名稱模糊比對之後，mismatch 與 duplicate 不會再生，本命令即可刪除。
 * 在那之前每跑一次 sync 都可能長出新的一批。
 *
 * ⚠️ `--collision-keep` 人工挑的 `provider_bank_code` 會被 sync 重建回配置檔的值
 * （見 `docs/未完成計劃/金流商架構重構/銀行收斂/sync全量重建面與補償命令.md` 的補償命令清單）。
 */
class BankFixMismatchCommand extends Command
{
    protected $signature = 'bank:fix-mismatch
                            {--currency= : 指定幣別 (MYR|VND|SGD|INR|IDR)，省略則全部}
                            {--agent-group= : 稽核／修復指定 agent group 層的 banks（預設 0＝base 層；group 層合併時 ACF JSON 改寫限該 group）}
                            {--bank= : 只處理涉及此 bank id 的 mismatch／duplicate 組（單點修復用）}
                            {--primary= : 指定該 bank id 為存活方（覆寫「組內最小 id」預設，用於保 canonical 碼而非白標舊碼；不在任何組內則報錯）}
                            {--json : JSON 輸出}
                            {--apply : 套用修復（預設只 audit）}
                            {--dry-run : 與 --apply 並用，只顯示不寫入}
                            {--force : 與 --apply 並用，跳過確認}
                            {--backup : 與 --apply 並用，執行前備份（--apply 預設必要）}
                            {--skip-backup : 與 --apply 並用，明示不備份（無回滾工具，另需確認）}
                            {--prune-inactive-collisions : 撞鍵留置組中「恰一側停用且碼有活列承接」刪停用列、「兩側皆活且 provider 碼相同」刪 dup 側，放行合併（強制 --backup，出錯以 --recreate-missing 重建）}
                            {--coerce-type-to-primary : 合併時放行 type 標記矛盾的組、以 primary 側 type 為準（限確認 dup 側為誤建批才帶，審計記 mismatch_type_coerced）}
                            {--collision-keep= : 人工已挑碼：撞點一律保留 primary|duplicate 側的 cash_flow_banks 列（覆寫 gate 2／4 自動判準，強制搭 --prune-inactive-collisions 與 --bank=）}
                            {--include-inactive : 連 status=0 的列一起稽核（看尚未上線的正式組資料有無 drift）；純報表，拒絕與 --apply 併用}
                            {--absorb-inactive= : 人工點名一列 status=0 的停用列（banks.id）併入 --primary= 指定的服務中列：跳過 auditor 分組、身分判準除 status 外照常、審計另記 mismatch_inactive_merged；強制搭 --primary= 與 --apply（預演加 --dry-run），拒絕與 --bank／--include-inactive／--collision-keep 併用}';

    protected $description = '修復 banks 表與 standard 的不一致（rename / merge）';

    /** 備份時整表快照的小型配置表（正是會被改寫的三張，列數皆四位數以內） */
    private const BACKUP_FULL_TABLES = [
        'banks',
        'cash_flow_banks',
        'agent_cash_flows',
    ];

    /**
     * 備份時只備 `bank_id IN (dup ids)` 的大型會員事實表。
     *
     * 與 BankRepairService::FK_TABLES 對齊（cash_flow_banks 已在整表組）；
     * ⚠️ 同樣不含 order_images——其 bank_id 指 bank_accounts.id，非 banks.id。
     *
     * @var list<string>
     */
    private const BACKUP_DUP_SCOPED_TABLES = [
        'withdrawal_orders',
        'member_bank_accounts',
        'realname_validation_requests',
        'agent_bank_accounts',
        'agent_real_name_validation_requests',
    ];

    public function __construct(
        protected BankMismatchAuditor $auditor,
        protected BankRepairService $repairer,
        protected TableBackupService $backup,
    ) {
        parent::__construct();
    }

    /**
     * @param  array<string, array{duplicate: list<array{duplicates: list<array{bank_id: int}>}>}>  $audit
     * @return list<int>
     */
    private function collectDuplicateIds(array $audit): array
    {
        $ids = [];

        foreach ($audit as $data) {
            foreach ($data['duplicate'] as $group) {
                foreach ($group['duplicates'] as $dup) {
                    $ids[] = (int) $dup['bank_id'];
                }
            }
        }

        return array_values(array_unique($ids));
    }

    public function handle(): int
    {
        if ($this->option('absorb-inactive') !== null) {
            $audit = $this->buildAbsorbInactiveAudit((string) $this->option('absorb-inactive'));

            return $audit === null ? self::FAILURE : $this->applyAll($audit, absorbInactive: true);
        }

        $currencies = $this->resolveCurrencies();
        if (empty($currencies)) {
            $this->error('未找到任何指定幣別');

            return self::FAILURE;
        }

        $agentGroupId = (int) ($this->option('agent-group') ?? 0);
        $bankFilter = $this->option('bank') !== null ? (int) $this->option('bank') : null;
        $primaryOverride = $this->option('primary') !== null ? (int) $this->option('primary') : null;
        $includeInactive = (bool) $this->option('include-inactive');

        // 停用列的修復方向要另外判斷（可能是尚未上線的正式組資料、也可能是已退場的舊列），
        // 同一套 rename／merge 判準不見得適用 ⇒ 本旗標只出報表，不給修。
        if ($includeInactive && $this->option('apply')) {
            $this->error('--include-inactive 是唯讀稽核（停用列的修復方向需另判），不可與 --apply 併用。');

            return self::FAILURE;
        }

        $audit = [];
        $primaryOverrideHit = false;

        foreach ($currencies as $code => $id) {
            $full = $this->auditor->auditCurrency($code, $id, $agentGroupId, $includeInactive);
            $groups = $this->filterByBank($full['duplicate'], $bankFilter, isGroup: true);
            $repointed = $this->overridePrimary($groups, $primaryOverride);
            $primaryOverrideHit = $primaryOverrideHit || $repointed['hit'];

            $audit[$code] = [
                'currency_id' => $id,
                'mismatch' => $this->filterByBank($full['mismatch'], $bankFilter, isGroup: false),
                'duplicate' => $repointed['groups'],
            ];
        }

        // 打錯 id 而靜默照跑預設方向＝把「人工指定的收斂方向」悄悄換掉，比報錯危險
        if ($primaryOverride !== null && !$primaryOverrideHit) {
            $this->error(sprintf('--primary=%d 不在任何 duplicate 組內（範圍：%s）。', $primaryOverride, implode('／', array_keys($currencies))));

            return self::FAILURE;
        }

        if ($this->option('apply')) {
            return $this->applyAll($audit);
        }

        if ($this->option('json')) {
            $this->renderJson($audit);
        } else {
            $this->renderText($audit);
        }

        return self::SUCCESS;
    }

    /**
     * `--bank=` 單點過濾：mismatch 看該筆的 bank_id；duplicate 看 primary 或任一 duplicate 命中。
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function filterByBank(array $items, ?int $bankId, bool $isGroup): array
    {
        if ($bankId === null) {
            return $items;
        }

        return array_values(array_filter($items, function (array $item) use ($bankId, $isGroup): bool {
            if (!$isGroup) {
                return (int) $item['bank_id'] === $bankId;
            }

            if ((int) $item['primary_bank_id'] === $bankId) {
                return true;
            }

            foreach ($item['duplicates'] as $dup) {
                if ((int) $dup['bank_id'] === $bankId) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * `--primary=` 指定存活方：auditor 預設取組內最小 id 當 primary（穩定但不管語意），
     * 而「保白標舊碼還是 canonical 碼」是碼治理決策——g19 實測 primary 側（`607` VWSG002）
     * 八筆出款單零成功、唯一啟用中的代付 ACF 只收 canonical 碼，預設方向會把綁定留在死路上。
     *
     * 只重排指定 id 所在的那一組（原 primary 降為 duplicate），其餘組不動。
     *
     * @param  list<array{primary_bank_id: int, primary_name?: string, duplicates: list<array{bank_id: int, name?: string}>}>  $groups
     * @return array{groups: list<array<string, mixed>>, hit: bool}
     */
    private function overridePrimary(array $groups, ?int $primaryId): array
    {
        if ($primaryId === null) {
            return ['groups' => $groups, 'hit' => false];
        }

        $hit = false;

        foreach ($groups as $i => $group) {
            if ((int) $group['primary_bank_id'] === $primaryId) {
                $hit = true; // 已是 primary，方向本來就對

                continue;
            }

            foreach ($group['duplicates'] as $j => $dup) {
                if ((int) $dup['bank_id'] !== $primaryId) {
                    continue;
                }

                $hit = true;
                $demoted = ['bank_id' => (int) $group['primary_bank_id'], 'name' => (string) ($group['primary_name'] ?? '')];
                $others = array_values(array_filter(
                    $group['duplicates'],
                    fn (array $d): bool => (int) $d['bank_id'] !== $primaryId,
                ));

                $groups[$i]['primary_bank_id'] = $primaryId;
                $groups[$i]['primary_name'] = (string) ($dup['name'] ?? '');
                $groups[$i]['duplicates'] = [$demoted, ...$others];

                unset($j);

                break;
            }
        }

        return ['groups' => array_values($groups), 'hit' => $hit];
    }

    /**
     * `--absorb-inactive=` 的前置檢查與單組 audit 組裝（不經 auditor）。
     *
     * 前置條件全部 fail-closed：duplicate 必須是 `status=0` 且未軟刪、primary 必須 `status=1`、
     * 兩列同 agent group 同幣別、幣別在支援清單內；`--agent-group`／`--currency` 若帶則必須與
     * 兩列相符（帶了卻不符＝操作者以為在動別的組，比靜默照跑危險）。組裝出來的結構與 auditor
     * 的 duplicate 組同形，後段 applyAll（備份、預演、實跑、校驗）完全共用。
     *
     * @return array<string, array{currency_id: int, mismatch: list<never>, duplicate: list<array<string, mixed>>}>|null null＝前置條件不成立（已印錯誤）
     */
    private function buildAbsorbInactiveAudit(string $rawDupId): ?array
    {
        if (preg_match('/^\d+$/', $rawDupId) !== 1) {
            $this->error(sprintf('--absorb-inactive 必須是純數字的 banks.id，收到「%s」。', $rawDupId));

            return null;
        }

        if (!$this->option('apply')) {
            $this->error('--absorb-inactive 沒有 audit 報表可看，請帶 --apply（預演加 --dry-run）。');

            return null;
        }

        if ($this->option('primary') === null) {
            $this->error('--absorb-inactive 必須搭 --primary= 指定承接的服務中列（人工指定併入沒有預設方向）。');

            return null;
        }

        foreach (['bank', 'include-inactive', 'collision-keep'] as $conflicting) {
            if ($this->option($conflicting)) {
                $this->error(sprintf('--absorb-inactive 已點名唯一一組，不可與 --%s 併用。', $conflicting));

                return null;
            }
        }

        $dupId = (int) $rawDupId;
        $primaryId = (int) $this->option('primary');
        $dup = Bank::query()->find($dupId);
        $primary = Bank::query()->find($primaryId);

        if (!$dup) {
            $this->error(sprintf('duplicate id=%d 不存在。', $dupId));

            return null;
        }

        if (!$primary) {
            $this->error(sprintf('primary id=%d 不存在。', $primaryId));

            return null;
        }

        if ($dupId === $primaryId) {
            $this->error('duplicate 與 primary 相同，拒絕自我合併。');

            return null;
        }

        if ((int) $dup->status !== 0) {
            $this->error(sprintf('duplicate id=%d 不是停用列（status=%d）：服務中的列走一般稽核路徑（--bank=／--primary=）。', $dupId, (int) $dup->status));

            return null;
        }

        if ($dup->deleted_at !== null) {
            $this->error(sprintf('duplicate id=%d 已軟刪除（deleted_at=%s），不併入。', $dupId, (string) $dup->deleted_at));

            return null;
        }

        if ((int) $primary->status !== 1) {
            $this->error(sprintf('primary id=%d 不是服務中的列（status=%d），不能當承接方。', $primaryId, (int) $primary->status));

            return null;
        }

        if ((int) $dup->agent_group_id !== (int) $primary->agent_group_id) {
            $this->error(sprintf('兩列不同 agent group（duplicate %d vs primary %d），不是同一實體。', (int) $dup->agent_group_id, (int) $primary->agent_group_id));

            return null;
        }

        if ((int) $dup->currency_id !== (int) $primary->currency_id) {
            $this->error(sprintf('兩列不同幣別（currency_id %d vs %d），不是同一實體。', (int) $dup->currency_id, (int) $primary->currency_id));

            return null;
        }

        $agentGroupOption = $this->option('agent-group');

        if ($agentGroupOption !== null && (int) $agentGroupOption !== (int) $dup->agent_group_id) {
            $this->error(sprintf('--agent-group=%s 與兩列所屬的 agent group %d 不符。', (string) $agentGroupOption, (int) $dup->agent_group_id));

            return null;
        }

        $supported = array_map(static fn ($id): int => (int) $id, $this->auditor->supportedCurrencies());
        $currencyCode = array_search((int) $dup->currency_id, $supported, true);

        if ($currencyCode === false) {
            $this->error(sprintf('兩列的幣別 currency_id=%d 不在本命令支援的幣別清單（%s）內。', (int) $dup->currency_id, implode('／', array_keys($supported))));

            return null;
        }

        $currencyOption = $this->option('currency');

        if ($currencyOption !== null && $currencyOption !== $currencyCode) {
            $this->error(sprintf('--currency=%s 與兩列的幣別 %s 不符。', (string) $currencyOption, $currencyCode));

            return null;
        }

        $usage = $this->collectUsage([$dupId, $primaryId]);
        $this->info(sprintf('人工點名併入停用列（--absorb-inactive）：%s／agent group %d', $currencyCode, (int) $dup->agent_group_id));
        $this->line(sprintf('  承接 primary   id=%d "%s"  %s', $primaryId, (string) $primary->name, $this->describeUsage($usage[$primaryId] ?? null)));
        $this->line(sprintf('  併入 duplicate id=%d "%s"（status=0）  %s', $dupId, (string) $dup->name, $this->describeUsage($usage[$dupId] ?? null)));

        return [
            $currencyCode => [
                'currency_id' => (int) $dup->currency_id,
                'mismatch' => [],
                'duplicate' => [[
                    'standard_name' => '(人工點名)',
                    'primary_bank_id' => $primaryId,
                    'primary_name' => (string) $primary->name,
                    'duplicates' => [['bank_id' => $dupId, 'name' => (string) $dup->name]],
                ]],
            ],
        ];
    }

    protected function resolveCurrencies(): array
    {
        $supported = $this->auditor->supportedCurrencies();
        $filter = $this->option('currency');

        if ($filter === null) {
            return $supported;
        }

        return isset($supported[$filter]) ? [$filter => $supported[$filter]] : [];
    }

    /**
     * `--collision-keep=` 的取值與前置條件檢查。
     *
     * 這是人工挑碼的授權旗標，覆寫 gate 2／4 的自動判準（見 BankRepairService::classifyPruneCandidates），
     * 所以兩道前置條件都是硬性的：
     * - **強制搭 `--prune-inactive-collisions`**：撞點放行的 DELETE 與強制備份的邏輯都掛在那支旗標下，
     *   單獨帶本旗標不會有任何效果，靜默無效比報錯糟。
     * - **強制搭 `--bank=`**：一次執行的各組挑碼方向不會相同（實例：VWorld `709`／`721` 要保 duplicate 側、
     *   `989` 要保 primary 側），不限單一組等於把一個組的裁決盲套到別組。
     *
     * @return string|false|null 合法值、驗證失敗（false）、或未指定（null）
     */
    private function resolveCollisionKeep(bool $prune): string|false|null
    {
        $raw = $this->option('collision-keep');

        if ($raw === null || $raw === '') {
            return null;
        }

        $value = (string) $raw;
        $allowed = [BankRepairService::COLLISION_KEEP_PRIMARY, BankRepairService::COLLISION_KEEP_DUPLICATE];

        if (!in_array($value, $allowed, true)) {
            $this->error(sprintf('--collision-keep 只接受 %s，收到 "%s"。', implode('|', $allowed), $value));

            return false;
        }

        if (!$prune) {
            $this->error('--collision-keep 是撞點放行的挑碼授權，必須搭 --prune-inactive-collisions 才有作用。');

            return false;
        }

        if ($this->option('bank') === null) {
            $this->error('--collision-keep 必須搭 --bank= 限定單一組：各組的挑碼方向不同，不限範圍等於盲套。');

            return false;
        }

        return $value;
    }

    protected function applyAll(array $audit, bool $absorbInactive = false): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $isForce = (bool) $this->option('force');
        $prune = (bool) $this->option('prune-inactive-collisions');
        $coerceType = (bool) $this->option('coerce-type-to-primary');
        $collisionKeep = $this->resolveCollisionKeep($prune);
        $skippedTotal = 0;

        if ($collisionKeep === false) {
            return self::FAILURE;
        }

        // prune 是 DELETE：備份表是重建（--recreate-missing）的唯一來源，不容明示跳過
        if ($prune && !$isDryRun && $this->option('skip-backup')) {
            $this->error('--prune-inactive-collisions 涉及 DELETE，強制 --backup，不接受 --skip-backup。');

            return self::FAILURE;
        }

        if (!$isDryRun && !$this->ensureBackupDecision($isForce)) {
            return self::FAILURE;
        }

        if (!$isDryRun && !$isForce) {
            if (!$this->confirm('即將套用修復，確認執行？', false)) {
                $this->warn('已取消');

                return self::SUCCESS;
            }
        }

        $backupStamp = null;

        if ($this->option('backup') && !$isDryRun) {
            // 備份成同庫備份表（<原表名>_bak_<stamp>），不再委派 bank:standardize --step=backup
            // ——那條路整表載入記憶體，2026-08-25 VWorld 首跑在 withdrawal_orders（143 萬筆）OOM。
            // 改動後以 diff() 對備份表校驗，疏失時走 db:backup-rollback 逐列還原；
            // 備份表由 db:prune-table-backups 排程在保留期後清除。
            $this->info('建立備份表（配置表整表、大表只備 dup 引用列）...');

            $dupIds = $this->collectDuplicateIds($audit);
            $captured = $this->backup->capture(
                fullTables: self::BACKUP_FULL_TABLES,
                scopedTables: array_fill_keys(
                    self::BACKUP_DUP_SCOPED_TABLES,
                    ['column' => 'bank_id', 'ids' => $dupIds],
                ),
                meta: [
                    'command' => 'bank:fix-mismatch',
                    'currencies' => array_keys($audit),
                    'agent_group_id' => (int) ($this->option('agent-group') ?? 0),
                    'bank_filter' => $this->option('bank'),
                    'absorb_inactive_bank_id' => $this->option('absorb-inactive'),
                    'duplicate_bank_ids' => $dupIds,
                ],
            );
            $backupStamp = $captured['stamp'];

            foreach ($captured['tables'] as $source => $meta) {
                $this->line($meta['backup_table'] === null
                    ? sprintf('  %s → 未建表（%s 範圍 0 筆，紀錄在 manifest）', $source, $meta['scope'])
                    : sprintf('  %s → %s（%s，%d 筆）', $source, $meta['backup_table'], $meta['scope'], $meta['rows']));
            }
        }

        $prunedCfbIds = [];

        foreach ($audit as $code => $data) {
            $this->info(sprintf('=== 處理 %s ===', $code));
            $this->applyRenames($data['mismatch'], $isDryRun);
            $merges = $this->applyMerges($data['duplicate'], $isDryRun, $prune, $coerceType, $collisionKeep, $absorbInactive);
            $skippedTotal += $merges['skipped'];
            $prunedCfbIds = [...$prunedCfbIds, ...$merges['pruned_cfb']];
        }

        if ($skippedTotal > 0) {
            $this->newLine();
            $this->warn(sprintf(
                '⚠ 共 %d 筆 duplicate %s（見上方逐筆原因）。',
                $skippedTotal,
                $isDryRun ? '會被留置未合併' : '留置未合併',
            ));
            $this->line('留置的三種情形：');
            $this->line('  1. 改指 cash_flow_banks 會撞唯一鍵——primary 在同一個 (cash_flow_id,');
            $this->line('     currency_id) 已經有列了。請人工決定保留哪一列的碼、限額與 provider 碼。');
            $this->line('  2. 身分不一致（type／currency／group／standard 不同，或任一側已停用／軟刪除）');
            $this->line('     ——那不是重複，是不同實體。');
            $this->line('  3. duplicate id 等於 primary，或該 row 已不存在——輸入異常，不動它。');
        }

        if ($backupStamp !== null) {
            $this->renderVerification($backupStamp, $audit, $prunedCfbIds);
        }

        $this->info($isDryRun ? '預演完成（未寫入任何資料）' : '完成');

        return self::SUCCESS;
    }

    /**
     * 改動後校驗：live vs 備份表逐列比對，標注每筆差異是否在預期內。
     *
     * 預期判準（結構性，不依賴審計表）：
     * - banks：rename 清單 ∪ dup 清單內的 id
     * - 大表與 cash_flow_banks：備份列的 bank_id 屬 dup 清單（本命令只動這些列）
     * - agent_cash_flows：無結構性判準，一律列出請對照審計 mismatch_acf_changed
     * - missing（live 消失）：id ∈ 本次 prune 清單＝預期 DELETE；否則預期外
     *
     * 預期外的差異醒目警示——那可能是本命令的缺陷，也可能是校驗窗內的並發寫入，都該人看。
     *
     * @param  list<int>  $prunedCfbIds  本次 --prune-inactive-collisions 刪除的 cfb id
     */
    private function renderVerification(string $stamp, array $audit, array $prunedCfbIds = []): void
    {
        $this->newLine();
        $this->info(sprintf('=== 改動後校驗（備份戳 %s）===', $stamp));

        $renameIds = [];
        $dupIds = [];

        foreach ($audit as $data) {
            foreach ($data['mismatch'] as $item) {
                $renameIds[(int) $item['bank_id']] = true;
            }

            foreach ($data['duplicate'] as $group) {
                foreach ($group['duplicates'] as $dup) {
                    $dupIds[(int) $dup['bank_id']] = true;
                }
            }
        }

        $unexpected = 0;

        foreach ($this->backup->diff($stamp) as $source => $result) {
            if ($result['diffs'] === [] && $result['new_ids'] === []) {
                continue;
            }

            $this->line(sprintf('  [%s]', $source));

            foreach ($result['diffs'] as $row) {
                if ($row['missing']) {
                    if ($source === 'cash_flow_banks' && in_array((int) $row['id'], $prunedCfbIds, true)) {
                        $this->line(sprintf('    ✓ id=%d 已刪（prune，可用 --recreate-missing 重建）', $row['id']));

                        continue;
                    }

                    $unexpected++;
                    $this->warn(sprintf('    ⚠ id=%d 在 live 消失——不在本次 prune 清單，請查明', $row['id']));

                    continue;
                }

                $expected = match ($source) {
                    'banks' => isset($renameIds[$row['id']]) || isset($dupIds[$row['id']]),
                    'agent_cash_flows' => true, // 對照審計 mismatch_acf_changed 逐筆核對
                    default => isset($dupIds[(int) ($row['changes']['bank_id'][0] ?? 0)])
                        || !isset($row['changes']['bank_id']),
                };

                $changes = implode('，', array_map(
                    static fn (string $col, array $pair): string => $col === 'info'
                        // agent_cash_flows.info 整包含三方憑證（API_KEY／MERCHANT 等），前 60 字元就會露出
                        // 鍵值——校驗輸出會進終端與 log，憑證入 log 必遮（2026-09-08 VWorld 實跑抓到）。
                        // 改寫的是 available_banks[].BankCode_ELG，逐筆內容對照審計 mismatch_acf_changed。
                        ? sprintf('%s: (JSON 已變更 %d → %d 字元，含憑證不印)', $col, mb_strlen((string) $pair[0]), mb_strlen((string) $pair[1]))
                        : sprintf('%s: %s → %s', $col, self::short($pair[0]), self::short($pair[1])),
                    array_keys($row['changes']),
                    $row['changes'],
                ));

                if ($expected) {
                    $this->line(sprintf('    ✓ id=%d  %s', $row['id'], $changes));
                } else {
                    $unexpected++;
                    $this->warn(sprintf('    ⚠ 預期外 id=%d  %s', $row['id'], $changes));
                }
            }

            if ($result['new_ids'] !== []) {
                $this->line(sprintf(
                    '    ℹ 備份後新增 %d 列（id=%s）——本命令不插列，屬校驗窗內的其他寫入',
                    count($result['new_ids']),
                    implode(', ', array_slice($result['new_ids'], 0, 20)),
                ));
            }
        }

        if ($unexpected > 0) {
            $this->newLine();
            $this->warn(sprintf('⚠ 有 %d 筆預期外差異，請先查明再決定是否以 db:backup-rollback 回滾。', $unexpected));
        } else {
            $this->info('校驗通過：所有差異皆在預期內。');
        }

        $this->line(sprintf('  回滾（如需）：php artisan db:backup-rollback --stamp=%s --table=<表名> --ids=<id,...>', $stamp));
        $this->line('  備份表確認無誤後由人工 DROP TABLE 清理。');
    }

    /**
     * 校驗輸出用的截斷（ACF info 是整包 JSON，全文印出會淹沒差異重點）。
     */
    private static function short(mixed $value): string
    {
        $text = (string) $value;

        return mb_strlen($text) > 60 ? mb_substr($text, 0, 57) . '…' : $text;
    }

    /**
     * @param  list<array{bank_id: int, current_name: string, target_standard: string}>  $mismatches
     */
    private function applyRenames(array $mismatches, bool $isDryRun): void
    {
        foreach ($mismatches as $item) {
            $this->line(sprintf(
                '  rename id=%d  "%s" → "%s"%s',
                $item['bank_id'],
                $item['current_name'],
                $item['target_standard'],
                $isDryRun ? ' [DRY-RUN]' : ''
            ));

            if (!$isDryRun) {
                $this->repairer->applyMismatchRename($item['bank_id'], $item['target_standard']);
            }
        }
    }

    /**
     * 逐組合併（dry-run 走唯讀預檢），回傳留置筆數與本次 prune 刪除的 cfb id。
     *
     * @param  list<array{primary_bank_id: int, duplicates: list<array{bank_id: int}>}>  $groups
     * @return array{skipped: int, pruned_cfb: list<int>}
     */
    private function applyMerges(array $groups, bool $isDryRun, bool $prune, bool $coerceType = false, ?string $collisionKeep = null, bool $absorbInactive = false): array
    {
        $skipped = 0;
        $prunedCfb = [];

        foreach ($groups as $group) {
            $dupIds = array_column($group['duplicates'], 'bank_id');
            $this->line(sprintf(
                '  merge primary=%d, duplicates=[%s]%s',
                $group['primary_bank_id'],
                implode(', ', $dupIds),
                $isDryRun ? ' [DRY-RUN]' : ''
            ));

            if ($isDryRun) {
                $skipped += $this->renderPreview((int) $group['primary_bank_id'], $dupIds, $prune, $coerceType, $collisionKeep, $absorbInactive);

                continue;
            }

            $result = $this->repairer->applyDuplicateMerge($group['primary_bank_id'], $dupIds, $prune, $coerceType, $collisionKeep, $absorbInactive);
            $prunedCfb = [...$prunedCfb, ...$result['pruned_cfb']];

            if ($result['pruned_cfb'] !== []) {
                $this->line(sprintf('    ✂ 已刪撞鍵冗餘列 cfb id=%s（審計 mismatch_cfb_pruned）', implode(', ', $result['pruned_cfb'])));
            }

            foreach ($result['skipped'] as $skip) {
                $skipped++;
                $this->warn(sprintf('    ⏭ 留置 id=%d：%s', $skip['bank_id'], $skip['reason']));
            }
        }

        return ['skipped' => $skipped, 'pruned_cfb' => $prunedCfb];
    }

    /**
     * `--dry-run` 的逐筆預檢輸出，回傳會被留置的筆數。
     *
     * 沒有這一段的話 `--dry-run` 只印得出「打算合併哪幾個 id」，跑不到任何一道判斷——留置與否、
     * 代付 ACF 兩碼曝露全都看不見，等於沒有預演。判斷本身在 Service 端與實跑共用同一批方法。
     *
     * @param  list<int>  $dupIds
     */
    private function renderPreview(int $primaryId, array $dupIds, bool $prune = false, bool $coerceType = false, ?string $collisionKeep = null, bool $absorbInactive = false): int
    {
        $skipped = 0;

        foreach ($this->repairer->previewDuplicateMerge($primaryId, $dupIds, $prune, $coerceType, $collisionKeep, $absorbInactive) as $row) {
            if ($row['verdict'] === 'skip') {
                $skipped++;
                $this->warn(sprintf('    ⏭ 會留置 id=%d：%s', $row['bank_id'], $row['reason']));

                continue;
            }

            $this->line(sprintf('    ✓ 可合併 id=%d%s', $row['bank_id'], $this->describePreview($row)));

            if ($row['inactive_absorbed']) {
                $this->warn('      ⚠ duplicate 是停用列（--absorb-inactive 人工點名）：只移轉綁定與引用、不再改 status（審計 mismatch_inactive_merged）');
            }

            if ($row['type_coerced'] !== null) {
                $this->warn(sprintf(
                    '      ⚠ type 標記矛盾（%s）：旗標放行、綁定改走 primary 的 type（審計 mismatch_type_coerced）',
                    $row['type_coerced'],
                ));
            }

            if ($row['cfb_pruned'] !== []) {
                $this->warn(sprintf(
                    '      ✂ 將刪撞鍵冗餘列 cfb id=%s（停用有承接或兩側同碼；出錯以 db:backup-rollback --recreate-missing 重建）',
                    implode(', ', $row['cfb_pruned']),
                ));
            }

            if ($row['acf_conflicts'] !== []) {
                $this->warn(sprintf(
                    '      ⚠ %d 張代付 agent_cash_flows 同時含兩碼、不會被改寫（acf id=%s）。'
                    . '這些的 available_banks 會留著 duplicate 的舊碼，而新提款單帶的是 primary 的碼'
                    . '⇒ 舊 JSON 查表路徑會查不到而擋單。請人工決定這幾張怎麼處理。',
                    count($row['acf_conflicts']),
                    implode(', ', $row['acf_conflicts']),
                ));
            }

            if ($row['cfb_code_kept'] !== []) {
                $this->warn(sprintf(
                    '      ⚠ %d 列 cash_flow_banks 的 bank_code 原本就與 bank.code 不同步、保留不動'
                    . '（cfb id=%s）——那是既有 drift，改成 primary 的碼會直接改變查表結果。',
                    count($row['cfb_code_kept']),
                    implode(', ', $row['cfb_code_kept']),
                ));
            }
        }

        return $skipped;
    }

    /**
     * @param  array{fk: array<string, int>, cfb_code_synced: list<int>, cfb_code_kept: list<int>, acf_changed: list<int>, acf_conflicts: list<int>}  $row
     */
    private function describePreview(array $row): string
    {
        $fk = [];

        foreach ($row['fk'] as $table => $count) {
            $fk[] = sprintf('%s=%d', $table, $count);
        }

        return sprintf(
            '  [外鍵改指 %s｜碼表同步=%d 保留=%d｜代付 ACF 改寫=%d 留置=%d]',
            $fk === [] ? '無' : implode(' ', $fk),
            count($row['cfb_code_synced']),
            count($row['cfb_code_kept']),
            count($row['acf_changed']),
            count($row['acf_conflicts']),
        );
    }

    /**
     * 寫入前的備份把關。
     *
     * 這命令沒有回滾工具，`--backup` 的快照是唯一的 before 值來源，所以 `--apply` 預設強制帶。
     *
     * ⚠️ 快照的真實身分是**人工回推材料**，不是可執行還原：整表灌回會抹掉備份後新進的提款單與
     * 會員綁定，實務上不可用。備份的價值在於「出錯時查得到原本的 bank_id／bank_code 是什麼」。
     */
    private function ensureBackupDecision(bool $isForce): bool
    {
        if ($this->option('backup')) {
            return true;
        }

        if (!$this->option('skip-backup')) {
            $this->error('--apply 需要 --backup：這個命令沒有回滾工具，備份快照是唯一的 before 值來源。');
            $this->line('  想先看會動到什麼：改帶 --dry-run（唯讀預演，不需備份）。');
            $this->line('  確定不要備份：改帶 --skip-backup 明示（會再確認一次）。');

            return false;
        }

        $this->warn('⚠ 已帶 --skip-backup，本次不備份。');
        $this->line('  出錯時沒有 before 值可回推——bank_dedup_logs 只會留下受影響的 row id，');
        $this->line('  這些 row 原本的 bank_id / bank_code 不會留下任何紀錄。');

        if ($isForce) {
            return true;
        }

        return $this->confirm('確認不備份就執行？', false);
    }

    protected function renderText(array $audit): void
    {
        foreach ($audit as $currency => $data) {
            $this->info(str_repeat('=', 60));
            $this->info(sprintf('Currency: %s  (currency_id=%d)', $currency, $data['currency_id']));
            $this->info(str_repeat('=', 60));

            if (!empty($data['mismatch'])) {
                $this->newLine();
                $this->warn(sprintf('[MISMATCH] %d 筆 — 可用 --apply 修正', count($data['mismatch'])));
                foreach ($data['mismatch'] as $item) {
                    $this->line(sprintf(
                        '  - id=%d  "%s" → "%s"  信心度 %d%%',
                        $item['bank_id'],
                        $item['current_name'],
                        $item['target_standard'],
                        $item['confidence']
                    ));
                }
            }

            if (!empty($data['duplicate'])) {
                $this->newLine();
                $this->warn(sprintf('[DUPLICATE] %d 組 — 可用 --apply 合併', count($data['duplicate'])));
                $usage = $this->collectUsage($this->duplicateBankIds($data['duplicate']));

                foreach ($data['duplicate'] as $group) {
                    $primaryId = (int) $group['primary_bank_id'];
                    $this->line(sprintf(
                        '  - 主 row: id=%d "%s"  %s',
                        $primaryId,
                        $group['primary_name'],
                        $this->describeUsage($usage[$primaryId] ?? null),
                    ));

                    foreach ($group['duplicates'] as $dup) {
                        $dupId = (int) $dup['bank_id'];
                        $this->line(sprintf(
                            '    重複: id=%d "%s"  %s',
                            $dupId,
                            $dup['name'],
                            $this->describeUsage($usage[$dupId] ?? null),
                        ));

                        $this->warnIfDirectionSuspect($primaryId, $dupId, $usage);
                    }
                }
            }
        }

        $this->newLine();
        $this->info('Summary:');
        foreach ($audit as $currency => $data) {
            $this->line(sprintf(
                '  %s: mismatch=%d, duplicate=%d',
                $currency,
                count($data['mismatch']),
                count($data['duplicate'])
            ));
        }

        // 放行 status=0 後 duplicate 幾乎沒有判讀價值：混合 status 的組多半是
        // 「活列 ＋ 已停用舊列」的正常退場配對，不是待修 drift。不講明會被當成
        // 憑空多出幾十組待收斂。mismatch 是名稱對不上 standard，與 status 無關，
        // 才是本旗標要看的真訊號。
        if ($this->option('include-inactive')) {
            $this->newLine();
            $this->warn('⚠️ 本報表是「停用列的命名盤點」，數字不可直接當待收斂量：');
            $this->warn('   ・duplicate＝混合 status 的組多為「活列 ＋ 已停用舊列」的正常退場配對');
            $this->warn('   ・mismatch＝多為已退場舊列的舊命名（早年建立的白標舊碼），把它們 rename');
            $this->warn('     成 canonical 名會讓歷史單據的 bank_name snapshot 與現況對不上');
            $this->warn('     ⇒ 本旗標刻意不給修（拒絕 --apply）');
        }
    }

    /**
     * 取出一個幣別所有 duplicate 組涉及的 bank id（primary ＋ duplicates）。
     *
     * @param  list<array{primary_bank_id: int, duplicates: list<array{bank_id: int}>}>  $groups
     * @return list<int>
     */
    private function duplicateBankIds(array $groups): array
    {
        $ids = [];

        foreach ($groups as $group) {
            $ids[] = (int) $group['primary_bank_id'];

            foreach ($group['duplicates'] as $dup) {
                $ids[] = (int) $dup['bank_id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * 批次取這些 banks 的引用數，供人工判斷合併方向。
     *
     * 一律批次查（三個 groupBy），不在渲染迴圈裡逐列查。
     *
     * @param  list<int>  $bankIds
     * @return array<int, array{cfb_active: int, mba: int, wo: int}>
     */
    private function collectUsage(array $bankIds): array
    {
        if ($bankIds === []) {
            return [];
        }

        $counts = [
            'cfb_active' => DB::table('cash_flow_banks')->whereIn('bank_id', $bankIds)->where('status', 1),
            'mba' => DB::table('member_bank_accounts')->whereIn('bank_id', $bankIds),
            'wo' => DB::table('withdrawal_orders')->whereIn('bank_id', $bankIds),
        ];

        $usage = array_fill_keys($bankIds, ['cfb_active' => 0, 'mba' => 0, 'wo' => 0]);

        foreach ($counts as $key => $query) {
            $rows = $query->groupBy('bank_id')
                ->selectRaw('bank_id, COUNT(*) as aggregate')
                ->pluck('aggregate', 'bank_id');

            foreach ($rows as $bankId => $count) {
                $usage[(int) $bankId][$key] = (int) $count;
            }
        }

        return $usage;
    }

    /**
     * @param  array{cfb_active: int, mba: int, wo: int}|null  $usage
     */
    private function describeUsage(?array $usage): string
    {
        if ($usage === null) {
            return '[引用數未取得]';
        }

        return sprintf(
            '[活躍碼表=%d 會員綁定=%d 出款單=%d]',
            $usage['cfb_active'],
            $usage['mba'],
            $usage['wo'],
        );
    }

    /**
     * primary 取的是 id 最小者，不代表它就是營運上的承接方。
     *
     * 若 duplicate 的活躍碼表引用不少於 primary，合併方向很可能是反的——會把還在服務的
     * 引用搬到舊列上、再把新列停用。這裡只警示、不阻擋，方向屬營運判斷。
     *
     * @param  array<int, array{cfb_active: int, mba: int, wo: int}>  $usage
     */
    private function warnIfDirectionSuspect(int $primaryId, int $dupId, array $usage): void
    {
        $primaryActive = $usage[$primaryId]['cfb_active'] ?? 0;
        $dupActive = $usage[$dupId]['cfb_active'] ?? 0;

        if ($dupActive === 0 || $dupActive < $primaryActive) {
            return;
        }

        $this->warn(sprintf(
            '    ⚠ 這筆 duplicate 的活躍碼表引用不少於 primary（%d vs %d）。primary 取的是 id 最小者，'
            . '不代表它是營運上的承接方——合併方向請人工確認。',
            $dupActive,
            $primaryActive,
        ));
    }

    protected function renderJson(array $audit): void
    {
        $output = [
            'generated_at' => now()->toIso8601String(),
            'currencies' => [],
            'summary' => ['total_mismatch' => 0, 'total_duplicate_groups' => 0],
        ];

        foreach ($audit as $currency => $data) {
            $output['currencies'][$currency] = [
                'currency_id' => $data['currency_id'],
                'mismatch' => $data['mismatch'],
                'duplicate' => $data['duplicate'],
            ];
            $output['summary']['total_mismatch'] += count($data['mismatch']);
            $output['summary']['total_duplicate_groups'] += count($data['duplicate']);
        }

        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }
}
