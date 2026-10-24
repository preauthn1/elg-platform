<?php

declare(strict_types=1);

namespace App\Services\CashFlow\Bank;

use App\Models\AgentCashFlow;
use App\Models\Bank;
use App\Traits\PreservesRowTimestamps;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * 銀行 DB 修復 Service
 *
 * 抽取自 bank:standardize 的合併與外鍵連動邏輯，供 bank:fix-mismatch 共用。
 *
 * 🔴 **改名與合併一律經本 Service 或其上層命令，禁止直接 UPDATE banks 或硬刪列**
 * ——繞過的話下面那份 FK 連動清單不會跑，引用面直接留孤兒。偵測側
 * （{@see BankMismatchAuditor}、{@see CrossCodeConvergenceService}）零寫入，寫入一律走 rename 管線。
 *
 * 🔴 **新增任何引用 `banks.id` 或 `banks.code` 的資料表時，必須同步登記進
 * {@see self::reassignForeignKeys()} 的連動清單**，否則合併會留下指向已被合併掉那列的孤兒，
 * 且不會有任何錯誤訊號。最易漏查的引用面是 cash_flow_banks——bank_id 與 bank_code 兩個欄位都要查。
 *
 * 三個 public method：
 * - applyMismatchRename   : 重命名 banks.name（純 UPDATE，無 FK 影響）
 * - applyDuplicateMerge   : 合併 duplicate row 到 primary，連動所有 FK
 * - previewDuplicateMerge : 上者的唯讀預演（`--dry-run` 用），不寫入、不加鎖、不記審計
 *
 * 服務範圍只有 base 層：呼叫端 `BankMismatchAuditor` 的偵測一律 `where('agent_group_id', 0)`，
 * 所以這裡的 primary 與 duplicate 都是 base(group 0) 模板列。
 *
 * FK 連動表：
 * - withdrawal_orders.bank_id
 * - member_bank_accounts.bank_id
 * - cash_flow_banks.bank_id（＋依分類決定是否同步 bank_code，見下）
 * - realname_validation_requests.bank_id
 * - agent_bank_accounts.bank_id
 * - agent_real_name_validation_requests.bank_id
 * - agent_cash_flows.info.available_banks[].BankCode_ELG（JSON 內 code 替換，僅代付 type=3）
 *
 * ⚠️ **`order_images` 刻意不在清單內（2026-08-21 移除）**：`bank_id` 這個欄位名在本庫是
 * 重載的——`order_images.bank_id` 指的是 **`bank_accounts.id`（自動入款收款帳戶）**，不是
 * `banks.id`。VWorld 實證：235 個 distinct 值 100% 命中 `bank_accounts.id`，
 * `MAX(order_images.bank_id)`=273 與 `MAX(bank_accounts.id)`=273 精確吻合，而
 * `MAX(banks.id)`=1533。兩者 id 區間重疊 ⇒ 誤改不會報錯，只會靜默把自動入款訂單圖片
 * 指到錯的收款帳戶。
 * 同一缺陷 `bank:rename-bank` 曾發生並已修正（Codex 審查抓到）。
 * 📌 納入任何表之前一律「distinct id 對照候選表」實測佐證，**不可信欄位名或 migration 註解**
 * （例：`member_bank_accounts.bank_id` 註解誤寫 `order_types.id`，實測是 `banks.id`）。
 *
 * ## cash_flow_banks.bank_code 逐列分類（2026-08-24 修正）
 *
 * 不變式（`bank:check-cash-flow-banks-integrity` 的定義）：`cfb.bank_code` 應等於其 `bank_id`
 * 所指 bank 的 `code`。改指後若放著 `bank_code` 不動，就會把原本同步的列變成不同步——而代付
 * 送單以 `(cash_flow_id, currency_id, bank_code)` 查 `provider_bank_code`
 * （`WithdrawalPayoutTrait::lookupProviderBankCodeFromTable`，TakaPay／VaderPay 無條件直查），
 * 新提款單帶的是改指後 bank 的 `code`（`Frontend/WithdrawalController` 建單時 `bank_code => $bank->code`）
 * ⇒ 兩邊對不上就查空、回「Provider bank code not configured」而擋單。
 *
 * 所以逐列分類，不是一律同步、也不是一律不動：
 * - **原本同步**（`cfb.bank_code === dup.code`）⇒ 一併同步成 `primary.code`，維持不變式。
 * - **原本就不同步**（帶白標 agent 層碼或三方碼）⇒ **保留不動並回報**。那列本來就是既有 drift，
 *   改成 `primary.code` 會直接改變它的查表結果。VWorld 實測 651 筆指向 group 0 的 cfb 裡，
 *   560 筆同步、91 筆（69 active）不同步（例 `cfb 1` bank_code=`VND0001` 而 base code=`VND0048`；
 *   `cfb 176` bank_code=`WTMY02` 而 base code=`MayBank`）。
 *
 * 分類一律在 **PHP 端逐列**判、不用 SQL `WHERE bank_code = ?`（2026-08-24 Codex 複驗）：
 * - 本庫預設 collation `utf8mb4_unicode_ci` 大小寫不敏感，SQL 比對會把 `abc` 當成 `ABC`
 *   而誤判為「原本同步」並改寫——但 `bank_code` 大小寫不同就是不同的碼。
 * - `dup.code === primary.code` 時沒有東西要同步，**但那些列仍可能有既有 drift**（duplicate
 *   是依 standard name 分組，兩列同碼不同名是合法輸入），早退就會靜默漏報。故同碼情形也逐列
 *   分類、把 drift 列記進審計。
 *
 * ## 交易邊界：逐 duplicate 各自一個交易
 *
 * 刻意不把整組包在同一交易裡。`cash_flow_banks` 的唯一鍵是 `(cash_flow_id, currency_id, bank_id)`，
 * 改指時撞鍵會拋例外；整組共用一個交易的話，一筆撞鍵就會回滾同組其他本來已成功的列。
 * 逐筆交易＋預檢＋捕捉唯一鍵例外三層，才真的做到「一筆撞鍵不拖垮同組其他列」。
 *
 * ## 審計
 *
 * 每個寫入動作都記 `bank_dedup_logs`，action 一律 `mismatch_` 前綴。
 *
 * `mismatch_repoint` 只記各項筆數，受影響的 row id 另以四個 action 分批寫（`detail` 是
 * varchar(255)，清單長度不可預期，塞在同一筆裡會撞 fail-closed 上限）：
 * - `mismatch_code_synced` / `mismatch_code_kept`：被同步 / 保留不動的 cfb id
 * - `mismatch_acf_changed` / `mismatch_acf_conflict`：被改寫 / 因已含 primary 碼而留置的 ACF id
 *
 * ⚠️ **這個命令沒有回滾工具**（`mismatch_` 前綴與 rollback 白名單天然隔離，反面就是沒有還原
 * 路徑），出錯只能靠審計人工回推——所以 row id 必須落審計，只記筆數等於回推不了。
 * ⚠️ `audit()` 超長時拋的 `RuntimeException` **不在 `applyDuplicateMerge` 的 catch 範圍內**，
 * 會中止整個命令而非只留置該筆；方向仍安全（該筆交易回滾、不留截斷 JSON）。
 *
 * `bank:dedup-rollback`
 * 消費的是 exact 白名單（`repoint`／`deactivate_binding`／`deactivate_bank`／`soft_delete_bank`／
 * `repoint_realname_request`），`mismatch_repoint` ≠ `repoint`，故不會被誤消費。
 *
 * ## 唯讀預演（`previewDuplicateMerge`）
 *
 * 四道判斷（自我合併／存在性／身分一致／cfb 撞鍵）與兩項分類（cfb bank_code、代付 ACF）
 * 一律與 apply 路徑**共用同一份方法**，只在寫入與加鎖處分岔——平行寫一份預檢邏輯必然漂移，
 * 而預演報「可合併」實際卻留置（或反之）比沒有預演更糟。
 *
 * 預演刻意**不加 `lockForUpdate`**：它跑在交易外，鎖取得即釋放、擋不住任何並發，卻會在唯讀
 * 操作上留下寫鎖足跡。代價是預演與實跑之間的並發變動不會被看見——實跑仍有鎖內重驗與唯一鍵
 * 例外捕捉兩層守著，預演的定位是「決定要不要跑」，不是「保證跑出來一樣」。
 *
 * ## 仍存在的範圍限制
 *
 * ⚠️ base 層（agent_group_id=0）的 duplicate 合併時，{@see updateAgentCashFlowAvailableBanks()}
 * 改寫的是**所有代理群組**的代付 ACF（只比 BankCode_ELG 字串，見 classifyAgentCashFlows()）；
 * 群組層合併才限該群組。dedup／rename-bank 家族各自實作搬遷、沒有接上本 Service。
 * （master 舊版此處記載的「只改 cfb.bank_id 不碰 bank_code、無 dry-run、無審計」已由上面三節補上。）
 */
class BankRepairService
{
    use PreservesRowTimestamps;

    /** bank_dedup_logs.detail 是 varchar(255)（部分白標未跑 text 的 alter），超長即 fail-closed */
    private const DETAIL_MAX_LENGTH = 255;

    /**
     * 一筆 id 清單審計最多帶幾個 id。
     *
     * 安全邊界：20 個 11 位數 id ＝ 20×11 ＋ 19 逗號 ＋ JSON 外殼 ≈ 249 < 255。實測
     * `MAX(cash_flow_banks.id)` VWorld 1154／TPower 2586（4 位數），一批實算約 109 字元。
     * 真的超界時 audit() 拋例外（fail-closed），代價是中止讓人看，不是留下截斷的 JSON。
     */
    private const AUDIT_ID_CHUNK = 20;

    /** apply 與 preview 共用同一段留置說明，避免兩邊講法不一致 */
    private const SELF_MERGE_REASON = 'duplicate id 與 primary 相同，自我合併會停用存活方，已拒絕';

    /**
     * 曾把某列當 loser 停用／軟刪的審計 action（本 service 的 `mismatch_deactivate_bank`、
     * `bank:dedup-active` 的 `deactivate_bank`／`soft_delete_bank`）：人工指定併入停用列
     * （`$absorbInactiveDuplicate`）遇到這些列一律拒絕，見 absorbBlockReason。
     *
     * @var list<string>
     */
    private const CONVERGED_LOSER_ACTIONS = ['mismatch_deactivate_bank', 'deactivate_bank', 'soft_delete_bank'];

    /** `--collision-keep=` 的兩個合法值：撞點要保留 primary 側還是 duplicate 側的 cash_flow_banks 列。 */
    public const COLLISION_KEEP_PRIMARY = 'primary';

    public const COLLISION_KEEP_DUPLICATE = 'duplicate';

    /**
     * ⚠️ 不含 order_images：其 bank_id 指 bank_accounts.id，非 banks.id（理由見類別 docblock）
     *
     * @var list<string>
     */
    private const FK_TABLES = [
        'withdrawal_orders',
        'member_bank_accounts',
        'cash_flow_banks',
        'realname_validation_requests',
        'agent_bank_accounts',
        'agent_real_name_validation_requests',
    ];

    /**
     * 重命名 banks.name
     *
     * 不影響任何 FK，純 UPDATE。
     */
    public function applyMismatchRename(int $bankId, string $newName): void
    {
        $bank = Bank::query()->find($bankId);

        if (!$bank) {
            return;
        }

        $oldName = (string) $bank->name;

        if ($oldName === $newName) {
            return;
        }

        Bank::query()->where('id', $bankId)->update(['name' => $newName]);

        // rename 沒有 survivor／loser 之分，兩欄同填該列自己
        $this->audit($bank, $bankId, 'mismatch_rename', ['was' => $oldName, 'aft' => $newName]);
    }

    /**
     * 合併 duplicate row 到 primary，連動 FK
     *
     * 逐 duplicate 各自一個交易（理由見類別 docblock）。每筆先核對身分、再預檢
     * `cash_flow_banks` 撞鍵，撞到的留置該筆並回報；並發下仍撞鍵則捕捉例外、只回滾該筆。
     *
     * @param  int[]  $duplicateIds
     * @return array{merged: list<int>, skipped: list<array{bank_id: int, reason: string}>, pruned_cfb: list<int>}
     */
    public function applyDuplicateMerge(int $primaryId, array $duplicateIds, bool $pruneInactiveCollisions = false, bool $coerceTypeToPrimary = false, ?string $collisionKeep = null, bool $absorbInactiveDuplicate = false): array
    {
        $merged = [];
        $skipped = [];
        $prunedCfb = [];

        foreach ($duplicateIds as $rawId) {
            $dupId = (int) $rawId;

            // primary 自我合併會一路走到最後把 primary 自己 status=0（該 bank 沒有 cfb 列時，
            // 連 lockAndDetectConflicts 的自我衝突都攔不到），停用的是存活方、審計還記成一次
            // 成功合併。現行唯一呼叫端已用 array_shift 排除 primary，但本方法是 public。
            if ($dupId === $primaryId) {
                $skipped[] = ['bank_id' => $dupId, 'reason' => self::SELF_MERGE_REASON];

                continue;
            }

            try {
                $outcome = DB::transaction(fn (): array => $this->mergeOne($primaryId, $dupId, $pruneInactiveCollisions, $coerceTypeToPrimary, $collisionKeep, $absorbInactiveDuplicate));
                $reason = $outcome['reason'];
                // 留置（reason 非 null）時 mergeOne 保證零寫入，pruned_cfb 必為空；只在合併成功時累加
                $prunedCfb = [...$prunedCfb, ...$outcome['pruned_cfb']];
            } catch (UniqueConstraintViolationException $e) {
                // 只有 cash_flow_banks 的撞鍵才是這裡預期的並發情形（其唯一鍵
                // (cash_flow_id, currency_id, bank_id) 正是改指要碰的）。其他表的唯一鍵違反是
                // 未知缺陷，一律誤報成「並發」等於把它藏起來 ⇒ 原樣拋出讓人查。
                if (!str_contains($e->getSql(), 'cash_flow_banks')) {
                    throw $e;
                }

                // 預檢通過但寫入時仍撞鍵＝預檢與寫入之間有並發寫入。本筆已回滾、其餘不受影響。
                $reason = 'cash_flow_banks 改指時撞唯一鍵（預檢後有並發寫入），本筆已回滾未變更';
                $this->auditSkip($primaryId, $dupId, ['race' => 1]);
            }

            if ($reason === null) {
                $merged[] = $dupId;

                continue;
            }

            $skipped[] = ['bank_id' => $dupId, 'reason' => $reason];
        }

        return ['merged' => $merged, 'skipped' => $skipped, 'pruned_cfb' => $prunedCfb];
    }

    /**
     * `applyDuplicateMerge` 的唯讀預演：每筆 duplicate 會被合併還是留置、合併的話會動到什麼。
     *
     * 判斷順序與 apply 完全一致且共用同一批方法（見類別 docblock「唯讀預演」），差別只有
     * 不寫入、不加鎖、不記審計。
     *
     * `verdict='merge'` 的列附上實際受影響的 row id 而不只是筆數——首跑前要能逐筆核對的正是
     * 這些 id（尤其 `acf_conflicts`：那些 ACF 會保留舊碼不動，是需要人工接手的部分）。
     *
     * `$absorbInactiveDuplicate`（`--absorb-inactive`）＝人工點名的 duplicate 是停用列：身分判準
     * 改要求 duplicate `status=0`（其餘欄照常）、另擋曾作為 loser 被收斂過的列，見 identityMismatch
     * 與 absorbBlockReason；合併列以 `inactive_absorbed=true` 標示。
     *
     * @param  int[]  $duplicateIds
     * @return list<array{
     *     bank_id: int,
     *     verdict: 'merge'|'skip',
     *     reason: string|null,
     *     type_coerced: string|null,
     *     inactive_absorbed: bool,
     *     fk: array<string, int>,
     *     cfb_pruned: list<int>,
     *     cfb_code_synced: list<int>,
     *     cfb_code_kept: list<int>,
     *     acf_changed: list<int>,
     *     acf_conflicts: list<int>,
     * }>
     */
    public function previewDuplicateMerge(int $primaryId, array $duplicateIds, bool $pruneInactiveCollisions = false, bool $coerceTypeToPrimary = false, ?string $collisionKeep = null, bool $absorbInactiveDuplicate = false): array
    {
        $primary = Bank::query()->find($primaryId);
        $preview = [];

        foreach ($duplicateIds as $rawId) {
            $dupId = (int) $rawId;

            if (!$primary) {
                $preview[] = $this->previewSkip($dupId, sprintf('primary id=%d 不存在', $primaryId));

                continue;
            }

            if ($dupId === $primaryId) {
                $preview[] = $this->previewSkip($dupId, self::SELF_MERGE_REASON);

                continue;
            }

            $dup = Bank::query()->find($dupId);

            if (!$dup) {
                $preview[] = $this->previewSkip($dupId, sprintf('duplicate id=%d 不存在', $dupId));

                continue;
            }

            if ($identityMismatch = $this->identityMismatch($dup, $primary, $coerceTypeToPrimary, $absorbInactiveDuplicate)) {
                $preview[] = $this->previewSkip($dupId, $this->identityReason($identityMismatch));

                continue;
            }

            if ($absorbInactiveDuplicate && ($blocked = $this->absorbBlockReason($dup)) !== null) {
                $preview[] = $this->previewSkip($dupId, $blocked);

                continue;
            }

            $typeCoerced = $coerceTypeToPrimary && (int) $dup->type !== (int) $primary->type
                ? sprintf('%d→%d', (int) $dup->type, (int) $primary->type)
                : null;

            $conflicts = $this->detectCashFlowBankConflicts($dupId, $primary, lock: false);
            $cfbPruned = [];

            if ($conflicts !== [] && $pruneInactiveCollisions) {
                $classified = $this->classifyPruneCandidates($conflicts, lock: false, collisionKeep: $collisionKeep);

                if ($classified['blocked'] !== []) {
                    $preview[] = $this->previewSkip($dupId, $this->describePruneBlocked($classified['blocked']));

                    continue;
                }

                $cfbPruned = array_column($classified['prunable'], 'cfb_id');
            } elseif ($conflicts !== []) {
                $preview[] = $this->previewSkip($dupId, $this->describeConflicts($conflicts));

                continue;
            }

            $codes = $this->classifyCashFlowBankCodes($dup, $primary);
            $acf = $this->classifyAgentCashFlows($dup, $primary);

            // 實跑先刪再分類，dup 側被 prune 的列不會出現在碼表分類；預演時列還在，需濾掉才與實跑一致
            $preview[] = [
                'bank_id' => $dupId,
                'verdict' => 'merge',
                'reason' => null,
                'type_coerced' => $typeCoerced,
                'inactive_absorbed' => $absorbInactiveDuplicate,
                'fk' => $this->countForeignKeys($dupId),
                'cfb_pruned' => $cfbPruned,
                'cfb_code_synced' => array_values(array_diff($codes['synced'], $cfbPruned)),
                'cfb_code_kept' => array_values(array_diff($codes['kept'], $cfbPruned)),
                'acf_changed' => $acf['targets'],
                'acf_conflicts' => $acf['conflicts'],
            ];
        }

        return $preview;
    }

    /**
     * @return array{
     *     bank_id: int,
     *     verdict: 'skip',
     *     reason: string,
     *     type_coerced: null,
     *     inactive_absorbed: false,
     *     fk: array<string, int>,
     *     cfb_pruned: list<int>,
     *     cfb_code_synced: list<int>,
     *     cfb_code_kept: list<int>,
     *     acf_changed: list<int>,
     *     acf_conflicts: list<int>,
     * }
     */
    private function previewSkip(int $dupId, string $reason): array
    {
        return [
            'bank_id' => $dupId,
            'verdict' => 'skip',
            'reason' => $reason,
            'type_coerced' => null,
            'inactive_absorbed' => false,
            'fk' => [],
            'cfb_pruned' => [],
            'cfb_code_synced' => [],
            'cfb_code_kept' => [],
            'acf_changed' => [],
            'acf_conflicts' => [],
        ];
    }

    /**
     * 身分不符的留置說明。apply 與 preview 共用。
     */
    private function identityReason(string $identityMismatch): string
    {
        return sprintf('身分不一致（%s），不合併——不同實體不該被併成一列', $identityMismatch);
    }

    /**
     * 人工指定併入停用列（`$absorbInactiveDuplicate`）專用的額外閘：該停用列若曾作為 loser 被
     * 任一條收斂路徑處理過（CONVERGED_LOSER_ACTIONS），其綁定與引用早已移轉到當時的存活方，
     * 再併一次會把「零筆改指」記成一次成功合併、誤導事後回推 ⇒ fail-closed。apply 與 preview 共用。
     *
     * @return string|null null＝可併入
     */
    private function absorbBlockReason(Bank $dup): ?string
    {
        $action = DB::table('bank_dedup_logs')
            ->where('loser_bank_id', (int) $dup->id)
            ->whereIn('action', self::CONVERGED_LOSER_ACTIONS)
            ->orderByDesc('id')
            ->value('action');

        if ($action === null) {
            return null;
        }

        return sprintf('停用列曾作為 loser 被收斂（審計 action=%s），綁定已移轉過，不重複併入', (string) $action);
    }

    /**
     * 預演用：duplicate 各 FK 表現有幾筆會被改指（只回報非零的表）。
     *
     * @return array<string, int>
     */
    private function countForeignKeys(int $dupBankId): array
    {
        $counts = [];

        foreach (self::FK_TABLES as $table) {
            $count = DB::table($table)->where('bank_id', $dupBankId)->count();

            if ($count > 0) {
                $counts[$table] = $count;
            }
        }

        return $counts;
    }

    /**
     * 合併單一 duplicate。
     *
     * @return array{reason: string|null, pruned_cfb: list<int>} reason null＝已合併；字串＝留置原因
     */
    private function mergeOne(int $primaryId, int $dupId, bool $pruneInactiveCollisions, bool $coerceTypeToPrimary = false, ?string $collisionKeep = null, bool $absorbInactiveDuplicate = false): array
    {
        $primary = Bank::query()->lockForUpdate()->findOrFail($primaryId);
        $dup = Bank::query()->lockForUpdate()->find($dupId);

        if (!$dup) {
            return ['reason' => sprintf('duplicate id=%d 不存在', $dupId), 'pruned_cfb' => []];
        }

        if ($identityMismatch = $this->identityMismatch($dup, $primary, $coerceTypeToPrimary, $absorbInactiveDuplicate)) {
            $this->auditSkip($primaryId, $dupId, ['identity' => $identityMismatch]);

            return ['reason' => $this->identityReason($identityMismatch), 'pruned_cfb' => []];
        }

        if ($absorbInactiveDuplicate) {
            if (($blocked = $this->absorbBlockReason($dup)) !== null) {
                $this->auditSkip($primaryId, $dupId, ['absorb' => 'converged_loser']);

                return ['reason' => $blocked, 'pruned_cfb' => []];
            }

            // 停用列不在 auditor 視野內、是人工點名併入的：獨立審計一筆，事後查得出哪些合併不是自動分組來的
            $this->audit($primary, $dupId, 'mismatch_inactive_merged', ['dup_status' => (int) $dup->status]);
        }

        if ($coerceTypeToPrimary && (int) $dup->type !== (int) $primary->type) {
            // 綁定 repoint 後走 primary 的 type（出款流程分類跟著換），dup 停用留原 type——
            // 這是明示旗標授權的方向覆蓋，審計獨立記一筆供回查
            $this->audit($primary, $dupId, 'mismatch_type_coerced', [
                'dup_type' => (int) $dup->type,
                'primary_type' => (int) $primary->type,
            ]);
        }

        // 鎖住兩側的 cfb 後才判撞鍵，縮小預檢與寫入之間的空窗
        $conflicts = $this->lockAndDetectConflicts($dupId, $primary);
        $prunedCfb = [];

        if ($conflicts !== [] && $pruneInactiveCollisions) {
            $classified = $this->classifyPruneCandidates($conflicts, lock: true, collisionKeep: $collisionKeep);

            if ($classified['blocked'] !== []) {
                // all-or-nothing：任一撞點不合格，整組零寫入留置（半套風險見 classifyPruneCandidates）
                $this->auditSkip($primaryId, $dupId, [
                    'conflict' => count($conflicts),
                    'prune_blocked' => count($classified['blocked']),
                ]);

                return ['reason' => $this->describePruneBlocked($classified['blocked']), 'pruned_cfb' => []];
            }

            foreach ($classified['prunable'] as $candidate) {
                // 先審計再刪：detail 記 row id 與身分，完整 before 值在備份表（--apply 強制 --backup）
                $detail = [
                    'cfb' => $candidate['cfb_id'],
                    'side' => $candidate['side'],
                    'slot' => $candidate['slot'],
                    'provider' => $candidate['provider_code'],
                ];

                if ($collisionKeep !== null) {
                    // 人工裁決放行的撞點獨立標記，事後查得出哪些不是自動 gate 放行的
                    $detail['keep'] = $collisionKeep;
                }

                $this->audit($primary, $dupId, 'mismatch_cfb_pruned', $detail);
                DB::table('cash_flow_banks')->where('id', $candidate['cfb_id'])->delete();
                $prunedCfb[] = $candidate['cfb_id'];
            }
        } elseif ($conflicts !== []) {
            $this->auditSkip($primaryId, $dupId, ['conflict' => count($conflicts)]);

            return ['reason' => $this->describeConflicts($conflicts), 'pruned_cfb' => []];
        }

        $codes = $this->syncCashFlowBankCodes($dup, $primary);
        $moved = $this->reassignForeignKeys($dupId, $primary);
        $acf = $this->updateAgentCashFlowAvailableBanks($dup, $primary);

        $this->audit($primary, $dupId, 'mismatch_repoint', [
            'fk' => $moved,
            'acf' => count($acf['changed']),
            'acf_conflict' => count($acf['conflicts']),
            'code_synced' => count($codes['synced']),
            'code_kept' => count($codes['kept']),
        ]);

        // 只記筆數的話事後無法精確回推：改指後 duplicate 原本的列與 primary 自己原有的列
        // 混在同一個 bank_id 下、分不出來；被同步的 cfb 列更是 code 與 bank_id 雙改，
        // 與 primary 原生的同步列完全不可分。所以受影響的 row id 一律落審計。
        $this->auditIdList($primary, $dupId, 'mismatch_code_kept', 'cfb', $codes['kept']);
        $this->auditIdList($primary, $dupId, 'mismatch_code_synced', 'cfb', $codes['synced']);
        $this->auditIdList($primary, $dupId, 'mismatch_acf_changed', 'acf', $acf['changed']);
        $this->auditIdList($primary, $dupId, 'mismatch_acf_conflict', 'acf', $acf['conflicts']);

        // 人工指定併入的停用列本來就是 status=0：不再改寫，也不重複記一筆停用審計
        if ((int) $dup->status === 1) {
            Bank::query()->where('id', $dupId)->update(['status' => 0]);
            $this->audit($primary, $dupId, 'mismatch_deactivate_bank', []);
        }

        return ['reason' => null, 'pruned_cfb' => $prunedCfb];
    }

    /**
     * prune 被擋下的留置說明（all-or-nothing：一點被擋整組留置）。
     *
     * @param  list<array{slot: string, reason: string}>  $blocked
     */
    private function describePruneBlocked(array $blocked): string
    {
        $parts = array_map(
            static fn (array $b): string => sprintf('%s：%s', $b['slot'], $b['reason']),
            $blocked,
        );

        return 'prune 判準未全數通過、整組留置（all-or-nothing）：' . implode('；', $parts);
    }

    /**
     * duplicate 與 primary 是否真的是「同一家銀行的兩列」，且兩者現在都還活著。
     *
     * 呼叫端 `BankMismatchAuditor` 只按 currency／group／status 撈列並依 standard 分組，
     * **沒有核對 `type`**（1 銀行卡／3 加密貨幣／4 其他錢包等語意不同）。身分不一致就不是重複、
     * 而是不同實體，合併會把兩種東西併成一列，故在此 fail-closed。
     *
     * 另外三道是鎖內重驗（2026-08-24 Codex 複驗）：
     * - `status`：auditor 撈列時是 `status=1`，撈完到鎖住之間可能已被停用。
     * - `deleted_at`：auditor **完全沒有過濾軟刪除**，而 `Bank` model 沒有 `SoftDeletes`
     *   ⇒ 沒有全域 scope 幫忙。VWorld 實測存在 `deleted_at` 有值卻 `status=1` 的矛盾列
     *   （`banks 1453`），這種列不該被選為承接方、也不該被當成待併的重複列。
     * - `standard_bank_id`：分組只依 config 的名稱解析，兩側都已回填且不同 standard 時，
     *   那是名稱撞了而不是同一家銀行（任一為 null 代表未回填、無從判定，跳過不當作不符）。
     *
     * `$coerceTypeToPrimary` 只鬆綁 `type` 一道（合併後綁定走 primary 的 type，dup 停用留原值）：
     * 同名同幣同 group 但 type 標記矛盾的誤建批（VWorld g99 SGD 實例：主體 54 列 type 4、
     * 兩天冒出的 9 列 type 1）需要明示旗標才放行，其餘身分欄照常 fail-closed。
     *
     * `$absorbInactiveDuplicate`（2026-09-07，`--absorb-inactive`）把 duplicate 的 `status` 要求
     * 從「必須活著」翻成「必須是停用列」：停用列上還掛著活綁定（VWorld g70 `267` Maybank 5 筆）、
     * 承接列名稱不同又沒回填 standard 時，auditor 永遠配不成一組，只能人工點名；primary 仍須
     * 活著、兩側仍不得軟刪、type／currency／group／standard 四道照常。
     *
     * @return string|null null＝身分一致
     */
    private function identityMismatch(Bank $dup, Bank $primary, bool $coerceTypeToPrimary = false, bool $absorbInactiveDuplicate = false): ?string
    {
        foreach (['primary' => $primary, 'duplicate' => $dup] as $label => $bank) {
            $expectedStatus = $label === 'duplicate' && $absorbInactiveDuplicate ? 0 : 1;

            if ((int) $bank->status !== $expectedStatus) {
                return $expectedStatus === 0
                    ? sprintf('duplicate 未停用（status=%d）——人工指定併入只收停用列，服務中的列走一般稽核路徑', (int) $bank->status)
                    : sprintf('%s 已停用（status=%d）', $label, (int) $bank->status);
            }

            if ($bank->deleted_at !== null) {
                return sprintf('%s 已軟刪除（deleted_at=%s）', $label, (string) $bank->deleted_at);
            }
        }

        if (!$coerceTypeToPrimary && (int) $dup->type !== (int) $primary->type) {
            return sprintf('type %d vs %d', (int) $dup->type, (int) $primary->type);
        }

        if ((int) $dup->currency_id !== (int) $primary->currency_id) {
            return sprintf('currency_id %d vs %d', (int) $dup->currency_id, (int) $primary->currency_id);
        }

        if ((int) $dup->agent_group_id !== (int) $primary->agent_group_id) {
            return sprintf('agent_group_id %d vs %d', (int) $dup->agent_group_id, (int) $primary->agent_group_id);
        }

        $dupStandard = $dup->standard_bank_id;
        $primaryStandard = $primary->standard_bank_id;

        if ($dupStandard !== null && $primaryStandard !== null && (int) $dupStandard !== (int) $primaryStandard) {
            return sprintf('standard_bank_id %d vs %d', (int) $dupStandard, (int) $primaryStandard);
        }

        return null;
    }

    /**
     * 鎖住 duplicate 與 primary 兩側的 cash_flow_banks，然後判斷改指會不會撞唯一鍵。
     *
     * 唯一鍵是 `(cash_flow_id, currency_id, bank_id)`，所以只要 primary 在同一個
     * `(cash_flow_id, currency_id)` 已經有列，改指就會撞鍵。VWorld 實測 8 組 duplicate
     * 有 5 組會撞（例 primary 989 ← dup 1098 撞兩處：cf76/cur2 與 cf110/cur2，兩邊都 active）。
     *
     * 鎖只能縮小空窗、不能完全消除（`SELECT … FOR UPDATE` 擋不掉全新 INSERT 的所有情形），
     * 故呼叫端另有唯一鍵例外的捕捉作為第二層。
     *
     * `protected` 是刻意的測試接縫：第二層（真的撞鍵 → 回滾該筆 → 同組其他列照常提交）在
     * 單執行緒測試裡沒有別的辦法觸發，測試以子類覆寫本方法回傳空陣列來模擬「預檢當下沒看到」。
     *
     * @return list<array{dup_cfb_id: int, primary_cfb_id: int, cash_flow_id: int, currency_id: int,
     *     dup_cfb_status: int, primary_cfb_status: int,
     *     dup_cfb_provider_code: string|null, primary_cfb_provider_code: string|null}>
     */
    protected function lockAndDetectConflicts(int $dupBankId, Bank $primary): array
    {
        return $this->detectCashFlowBankConflicts($dupBankId, $primary, lock: true);
    }

    /**
     * 撞鍵偵測本體。`$lock=false` 供 `previewDuplicateMerge` 使用——預演跑在交易外，加鎖只會
     * 立即釋放、擋不住任何並發，卻在唯讀操作上留下寫鎖足跡（理由見類別 docblock）。
     *
     * 兩側的 `status`／`provider_bank_code` 隨撞鍵列一起取——prune 判準（見
     * `classifyPruneCandidates`）必須用**同一次鎖內查詢**的值，鎖後另查等於把判定
     * 建立在鎖外快照上（2026-08-25 Codex 複查點名的缺口）。
     *
     * @return list<array{dup_cfb_id: int, primary_cfb_id: int, cash_flow_id: int, currency_id: int,
     *     dup_cfb_status: int, primary_cfb_status: int,
     *     dup_cfb_provider_code: string|null, primary_cfb_provider_code: string|null}>
     */
    private function detectCashFlowBankConflicts(int $dupBankId, Bank $primary, bool $lock): array
    {
        $query = DB::table('cash_flow_banks')
            ->whereIn('bank_id', [$dupBankId, $primary->id]);

        if ($lock) {
            $query->lockForUpdate();
        }

        $rows = $query->get(['id', 'cash_flow_id', 'currency_id', 'bank_id', 'bank_code', 'status', 'provider_bank_code']);

        $primaryRows = [];

        foreach ($rows as $row) {
            if ((int) $row->bank_id === (int) $primary->id) {
                $primaryRows[$this->slotKey($row)] = $row;
            }
        }

        $conflicts = [];

        foreach ($rows as $row) {
            if ((int) $row->bank_id !== $dupBankId) {
                continue;
            }

            $slot = $this->slotKey($row);

            if (!isset($primaryRows[$slot])) {
                continue;
            }

            $primaryRow = $primaryRows[$slot];

            $conflicts[] = [
                'dup_cfb_id' => (int) $row->id,
                'primary_cfb_id' => (int) $primaryRow->id,
                'cash_flow_id' => (int) $row->cash_flow_id,
                'currency_id' => (int) $row->currency_id,
                'dup_cfb_status' => (int) $row->status,
                'primary_cfb_status' => (int) $primaryRow->status,
                'dup_cfb_provider_code' => $row->provider_bank_code === null ? null : (string) $row->provider_bank_code,
                'primary_cfb_provider_code' => $primaryRow->provider_bank_code === null ? null : (string) $primaryRow->provider_bank_code,
            ];
        }

        return $conflicts;
    }

    /**
     * prune 判準（`--prune-inactive-collisions`）：逐撞點分類「可刪停用列放行合併」或「擋下」。
     *
     * 四道 gate（2026-08-25 Codex 複查定案；第 4 道 2026-08-26 RB 拍板擴充）：
     * 1. **恰一側 `status=0`、另一側 `status=1`** ⇒ 走停用列刪除判準（gate 2）——status 取自
     *    `detectCashFlowBankConflicts` 同一次鎖內查詢；兩側皆停無從判定承接方，擋下。
     * 2. **停用側的 `provider_bank_code` 必須有其他活列承接**（同 cash_flow＋currency 同碼、
     *    `status=1`）——「status=0」單獨不能證明配置已移除（sync 有 matching collision 情境：
     *    仍在配置的碼被覆蓋後一列 status=1 都沒有）；有活列承接＝該碼服務不中斷、停用列是
     *    純孤立記錄。查無承接（含拼法變更、真下架）一律擋下請人工確認。空碼視同無服務可斷、放行。
     * 3. **all-or-nothing 由呼叫端執行**：同一 duplicate 任一撞點被擋，整組零寫入留置——
     *    逐撞點先刪後擋會留下「沒合併但列已被刪」的半套（留置是正常 return、交易會 commit）。
     * 4. **兩側皆 `status=1` 且 `provider_bank_code` 嚴格相同** ⇒ 刪 **duplicate 側**——送三方
     *    的碼一模一樣，挑碼問題不存在；保留 primary 側是因合併後只剩 primary 的 `banks.code`，
     *    其列的 `bank_code` 天然一致（兩站實測 6 撞點同碼、限額全 0 無資訊損失）。比對在 PHP 端
     *    嚴格 `===`（不用 SQL `=`，避免 collation 把大小寫不同的碼誤判相同）；兩側碼不同（含
     *    一側空值）才是真要人工挑碼，擋下。
     * 5. **`$collisionKeep`（`--collision-keep=`，2026-08-27 RB 拍板）＝人工已挑碼**，覆寫 gate 2
     *    的承接查詢與 gate 4 的同碼比對，直接刪「非指定側」那列。用於 gate 2／4 判不出來、但人工
     *    有外部依據可判的撞點（例：三方碼表證實某一側的碼不在該幣別的有效清單內）。**仍 fail-closed
     *    的一種情形＝指定保留的那側 `status=0`**：那等於刪掉唯一活列、換上停用列，服務會斷，
     *    人工挑碼的授權不涵蓋這件事，擋下請先確認配置狀態。呼叫端另強制搭 `--bank=` 限單一組
     *    （一次執行的各組挑碼方向不會相同）。
     *
     * @param  list<array{dup_cfb_id: int, primary_cfb_id: int, cash_flow_id: int, currency_id: int,
     *     dup_cfb_status: int, primary_cfb_status: int,
     *     dup_cfb_provider_code: string|null, primary_cfb_provider_code: string|null}>  $conflicts
     * @return array{
     *     prunable: list<array{cfb_id: int, side: string, slot: string, provider_code: string|null}>,
     *     blocked: list<array{slot: string, reason: string}>,
     * }
     */
    private function classifyPruneCandidates(array $conflicts, bool $lock, ?string $collisionKeep = null): array
    {
        $prunable = [];
        $blocked = [];

        foreach ($conflicts as $conflict) {
            $slot = sprintf('cf%d/cur%d', $conflict['cash_flow_id'], $conflict['currency_id']);
            $dupActive = $conflict['dup_cfb_status'] === 1;
            $primaryActive = $conflict['primary_cfb_status'] === 1;

            // gate 5：人工已挑碼，覆寫 gate 2／4 的自動判準（理由見 docblock）
            if ($collisionKeep !== null) {
                $verdict = $this->manualKeepVerdict($conflict, $slot, $collisionKeep, $dupActive, $primaryActive);

                if (isset($verdict['blocked'])) {
                    $blocked[] = $verdict['blocked'];
                } else {
                    $prunable[] = $verdict['prunable'];
                }

                continue;
            }

            if ($dupActive && $primaryActive) {
                $dupCode = $conflict['dup_cfb_provider_code'];
                $primaryCode = $conflict['primary_cfb_provider_code'];

                // gate 4：兩側送三方的碼嚴格相同 ⇒ 挑碼問題不存在，刪 dup 側（理由見 docblock）
                if ($dupCode === $primaryCode) {
                    $prunable[] = [
                        'cfb_id' => $conflict['dup_cfb_id'],
                        'side' => 'duplicate',
                        'slot' => $slot,
                        'provider_code' => $dupCode,
                    ];

                    continue;
                }

                $blocked[] = [
                    'slot' => $slot,
                    'reason' => sprintf(
                        '兩側皆 status=1 且 provider 碼不同（primary "%s" vs duplicate "%s"），須人工挑碼',
                        $primaryCode ?? '(null)',
                        $dupCode ?? '(null)',
                    ),
                ];

                continue;
            }

            if (!$dupActive && !$primaryActive) {
                $blocked[] = [
                    'slot' => $slot,
                    'reason' => '兩側皆 status=0，無從判定承接方',
                ];

                continue;
            }

            $side = $dupActive ? 'primary' : 'duplicate';
            $cfbId = $dupActive ? $conflict['primary_cfb_id'] : $conflict['dup_cfb_id'];
            $providerCode = $dupActive ? $conflict['primary_cfb_provider_code'] : $conflict['dup_cfb_provider_code'];

            if ($providerCode !== null && $providerCode !== '') {
                $successor = DB::table('cash_flow_banks')
                    ->where('cash_flow_id', $conflict['cash_flow_id'])
                    ->where('currency_id', $conflict['currency_id'])
                    ->where('provider_bank_code', $providerCode)
                    ->where('status', 1)
                    ->where('id', '!=', $cfbId);

                if ($lock) {
                    $successor->lockForUpdate();
                }

                if (!$successor->exists()) {
                    $blocked[] = [
                        'slot' => $slot,
                        'reason' => sprintf(
                            '停用側 cfb %d 的 provider 碼 "%s" 查無活列承接——可能是真下架，'
                            . '也可能是配置仍在、只是被 matching collision 覆蓋或拼法變更，需人工確認',
                            $cfbId,
                            $providerCode,
                        ),
                    ];

                    continue;
                }
            }

            $prunable[] = [
                'cfb_id' => $cfbId,
                'side' => $side,
                'slot' => $slot,
                'provider_code' => $providerCode,
            ];
        }

        return ['prunable' => $prunable, 'blocked' => $blocked];
    }

    /**
     * gate 5 單一撞點的判定：人工指名保留哪一側，就刪另一側。
     *
     * 唯一擋下的情形＝指名保留的那側 `status=0`（理由見 classifyPruneCandidates docblock gate 5）。
     *
     * @param  array{dup_cfb_id: int, primary_cfb_id: int, cash_flow_id: int, currency_id: int,
     *     dup_cfb_status: int, primary_cfb_status: int,
     *     dup_cfb_provider_code: string|null, primary_cfb_provider_code: string|null}  $conflict
     * @return array{
     *     prunable?: array{cfb_id: int, side: string, slot: string, provider_code: string|null},
     *     blocked?: array{slot: string, reason: string},
     * }
     */
    private function manualKeepVerdict(
        array $conflict,
        string $slot,
        string $collisionKeep,
        bool $dupActive,
        bool $primaryActive,
    ): array {
        $keepPrimary = $collisionKeep === self::COLLISION_KEEP_PRIMARY;

        if (!($keepPrimary ? $primaryActive : $dupActive)) {
            return ['blocked' => [
                'slot' => $slot,
                'reason' => sprintf(
                    '--collision-keep=%s 指定保留的那側 cfb status=0，放行等於刪掉唯一活列，先確認配置狀態',
                    $collisionKeep,
                ),
            ]];
        }

        return ['prunable' => [
            'cfb_id' => $keepPrimary ? $conflict['dup_cfb_id'] : $conflict['primary_cfb_id'],
            'side' => $keepPrimary ? 'duplicate' : 'primary',
            'slot' => $slot,
            'provider_code' => $keepPrimary
                ? $conflict['dup_cfb_provider_code']
                : $conflict['primary_cfb_provider_code'],
        ]];
    }

    /**
     * 唯一鍵裡除 bank_id 以外的兩欄組成的 slot 鍵。
     */
    private function slotKey(object $row): string
    {
        return $row->cash_flow_id . ':' . $row->currency_id;
    }

    /**
     * 依分類同步 duplicate 那些 cash_flow_banks 的 bank_code（分類理由見類別 docblock）。
     *
     * 這一步必須在 reassignForeignKeys() 之前跑——改指之後就分不出哪些列原本是同步的。
     *
     * 分類在 PHP 端逐列做：SQL 的 `WHERE bank_code = ?` 走 `utf8mb4_unicode_ci`，
     * 大小寫不同的碼會被當成相同而誤改；同碼（`dup.code === primary.code`）時也照樣逐列走，
     * 沒有東西要同步但既有 drift 仍要回報。
     *
     * @return array{synced: list<int>, kept: list<int>} synced＝本次實際改寫的列（同碼時恆為空）
     */
    private function syncCashFlowBankCodes(Bank $dup, Bank $primary): array
    {
        $classified = $this->classifyCashFlowBankCodes($dup, $primary);

        if ($classified['synced'] !== []) {
            DB::table('cash_flow_banks')
                ->whereIn('id', $classified['synced'])
                ->update($this->preservingUpdatedAt(['bank_code' => (string) $primary->code]));
        }

        return $classified;
    }

    /**
     * 上者的純讀分類（`--dry-run` 與實跑共用）。
     *
     * @return array{synced: list<int>, kept: list<int>} synced＝會被／已被改寫的列（同碼時恆為空）
     */
    private function classifyCashFlowBankCodes(Bank $dup, Bank $primary): array
    {
        $dupCode = (string) $dup->code;
        $primaryCode = (string) $primary->code;

        $rows = DB::table('cash_flow_banks')
            ->where('bank_id', $dup->id)
            ->get(['id', 'bank_code']);

        $syncCandidateIds = [];
        $kept = [];

        foreach ($rows as $row) {
            if ((string) $row->bank_code === $dupCode) {
                $syncCandidateIds[] = (int) $row->id;

                continue;
            }

            $kept[] = (int) $row->id;
        }

        // 兩碼相同時「同步」是空操作，但 drift 列已在上面收進 $kept，不會被漏報
        if ($dupCode === $primaryCode) {
            return ['synced' => [], 'kept' => $kept];
        }

        return ['synced' => $syncCandidateIds, 'kept' => $kept];
    }

    /**
     * 把撞鍵明細壓成一行人看得懂的說明。
     *
     * @param  list<array{dup_cfb_id: int, primary_cfb_id: int, cash_flow_id: int, currency_id: int}>  $conflicts
     */
    private function describeConflicts(array $conflicts): string
    {
        $parts = array_map(
            static fn (array $c): string => sprintf(
                'cf%d/cur%d 已有 cfb %d（本列 cfb %d）',
                $c['cash_flow_id'],
                $c['currency_id'],
                $c['primary_cfb_id'],
                $c['dup_cfb_id'],
            ),
            $conflicts,
        );

        return 'cash_flow_banks 改指會撞唯一鍵：' . implode('；', $parts);
    }

    /**
     * 將 duplicate row 的所有 FK 重指到 primary
     *
     * ⚠️ **`updated_at` 一律自我賦值（`DB::raw('`updated_at`')`）不可移除**：這些表的
     * `updated_at` 帶 `on update CURRENT_TIMESTAMP`，只寫 `bank_id` 會被 MySQL 連帶蓋成
     * 執行時間。改指是「同一筆訂單換綁到另一個 banks row」的技術搬移，不是業務事件，
     * 蓋掉時間戳會污染所有以 `updated_at` 判讀時序的下游——`withdrawal_orders.updated_at`
     * 正是前端訂單排序欄，2026-08-27 因此造成生產訂單順序混亂（550 筆，六站）。
     * 自我賦值的字串是常數、無外部輸入，不屬「未綁定 `DB::raw`」禁令範圍。
     * 已被污染的列以 `bank:restore-fk-timestamps` 從備份表逐欄還原。
     *
     * @return array<string, int> 表名 => 實際更新筆數（只回報有動到的表）
     */
    private function reassignForeignKeys(int $dupBankId, Bank $primary): array
    {
        $moved = [];

        foreach (self::FK_TABLES as $table) {
            $count = DB::table($table)
                ->where('bank_id', $dupBankId)
                ->update($this->preservingUpdatedAt(['bank_id' => $primary->id]));

            if ($count > 0) {
                $moved[$table] = $count;
            }
        }

        return $moved;
    }

    /**
     * 更新 agent_cash_flows.info.available_banks[].BankCode_ELG
     *
     * 將 dup 的 bank.code 全部換成 primary.code。
     * 注意：available_banks 是 JSON 陣列，無法用 SQL UPDATE，需 fetch + modify + save。
     * 僅處理 cash_flows.type=3（代付），其他類型沒有 available_banks 欄位。
     *
     * 🔴 **已含 primary 碼的 ACF 一律跳過並審計（2026-08-24 三方會審裁決）**：同一張 ACF 若
     * 同時含 dup 碼與 primary 碼，替換後會出現兩個相同的 `BankCode_ELG`，而舊 payout JSON 路徑
     * （`WithdrawalPayoutTrait::convertPayoutBankCodeFromJson`）以 foreach 取**第一筆命中**、
     * 兩筆 entry 各自保留原本的 `BankCode_Third` ⇒ 送給三方的銀行碼由陣列順序決定。
     * 實測（唯讀掃 ACF JSON）：VWorld pair `989←1098`（`SGD0040`←`ANZSG`）命中 3 張，其中
     * `acf 237`（EasyPay 代付）與 `acf 313`（BillionPay Withdrawal）**都是 status=1**；
     * `acf 313` 兩碼的 Third 還不同（`SGD0040→ANZ` vs `ANZSG→ANZ_AU`，idx6 vs idx37），
     * 目前只靠 idx6 < idx37 的排列維持原行為。TPower 三組 pair 全部命中 `acf 359`（status=1）。
     * ⚠️ 這也是「這組可能不是同一家銀行」的訊號——dup 1098 名為 ANZ **(Australia)**，
     * BillionPay 給它獨立碼。同 repo 的 `AvailableBanksAlignService` 對同一情形早已判 conflict、
     * 只審計不改資料，這裡對齊該語意。
     *
     * ⚠️ 掃描範圍仍是全庫所有 type=3 ACF、不限 agent group／幣別（維持原行為）。base 層的碼會被
     * 複製到各 agent group，所以「跨 group 一起換」多數情況正確；範圍是否該收斂屬語意決策，
     * 未在此擅改——但「不製造重複 entry」是正確性守衛、不在語意決策的豁免範圍內。
     *
     * 鎖只加在真的要改的那幾張（先無鎖挑候選、再對候選 `lockForUpdate` 並重驗），避免把全庫
     * type=3 ACF 都鎖住；整包 `info` 覆寫會吃掉並發的後台編輯，故鎖不可省。
     *
     * @return array{changed: list<int>, conflicts: list<int>} 實際改寫的 ACF id 與留置的 ACF id
     */
    private function updateAgentCashFlowAvailableBanks(Bank $dup, Bank $primary): array
    {
        $oldCode = (string) $dup->code;
        $newCode = (string) $primary->code;

        $classified = $this->classifyAgentCashFlows($dup, $primary);
        $conflicts = $classified['conflicts'];

        if ($classified['targets'] === []) {
            return ['changed' => [], 'conflicts' => $conflicts];
        }

        $changed = [];

        $cashFlows = AgentCashFlow::query()
            ->whereIn('id', $classified['targets'])
            ->lockForUpdate()
            ->get();

        foreach ($cashFlows as $acf) {
            // 鎖前的挑選是無鎖快照，鎖後重驗一次
            if (!$this->hasBankCode($acf, $oldCode)) {
                continue;
            }

            if ($this->hasBankCode($acf, $newCode)) {
                $conflicts[] = (int) $acf->id;

                continue;
            }

            $info = $acf->info;
            $banks = $info['available_banks'] ?? [];

            // ⚠️ 不可寫成 `foreach ($info['available_banks'] ?? [] as &$entry)`——`??` 產生的是
            // 臨時值，by-reference 迭代改到的是那個臨時陣列、寫不回 $info（2026-08-24 由端到端
            // 測試抓到；靜態審查與三輪 code review 都沒發現，因為它靜默不生效、回傳筆數照樣非零）。
            foreach ($banks as $index => $entry) {
                if (($entry['BankCode_ELG'] ?? null) === $oldCode) {
                    $banks[$index]['BankCode_ELG'] = $newCode;
                }
            }

            $info['available_banks'] = $banks;

            $acf->info = $info;
            $acf->save();

            $changed[] = (int) $acf->id;
        }

        return ['changed' => $changed, 'conflicts' => $conflicts];
    }

    /**
     * 上者的純讀分類（`--dry-run` 與實跑共用）：哪些代付 ACF 會被改寫、哪些因已含 primary 碼
     * 而留置。
     *
     * 實跑另在鎖後重驗一次（鎖前是無鎖快照），可能再多出 conflict；預演看到的是當下狀態。
     *
     * @return array{targets: list<int>, conflicts: list<int>}
     */
    private function classifyAgentCashFlows(Bank $dup, Bank $primary): array
    {
        $oldCode = (string) $dup->code;
        $newCode = (string) $primary->code;

        if ($oldCode === $newCode) {
            return ['targets' => [], 'conflicts' => []];
        }

        $targets = [];
        $conflicts = [];

        // group 層合併（dup 非 base）時只掃該 group 的 ACF——group 的碼只服務該 group 會員，
        // 全庫改寫會把其他 group 還活著的同字串碼一併改掉、弄斷其送碼鏈（2026-08-26 TPower
        // g674 IDR0025 實證：base 合併的全庫改寫讓 group 活碼失去 JSON entry、查碼 fallback
        // 回空字串擋單）。base 層合併維持全庫掃描（現行語意；其 group-aware gate 補強另案）。
        $rows = AgentCashFlow::query()
            ->whereHas('cashFlow', fn ($q) => $q->where('type', 3))
            ->when((int) $dup->agent_group_id !== 0, fn ($q) => $q->where('agent_group_id', (int) $dup->agent_group_id))
            ->get();

        foreach ($rows as $acf) {
            if (!$this->hasBankCode($acf, $oldCode)) {
                continue;
            }

            if ($this->hasBankCode($acf, $newCode)) {
                $conflicts[] = (int) $acf->id;

                continue;
            }

            $targets[] = (int) $acf->id;
        }

        return ['targets' => $targets, 'conflicts' => $conflicts];
    }

    /**
     * 該 ACF 的 available_banks 是否含指定的 BankCode_ELG。
     */
    private function hasBankCode(AgentCashFlow $acf, string $code): bool
    {
        foreach ($acf->info['available_banks'] ?? [] as $entry) {
            if (($entry['BankCode_ELG'] ?? null) === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * 把一串受影響的 row id 分批寫成審計（清單為空即不寫）。
     *
     * @param  list<int>  $ids
     */
    private function auditIdList(Bank $primary, int $loserBankId, string $action, string $key, array $ids): void
    {
        foreach (array_chunk($ids, self::AUDIT_ID_CHUNK) as $chunk) {
            $this->audit($primary, $loserBankId, $action, [$key => $chunk]);
        }
    }

    /**
     * 留置的審計。primary 可能還沒撈出來（身分不符情境），故只用 id。
     *
     * @param  array<string, mixed>  $detail
     */
    private function auditSkip(int $primaryId, int $loserBankId, array $detail): void
    {
        $primary = Bank::query()->find($primaryId);

        if (!$primary) {
            return;
        }

        $this->audit($primary, $loserBankId, 'mismatch_skip_cfb', $detail);
    }

    /**
     * 寫一筆 bank_dedup_logs。
     *
     * action 一律 `mismatch_` 前綴（欄位上限 30 字元）。`bank:dedup-rollback` 消費的是 exact
     * 白名單，不含這些 action，故不會被誤回滾。
     *
     * @param  array<string, mixed>  $detail
     */
    private function audit(Bank $primary, int $loserBankId, string $action, array $detail): void
    {
        $encoded = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        // 截斷的 JSON 比人話更糟——解不開又救不回。寧可整筆失敗讓人查。
        if (mb_strlen($encoded) > self::DETAIL_MAX_LENGTH) {
            throw new \RuntimeException(sprintf(
                '審計 detail 超出 %d 字元，中止以免留下不可解的紀錄：%s',
                self::DETAIL_MAX_LENGTH,
                $encoded,
            ));
        }

        DB::table('bank_dedup_logs')->insert([
            'connection' => DB::connection()->getName(),
            'agent_group_id' => (int) $primary->agent_group_id,
            'code' => mb_substr((string) $primary->code, 0, 16),
            'survivor_bank_id' => (int) $primary->id,
            'loser_bank_id' => $loserBankId,
            'action' => $action,
            'detail' => $encoded,
        ]);
    }
}
