<?php

declare(strict_types=1);

namespace App\Console\Commands\CashFlow\Guard;

use App\Enums\WithdrawalOrderStatusEnum;
use App\Traits\PreservesRowTimestamps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wintoto g70/g93 drift 銀行雙列合併。
 *
 * 【分類】Guard — ⚠️ 非 sync-safe 守護命令。
 *
 * ## 觸發時機
 * **Wintoto 執行 `agent:add-agent-cash-flow`（或串起它的 `cash-flow:auto-sync`）之後**
 * ——那支經 `AgentBankSyncService::syncToAgentGroup()` 把整套新碼列建進 agent 層，
 * drift 雙列會重新長出來（2026-04-07、2026-07-07~08 兩波即為實例）。
 * 漏跑的後果：會員新綁定綁到 drift 列 → 代付判「不支援銀行」。
 *
 * ## 冪等性與重跑安全性
 * **冪等，重跑安全。** 配對表寫死，執行前逐列比對 DB（group／type／currency／code 完全一致），
 * 任一不符整體攔截零寫入——**跑錯白標 DB 必然被擋**。預設 dry-run，`--execute` 才寫入。
 * 每筆寫入記 `bank_dedup_logs`（`drift_*` 前綴），可依審計反向重放回滾。
 *
 * ⚠️ **2026-08-21 調整：survivor 已軟刪不再攔死整批。** 軌1 rename 會反轉 survivor／loser
 * 方向——2026-08-17 一次 rename 就把 WTT g70/g93 共 **27 對**的 survivor 停用＋軟刪、
 * 原 loser 成為 canonical 並承載綁定（g70 `98 WTMY03` → `1343 HongLeongBank` 3,877 筆為最大者）。
 * 該狀態下原邏輯的「未刪除」檢查會讓整批攔截，**連同真正待處理的對子一起鎖死**
 * （實測當時僅 3 對仍雙列並存：g70 `185 WTMY91`←`1439 TPMY21` 343 筆、
 * g93 `653 WTMY20`←`1409 TPMY20` 0 筆、g93 `654 WTMY91`←`1403 TPMY21` 0 筆）。
 * 故改為三類分流（見 `partitionPairs()`）：身分四項全中但已軟刪＝跳過並回報，
 * 身分不中仍維持攔死，環境防呆不因此弱化。
 * 📌 **retired 對子代表配對表該項已失效**，待軌1 rename 收斂完成後一併重新盤點。
 *
 * ## 誰才是重建者（2026-09-10 實查更正）
 *
 * ⚠️ **不是 `cash-flow:sync`。** `SyncCashFlowsCommand` 建的 `banks` 列一律
 * `agent_group_id = 0`（base 層，`:2130`），它不建代理群組層的列；
 * `AgentBankSyncService::syncToAgentGroup()` 全專案唯一的呼叫點是
 * `AddAgentCashFlow.php:1790`。⇒ 讓 drift 雙列長回來的是 `agent:add-agent-cash-flow`。
 *
 * 兩支由 `cash-flow:auto-sync` 依序串起（`AutoSyncCashFlowCommand.php:309` 呼 sync、
 * `:358` 呼 add-agent-cash-flow），所以實務上「跑完 sync」多半兩者都跑到——但只跑
 * `cash-flow:sync --ids=` 時 agent 層不會被動。
 *
 * ## 退役條件
 * 需要**同時**達成：
 * 1. base(group 0) 模板污染根治（`bank:merge-base-duplicate-banks` 的退役條件達成）；且
 * 2. `bank:rename-bank` 收斂完成，agent 層碼統一，sync 不再建出並存的新碼列。
 *
 * 屆時配對表會自然歸零，本命令可連同配對表一併刪除。
 *
 * ## 事故根因（2026-07-09 排查 VWorld agent_group 70，全程唯讀；描述的是當時的舊 sync 行為）
 * 上游：base(group 0) 模板本身用新碼系且自身重複。中游：當時的 sync（舊 `AddAgentCashFlowByChannel::syncBanksToAgentGroup`）
 * 以 name 為 key 拿 base 新碼就地覆寫 agent 層 banks.code，又只更新當前渠道那一個 ACF 的 available_banks、
 * 不同步其他 provider；另一波 sync 則把整套新碼列建進 agent 層，與老 WTMY canonical 並存成雙列。
 * 結果：TTPay 渠道 available_banks 仍是 WTMY 舊碼，走 JSON 路徑的出款字面比對不中、判「不支援銀行」
 * （實例：提款單 1408515 MayBank、1408696 AFFIN）。觸發源是 2026-07-07～08 為 Wintoto 配置 DGPay 時跑的
 * add-agent-cash-flow 與 sync。「就地覆寫 code」這條路徑已於 2026-07 由 `AgentBankSyncService` 的四元組
 * 去重鍵取代（code 不再被覆寫）；本命令處理的是那段期間長出的 drift 雙列，不是覆寫本身。
 * 另一條當時未判是否與上述互斥的歸因：base 模板上已下架的舊列被當模板繼承，agent 層長出 status=0 的列
 * （group 70／93 的 Maybank／CIMB，bank_id 581／1399／1435 就是這批）；這條路徑現由 AgentBankSyncService
 * 的 base 只認 status=1 擋住。
 * 排除項：`bank:dedup-active` 與本案無關（審計表當時為空、從未 execute）；`bank:fix-mismatch` 解不了
 * 本案（只收 base、無 group scope）。
 *
 * ## 關聯
 * - 上位路線：`docs/未完成計劃/RB/TODO.md`「銀行收斂 rollout 三連」之 Wintoto drift 尾巴
 * - ⚠️ 未盤點子樹：g99／g19／g3／g6、WTT／VWSG
 *
 * 背景：base(group 0) 模板污染 + 兩波 sync（2026-04-07、2026-07-07~08）把整套新碼列建進
 * agent 層，與老 WTMY* canonical 並存 → 會員新綁定綁到 drift 列，啟用中代付渠道的
 * available_banks 只認老碼 → 字面比對不中、代付判「不支援銀行」。
 *
 * 修復（配對表寫死，survivor=canonical、loser=drift）：
 * - 會員只綁 loser → 綁定 repoint 至 survivor（含特殊組：survivor 停用中仍 repoint，尊重停用意圖）
 * - 會員同時綁兩邊 → 停用 loser 綁定
 * - loser 一律 status=0 不刪（WithdrawalOrder::bank() belongsTo 無 status 過濾，刪列會打爆歷史單）
 * - withdrawal_orders 歷史單不溯及既往（Yes2Win 前例決策），一律不改
 *
 * 審計：每筆寫入記 bank_dedup_logs，action 用 drift_* 前綴與 bank:dedup-active 隔離——
 * bank:dedup-rollback 只消費無前綴 action，不會（也不得）回滾本命令的變更。
 * rollback 底稿：依 drift_* 審計列反向重放即可——
 *   drift_repoint             → member_bank_accounts.bank_id 改回 loser_bank_id
 *   drift_deactivate_binding  → member_bank_accounts.status 改回 1
 *   drift_deactivate_bank     → banks.status 改回 1
 * （本命令未動 withdrawal_orders，回滾亦無需觸碰。）
 *
 * 防呆：執行前逐列比對配對表與 DB（同 group/type/currency、code 完全一致、未刪除），
 * 任一不符即整體攔截零寫入——跑錯環境（其他白標 DB）必然 code 不匹配而被擋下。
 *
 * php artisan bank:merge-wintoto-drift-banks            # dry-run（預設）
 * php artisan bank:merge-wintoto-drift-banks --execute  # 實際執行（需確認）
 */
class MergeWintotoDriftBanks extends Command
{
    use PreservesRowTimestamps;

    public const BANK_TYPE = 1;

    /** VWorld connection 的 MYR */
    public const CURRENCY_ID = 1;

    /**
     * survivor ← losers 配對表（2026-07-13 全景盤點拍板；execute 前已逐列 SQL 複核）。
     *
     * @var list<array{agent_group_id: int, survivor_id: int, survivor_code: string, losers: array<int, string>}>
     */
    public const PAIRS = [
        // ---- g70 正常組（survivor = 啟用中 canonical）----
        ['agent_group_id' => 70, 'survivor_id' => 96, 'survivor_code' => 'WTMY01', 'losers' => [1344 => 'PublicBank']],
        ['agent_group_id' => 70, 'survivor_id' => 98, 'survivor_code' => 'WTMY03', 'losers' => [1343 => 'HongLeongBank']],
        ['agent_group_id' => 70, 'survivor_id' => 99, 'survivor_code' => 'WTMY04', 'losers' => [1435 => 'CIMB']],
        ['agent_group_id' => 70, 'survivor_id' => 100, 'survivor_code' => 'WTMY05', 'losers' => [1346 => 'MYAB']],
        ['agent_group_id' => 70, 'survivor_id' => 102, 'survivor_code' => 'WTMY07', 'losers' => [1351 => 'ABMB']],
        ['agent_group_id' => 70, 'survivor_id' => 103, 'survivor_code' => 'WTMY08', 'losers' => [1436 => 'TPMY07']],
        ['agent_group_id' => 70, 'survivor_id' => 104, 'survivor_code' => 'OCBC', 'losers' => [1355 => 'OCBC']],
        ['agent_group_id' => 70, 'survivor_id' => 105, 'survivor_code' => 'WTMY10', 'losers' => [1345 => 'RHB']],
        ['agent_group_id' => 70, 'survivor_id' => 106, 'survivor_code' => 'WTMY11', 'losers' => [1347 => 'STCB']],
        ['agent_group_id' => 70, 'survivor_id' => 107, 'survivor_code' => 'WTMY12', 'losers' => [1349 => 'UNOB']],
        ['agent_group_id' => 70, 'survivor_id' => 108, 'survivor_code' => 'WTMY13', 'losers' => [1441 => 'TPMY14', 1350 => 'HSBC']],
        ['agent_group_id' => 70, 'survivor_id' => 109, 'survivor_code' => 'WTMY14', 'losers' => [1357 => 'TPMY10']],
        ['agent_group_id' => 70, 'survivor_id' => 110, 'survivor_code' => 'WTMY15', 'losers' => [1453 => 'TPMY15']],
        ['agent_group_id' => 70, 'survivor_id' => 111, 'survivor_code' => 'WTMY16', 'losers' => [1353 => 'RAKY']],
        ['agent_group_id' => 70, 'survivor_id' => 112, 'survivor_code' => 'WTMY17', 'losers' => [1454 => 'MYR0068']],
        ['agent_group_id' => 70, 'survivor_id' => 113, 'survivor_code' => 'WTMY18', 'losers' => [1358 => 'KFHB']],
        ['agent_group_id' => 70, 'survivor_id' => 114, 'survivor_code' => 'WTMY19', 'losers' => [1354 => 'MUAM']],
        ['agent_group_id' => 70, 'survivor_id' => 185, 'survivor_code' => 'WTMY91', 'losers' => [1439 => 'TPMY21']],
        ['agent_group_id' => 70, 'survivor_id' => 561, 'survivor_code' => 'WTMY92', 'losers' => [1451 => 'VWMY34']],
        // ---- g70 特殊組（survivor 停用中：尊重停用意圖，drift 停用、綁定 repoint 到停用列）----
        ['agent_group_id' => 70, 'survivor_id' => 101, 'survivor_code' => 'AFFIN', 'losers' => [1438 => 'TPMY08']],
        ['agent_group_id' => 70, 'survivor_id' => 115, 'survivor_code' => 'CITIBANK', 'losers' => [1356 => 'CITI', 1437 => 'MYR0013']],
        ['agent_group_id' => 70, 'survivor_id' => 116, 'survivor_code' => 'WTMY20', 'losers' => [1445 => 'TPMY20']],
        ['agent_group_id' => 70, 'survivor_id' => 632, 'survivor_code' => 'RYT BANK', 'losers' => [1452 => 'RYT BANK']],
        // ---- g93 正常組 ----
        ['agent_group_id' => 93, 'survivor_id' => 633, 'survivor_code' => 'WTMY01', 'losers' => [207 => 'PUBLIC BANK', 1327 => 'PublicBank']],
        // 2026-08-21 校正 survivor_code：drift 合併（07-13，loser 581 已軟刪）之後，survivor 634 於
        // 07-15 被軌1 rename 從 WTMY02 改名為 MayBank（接手 loser 的碼）。原寫死的 WTMY02 已成過期值，
        // 讓身分檢查判為 code 漂移而攔死整批。DB 實證：banks 634 g93 code=MayBank status=1 綁定 10。
        ['agent_group_id' => 93, 'survivor_id' => 634, 'survivor_code' => 'MayBank', 'losers' => [581 => 'MayBank']],
        ['agent_group_id' => 93, 'survivor_id' => 635, 'survivor_code' => 'WTMY03', 'losers' => [1326 => 'HongLeongBank']],
        ['agent_group_id' => 93, 'survivor_id' => 636, 'survivor_code' => 'WTMY04', 'losers' => [1399 => 'CIMB']],
        ['agent_group_id' => 93, 'survivor_id' => 637, 'survivor_code' => 'WTMY05', 'losers' => [1329 => 'MYAB']],
        ['agent_group_id' => 93, 'survivor_id' => 639, 'survivor_code' => 'WTMY07', 'losers' => [1334 => 'ABMB']],
        ['agent_group_id' => 93, 'survivor_id' => 640, 'survivor_code' => 'WTMY08', 'losers' => [1400 => 'TPMY07']],
        ['agent_group_id' => 93, 'survivor_id' => 641, 'survivor_code' => 'OCBC', 'losers' => [1338 => 'OCBC']],
        ['agent_group_id' => 93, 'survivor_id' => 642, 'survivor_code' => 'WTMY10', 'losers' => [1328 => 'RHB']],
        ['agent_group_id' => 93, 'survivor_id' => 643, 'survivor_code' => 'WTMY11', 'losers' => [1330 => 'STCB']],
        ['agent_group_id' => 93, 'survivor_id' => 644, 'survivor_code' => 'WTMY12', 'losers' => [1332 => 'UNOB']],
        ['agent_group_id' => 93, 'survivor_id' => 645, 'survivor_code' => 'WTMY13', 'losers' => [1405 => 'TPMY14', 1333 => 'HSBC']],
        ['agent_group_id' => 93, 'survivor_id' => 646, 'survivor_code' => 'WTMY14', 'losers' => [1340 => 'TPMY10']],
        ['agent_group_id' => 93, 'survivor_id' => 647, 'survivor_code' => 'WTMY15', 'losers' => [1417 => 'TPMY15']],
        ['agent_group_id' => 93, 'survivor_id' => 648, 'survivor_code' => 'WTMY16', 'losers' => [1336 => 'RAKY']],
        ['agent_group_id' => 93, 'survivor_id' => 649, 'survivor_code' => 'WTMY17', 'losers' => [1418 => 'MYR0068']],
        ['agent_group_id' => 93, 'survivor_id' => 650, 'survivor_code' => 'WTMY18', 'losers' => [1341 => 'KFHB']],
        ['agent_group_id' => 93, 'survivor_id' => 651, 'survivor_code' => 'WTMY19', 'losers' => [1337 => 'MUAM']],
        ['agent_group_id' => 93, 'survivor_id' => 653, 'survivor_code' => 'WTMY20', 'losers' => [1409 => 'TPMY20']],
        ['agent_group_id' => 93, 'survivor_id' => 654, 'survivor_code' => 'WTMY91', 'losers' => [1403 => 'TPMY21']],
        ['agent_group_id' => 93, 'survivor_id' => 656, 'survivor_code' => 'WTMY92', 'losers' => [1415 => 'VWMY34']],
        ['agent_group_id' => 93, 'survivor_id' => 658, 'survivor_code' => 'RYT BANK', 'losers' => [1416 => 'RYT BANK']],
        // ---- g93 特殊組 ----
        ['agent_group_id' => 93, 'survivor_id' => 652, 'survivor_code' => 'CITIBANK', 'losers' => [1339 => 'CITI', 1401 => 'MYR0013']],
        ['agent_group_id' => 93, 'survivor_id' => 638, 'survivor_code' => 'AFIN', 'losers' => [1402 => 'TPMY08']],
    ];

    protected $signature = 'bank:merge-wintoto-drift-banks
                            {--execute : 實際執行合併（預設僅 dry-run 報表）}
                            {--force : 跳過執行前確認}';

    protected $description = 'Wintoto g70/g93 drift 銀行雙列合併（配對表寫死；預設 dry-run，僅報表不寫入）';

    public function handle(): int
    {
        $banks = $this->loadBanks();

        $partition = $this->partitionPairs($banks);

        if ($partition['errors'] !== []) {
            $this->error(sprintf('配對表與 DB 現況不符（%d 項），整體攔截、未寫入任何資料：', count($partition['errors'])));
            foreach ($partition['errors'] as $error) {
                $this->line('  - ' . $error);
            }

            return self::FAILURE;
        }

        if ($partition['conflicts'] !== []) {
            $this->error(sprintf('⚠ 狀態矛盾 %d 項（該對／該列已排除，其餘照常處理）：', count($partition['conflicts'])));
            foreach ($partition['conflicts'] as $conflict) {
                $this->line('  - ' . $conflict);
            }
            $this->newLine();
            $this->line('這些列已標記刪除卻仍 status=1。Bank model 沒有 SoftDeletes，會員可見性只看 status，');
            $this->line('所以它們對前台依然存在、依然能被新綁定 ⇒ 既不能當「已合併完成」跳過，也不該直接合併。');
            $this->line('請先確認該列是要停用（補 status=0）還是要復活（清 deleted_at），再重跑本命令。');
            $this->newLine();
        }

        if ($partition['retired'] !== []) {
            $this->warn(sprintf(
                '⏭ 跳過 %d 對：身分四項全中但已軟刪＝已完成（survivor 軟刪＝整對由軌1 rename 反向處理完；loser 軟刪＝該 drift 列已合併）。配對表該些項已失效，待軌1 rename 收斂後重新盤點。',
                count($partition['retired'])
            ));

            foreach ($partition['retired'] as $pair) {
                $this->line(sprintf(
                    '  - g%d survivor=%d(%s) / loser=%s ⇒ %s',
                    $pair['agent_group_id'],
                    $pair['survivor_id'],
                    $pair['survivor_code'],
                    implode('、', array_map(
                        static fn (int $id, string $code): string => $id . '(' . $code . ')',
                        array_keys($pair['losers']),
                        array_values($pair['losers']),
                    )),
                    $pair['reason'],
                ));
            }

            $this->newLine();
        }

        if ($partition['actionable'] === []) {
            $this->info('✅ 配對表中已無可處理對子（全數已合併，或已由軌1 rename 接手）。');

            return self::SUCCESS;
        }

        $plan = $this->buildPlan($banks, $partition['actionable']);
        $this->displayPlan($plan);

        $actionable = array_values(array_filter(
            $plan,
            static fn (array $row): bool => $row['deactivate_bindings'] > 0 || $row['repoint_bindings'] > 0 || $row['deactivate_bank'],
        ));

        $inflightTotal = array_sum(array_column($plan, 'inflight_orders'));
        if ($inflightTotal > 0) {
            $this->warn(sprintf(
                '⚠️ loser 銀行上仍有 %d 筆 in-flight 出款單（僅提示，本命令不改 withdrawal_orders；請於執行後人工確認出款路徑）',
                $inflightTotal
            ));
        }

        if (!$this->option('execute')) {
            $this->newLine();
            $this->info(sprintf(
                '🔍 Dry-run 模式，未寫入。待處理：停用綁定 %d、repoint 綁定 %d、停用銀行 %d。加 --execute 以實際執行。',
                array_sum(array_column($plan, 'deactivate_bindings')),
                array_sum(array_column($plan, 'repoint_bindings')),
                count(array_filter(array_column($plan, 'deactivate_bank'))),
            ));

            return self::SUCCESS;
        }

        if ($actionable === []) {
            $this->newLine();
            $this->info('✅ 無需變更（已全數合併，冪等）');

            return self::SUCCESS;
        }

        if (!$this->option('force')
            && !$this->confirm('確認執行 Wintoto drift 銀行合併？此操作將合併會員綁定並停用 drift 銀行列', false)) {
            $this->info('已取消，未寫入任何資料。');

            return self::SUCCESS;
        }

        $summary = $this->executeMerge($partition['actionable']);

        $this->newLine();
        $this->info(sprintf(
            '✅ 合併完成：停用綁定 %d、repoint 綁定 %d、停用銀行 %d（審計見 bank_dedup_logs action=drift_*）。',
            $summary['deactivated_bindings'],
            $summary['repointed'],
            $summary['deactivated_banks'],
        ));

        return self::SUCCESS;
    }

    /**
     * 一次撈出配對表涉及的全部 banks 列，以 id 為鍵。
     *
     * @return array<int, object{id: int, agent_group_id: int, type: int, currency_id: int|null, code: string, status: int, deleted_at: string|null}>
     */
    private function loadBanks(bool $lock = false): array
    {
        $ids = [];
        foreach (self::PAIRS as $pair) {
            $ids[] = $pair['survivor_id'];
            foreach (array_keys($pair['losers']) as $loserId) {
                $ids[] = $loserId;
            }
        }

        $query = DB::table('banks')->whereIn('id', $ids);

        // $lock 只在 executeMerge 的交易內用：鎖的是主鍵，範圍精準
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query
            ->get(['id', 'agent_group_id', 'type', 'currency_id', 'code', 'status', 'deleted_at'])
            ->keyBy('id')
            ->all();
    }

    /**
     * 逐列比對配對表與 DB 現況，把配對表分流為三類。
     *
     * - errors     ：環境／身分不符（跑錯白標 DB、code 漂移）⇒ 呼叫端整批攔截零寫入
     * - retired    ：身分全中但已軟刪 ⇒ 已完成，跳過並回報。survivor 軟刪＝整對退場（軌1 rename
     *                反向處理完畢）；loser 軟刪＝逐 loser 退場（該 drift 列已合併），同對其他
     *                未完成的 loser 仍會被處理
     * - actionable ：驗證通過、交由 buildPlan／executeMerge 處理
     *
     * @param  array<int, object>  $banks
     * @return array{errors: list<string>, conflicts: list<string>, retired: list<array{agent_group_id: int, survivor_id: int, survivor_code: string, losers: array<int, string>, reason: string}>, actionable: list<array{agent_group_id: int, survivor_id: int, survivor_code: string, losers: array<int, string>}>}
     */
    private function partitionPairs(array $banks): array
    {
        $errors = [];
        $conflicts = [];
        $retired = [];
        $actionable = [];

        foreach (self::PAIRS as $pair) {
            $survivor = $banks[$pair['survivor_id']] ?? null;

            // 「survivor 已軟刪」單獨分流，不併入 errors：軌1 rename 會反轉 survivor／loser
            // 方向（實證 2026-08-17 WTT g70/g93 共 27 對），此時原 survivor 被停用＋軟刪、
            // 原 loser 成為 canonical 並承載綁定。那是「該對已完成、方向與配對表相反」，
            // 不是「跑錯白標 DB」；沿用整批攔截會連同真正待處理的對子一起鎖死整支命令。
            // ⚠️ 僅在身分四項（group／type／currency／code）全中時才判定為已接手，
            // 身分不中仍屬環境不符、維持攔死——跑錯 DB 時 id 對到的列不會四項全中。
            // 「已軟刪卻仍 status=1」是狀態矛盾，不是已完成：Bank model 沒有 SoftDeletes，
            // 會員可見性只看 status=1，所以這種列對前台依然存在、依然能被新綁定。
            // 單獨分流成 conflicts：該對排除不動、明確回報等人處理，但不阻擋其他對子
            // ——整批鎖死正是本命令原本的毛病。
            if ($survivor !== null && $this->isStateConflict($survivor, $pair['survivor_code'], $pair['agent_group_id'])) {
                $conflicts[] = sprintf(
                    'g%d survivor=%d(%s)：已標記刪除（%s）卻仍 status=1 ⇒ 前台仍可見、仍可被新綁定；'
                    . '該對整對排除，請先確認這列是要停用還是要復活',
                    $pair['agent_group_id'],
                    $pair['survivor_id'],
                    $pair['survivor_code'],
                    (string) $survivor->deleted_at,
                );

                continue;
            }

            if ($survivor !== null && $this->isRetiredRow($survivor, $pair['survivor_code'], $pair['agent_group_id'])) {
                // survivor 退場後，該對的 canonical 應該只剩一列。若有兩個以上 loser 還活著，
                // 表示 rename 只接手了其中一個變體，drift 已經改存在於那些 loser 之間；
                // 而配對表只描述 survivor↔loser 的關係、不描述 loser 彼此，整對跳過就再也發現不了。
                $activeLosers = $this->activeLosers($banks, $pair['losers']);

                if (count($activeLosers) > 1) {
                    $errors[] = sprintf(
                        'pair survivor=%d(%s)：survivor 已退場但仍有 %d 個 loser 是 active（%s）＝canonical 不唯一，'
                        . 'drift 已轉移到 loser 之間，需人工重盤配對表',
                        $pair['survivor_id'],
                        $pair['survivor_code'],
                        count($activeLosers),
                        implode('、', $activeLosers)
                    );

                    continue;
                }

                $retired[] = [...$pair, 'reason' => 'survivor 已軟刪：整對已由軌1 rename 反向處理完畢'];

                continue;
            }

            $rowErrors = $this->validateRow($banks, $pair['survivor_id'], $pair['survivor_code'], $pair['agent_group_id'], 'survivor');

            $remainingLosers = [];
            $retiredLosers = [];

            foreach ($pair['losers'] as $loserId => $loserCode) {
                // 防未來改動配對表時把同列填成 survivor＋loser；現行常值下恆假，PHPStan 誤報為死碼
                if ($loserId === $pair['survivor_id']) { // @phpstan-ignore identical.alwaysFalse
                    $rowErrors[] = sprintf('pair survivor=%d：loser 與 survivor 同列', $pair['survivor_id']);

                    continue;
                }

                $loser = $banks[$loserId] ?? null;

                // loser 已軟刪（身分四項全中）＝該 drift 列早已合併並標刪，無事可做。
                // 逐 loser 判定而非整對：同一 survivor 可掛多個 loser，
                // 其中一個完成不該讓同對另一個未完成的也被跳過。
                if ($loser !== null && $this->isStateConflict($loser, $loserCode, $pair['agent_group_id'])) {
                    $conflicts[] = sprintf(
                        'g%d survivor=%d(%s) / loser=%d(%s)：loser 已標記刪除（%s）卻仍 status=1 ⇒ 前台仍可見、'
                        . '仍可被新綁定；該 loser 排除不動，請先確認這列是要停用還是要復活',
                        $pair['agent_group_id'],
                        $pair['survivor_id'],
                        $pair['survivor_code'],
                        $loserId,
                        $loserCode,
                        (string) $loser->deleted_at,
                    );

                    continue;
                }

                if ($loser !== null && $this->isRetiredRow($loser, $loserCode, $pair['agent_group_id'])) {
                    $retiredLosers[$loserId] = $loserCode;

                    continue;
                }

                $loserErrors = $this->validateRow($banks, $loserId, $loserCode, $pair['agent_group_id'], 'loser');

                if ($loserErrors !== []) {
                    $rowErrors = [...$rowErrors, ...$loserErrors];

                    continue;
                }

                $remainingLosers[$loserId] = $loserCode;
            }

            if ($retiredLosers !== []) {
                $retired[] = [...$pair, 'losers' => $retiredLosers, 'reason' => 'loser 已軟刪：該 drift 列已合併完成'];
            }

            if ($rowErrors !== []) {
                $errors = [...$errors, ...$rowErrors];

                continue;
            }

            if ($remainingLosers === []) {
                continue;
            }

            $actionable[] = [...$pair, 'losers' => $remainingLosers];
        }

        return ['errors' => $errors, 'conflicts' => $conflicts, 'retired' => $retired, 'actionable' => $actionable];
    }

    /**
     * 該列是否為「已被收斂處理掉」的退場列。
     *
     * 三個條件都要成立：已軟刪、status 為 0、身分四項全中。
     *
     * status 也要一起看，是因為 Bank model 沒有 SoftDeletes：`deleted_at` 沒有全域 scope，
     * 會員能不能看到某銀行只取決於 `status = 1`（前台是 INNER JOIN ＋ b.status=1，見
     * Frontend/WithdrawalController:252-255）。所以「已軟刪但 status 仍是 1」的列對會員
     * 依然可見、依然能被新綁定——那不是已完成，是狀態矛盾，要歸 errors 讓人來看。
     */
    private function isRetiredRow(object $bank, string $expectedCode, int $expectedGroup): bool
    {
        return $bank->deleted_at !== null
            && (int) $bank->status === 0
            && $this->identityMatches($bank, $expectedCode, $expectedGroup);
    }

    /**
     * 該列是否處於「已標記刪除卻仍啟用」的矛盾狀態。
     *
     * 兩軸是各自獨立寫入的（`BankActiveDedupService` 一支只寫 deleted_at、另一支只寫 status；
     * `bank:dedup-rollback` 的復原也分兩個 action），所以會分岔。這種列既不算已完成、
     * 也不該直接合併，單獨分流成 conflicts 等人判斷。
     */
    private function isStateConflict(object $bank, string $expectedCode, int $expectedGroup): bool
    {
        return $bank->deleted_at !== null
            && (int) $bank->status === 1
            && $this->identityMatches($bank, $expectedCode, $expectedGroup);
    }

    /**
     * 這些 loser 之中還活著（未軟刪且 status=1）的，回傳 "id(code)" 描述字串。
     *
     * @param  array<int, object>  $banks
     * @param  array<int, string>  $losers
     * @return list<string>
     */
    private function activeLosers(array $banks, array $losers): array
    {
        $active = [];

        foreach ($losers as $loserId => $loserCode) {
            $loser = $banks[$loserId] ?? null;

            if ($loser !== null && $loser->deleted_at === null && (int) $loser->status === 1) {
                $active[] = sprintf('%d(%s)', $loserId, $loserCode);
            }
        }

        return $active;
    }

    /**
     * 身分四項是否全中（group／type／currency／code）。
     *
     * 用於區分「已由 rename 接手」與「跑錯白標 DB」：前者身分全中、僅 deleted_at 非空。
     */
    private function identityMatches(object $bank, string $expectedCode, int $expectedGroup): bool
    {
        return (int) $bank->agent_group_id === $expectedGroup
            && (int) $bank->type === self::BANK_TYPE
            && (int) $bank->currency_id === self::CURRENCY_ID
            && (string) $bank->code === $expectedCode;
    }

    /**
     * @param  array<int, object>  $banks
     * @return list<string>
     */
    private function validateRow(array $banks, int $bankId, string $expectedCode, int $expectedGroup, string $role): array
    {
        $bank = $banks[$bankId] ?? null;

        if ($bank === null) {
            return [sprintf('%s bank_id=%d：DB 無此列', $role, $bankId)];
        }

        $errors = [];

        if ($bank->deleted_at !== null) {
            $errors[] = sprintf('%s bank_id=%d：已標記刪除（deleted_at=%s）', $role, $bankId, $bank->deleted_at);
        }

        if ((int) $bank->agent_group_id !== $expectedGroup) {
            $errors[] = sprintf('%s bank_id=%d：agent_group_id=%d ≠ 預期 %d', $role, $bankId, (int) $bank->agent_group_id, $expectedGroup);
        }

        if ((int) $bank->type !== self::BANK_TYPE) {
            $errors[] = sprintf('%s bank_id=%d：type=%d ≠ 預期 %d', $role, $bankId, (int) $bank->type, self::BANK_TYPE);
        }

        if ((int) $bank->currency_id !== self::CURRENCY_ID) {
            $errors[] = sprintf('%s bank_id=%d：currency_id=%s ≠ 預期 %d', $role, $bankId, (string) $bank->currency_id, self::CURRENCY_ID);
        }

        if ((string) $bank->code !== $expectedCode) {
            $errors[] = sprintf('%s bank_id=%d：code=%s ≠ 預期 %s', $role, $bankId, (string) $bank->code, $expectedCode);
        }

        return $errors;
    }

    /**
     * 建立逐 loser 的處理計劃（快照統計，執行時以當下狀態為準）。
     *
     * @param  array<int, object>  $banks
     * @param  list<array{agent_group_id: int, survivor_id: int, survivor_code: string, losers: array<int, string>}>  $pairs  僅 partitionPairs 判定為 actionable 的對子
     * @return list<array{agent_group_id: int, survivor_id: int, survivor_code: string, loser_id: int, loser_code: string, loser_status: int, deactivate_bindings: int, repoint_bindings: int, deactivate_bank: bool, inflight_orders: int}>
     */
    private function buildPlan(array $banks, array $pairs): array
    {
        $inflightStatuses = [
            WithdrawalOrderStatusEnum::ORDER_CREATED->value,
            WithdrawalOrderStatusEnum::PENDING_REVIEW->value,
            WithdrawalOrderStatusEnum::APPROVED->value,
            WithdrawalOrderStatusEnum::PAYMENT_SUBMITTED->value,
            WithdrawalOrderStatusEnum::PAYMENT_PROCESSING->value,
        ];

        $plan = [];

        foreach ($pairs as $pair) {
            foreach ($pair['losers'] as $loserId => $loserCode) {
                $plan[] = [
                    'agent_group_id' => $pair['agent_group_id'],
                    'survivor_id' => $pair['survivor_id'],
                    'survivor_code' => $pair['survivor_code'],
                    'loser_id' => $loserId,
                    'loser_code' => $loserCode,
                    'loser_status' => (int) $banks[$loserId]->status,
                    'deactivate_bindings' => $this->duplicateBindingsQuery($pair['survivor_id'], $loserId)->count(),
                    'repoint_bindings' => $this->soleBindingsQuery($pair['survivor_id'], $loserId)->count(),
                    'deactivate_bank' => (int) $banks[$loserId]->status === 1,
                    'inflight_orders' => DB::table('withdrawal_orders')
                        ->where('bank_id', $loserId)
                        ->whereIn('status', $inflightStatuses)
                        ->count(),
                ];
            }
        }

        return $plan;
    }

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    private function displayPlan(array $plan): void
    {
        $this->table(
            ['group', 'survivor', 'loser', 'loser_status', '停用綁定', 'repoint 綁定', '停用銀行', 'in-flight 單'],
            array_map(static fn (array $row): array => [
                $row['agent_group_id'],
                sprintf('%d %s', $row['survivor_id'], $row['survivor_code']),
                sprintf('%d %s', $row['loser_id'], $row['loser_code']),
                $row['loser_status'],
                $row['deactivate_bindings'],
                $row['repoint_bindings'],
                $row['deactivate_bank'] ? 'Y' : '-',
                $row['inflight_orders'],
            ], $plan),
        );
    }

    /**
     * 執行合併（單一交易）：逐 loser 先停用重複綁定、再 repoint 獨佔綁定、最後停用 loser 銀行列。
     *
     * ## 鎖內重驗（2026-08-25 補）
     *
     * 呼叫端的分流跑在 `--force`／confirm 提示**之前**，提示開著多久，那份快照就過期多久。
     * 所以交易入口把配對表涉及的 banks 列按主鍵上鎖、重跑同一套 `partitionPairs`，只處理
     * 鎖內仍 actionable 的對子——確認期間被軟刪、退場或狀態矛盾化的對子在這裡被丟掉並回報，
     * 而不是照舊快照合併下去。
     *
     * 綁定的鎖用**兩階段主鍵鎖**，不能 `WHERE bank_id … FOR UPDATE`：`member_bank_accounts`
     * 沒有 `bank_id` 索引（2026-08-25 實查只有 PRIMARY 與 member_id 兩個索引、全表 15 萬列），
     * 帶鎖直查會全表掃描並把整張會員綁定表鎖到交易結束。鎖不到「鎖後才新增的綁定」是
     * SELECT … FOR UPDATE 的固有限制，殘餘空窗以審計可回推為底線。
     *
     * @param  list<array{agent_group_id: int, survivor_id: int, survivor_code: string, losers: array<int, string>}>  $pairs  鎖外分流判定為 actionable 的對子（僅用於決定處理範圍，內容以鎖內重驗為準）
     * @return array{deactivated_bindings: int, repointed: int, deactivated_banks: int}
     */
    private function executeMerge(array $pairs): array
    {
        $summary = ['deactivated_bindings' => 0, 'repointed' => 0, 'deactivated_banks' => 0];
        $connection = (string) config('database.default');

        DB::transaction(function () use ($connection, $pairs, &$summary): void {
            $lockedActionable = [];

            foreach ($this->partitionPairs($this->loadBanks(lock: true))['actionable'] as $freshPair) {
                $lockedActionable[$freshPair['survivor_id']] = $freshPair;
            }

            foreach ($pairs as $stalePair) {
                // 對子內容（含 losers 清單）一律取鎖內重驗的版本，鎖外那份只決定處理範圍
                $pair = $lockedActionable[$stalePair['survivor_id']] ?? null;

                if ($pair === null) {
                    $this->warn(sprintf(
                        '  ⏭ g%d survivor=%d(%s)：鎖內重驗已不屬 actionable（確認期間狀態變了），本對跳過未動',
                        $stalePair['agent_group_id'],
                        $stalePair['survivor_id'],
                        $stalePair['survivor_code'],
                    ));

                    continue;
                }

                foreach ($pair['losers'] as $loserId => $loserCode) {
                    $this->mergeLoserBindings($connection, $pair, $loserId, $loserCode, $summary);
                }
            }
        });

        return $summary;
    }

    /**
     * 單一 loser 的綁定合併與銀行停用。呼叫端保證已在交易內、且 banks 列已上鎖。
     *
     * @param  array{agent_group_id: int, survivor_id: int, survivor_code: string}  $pair
     * @param  array{deactivated_bindings: int, repointed: int, deactivated_banks: int}  $summary
     */
    private function mergeLoserBindings(string $connection, array $pair, int $loserId, string $loserCode, array &$summary): void
    {
        $detail = sprintf('wintoto_drift_merge survivor_code=%s', $pair['survivor_code']);
        $survivorId = $pair['survivor_id'];

        // 第一階段：無鎖挑候選，只取 id（bank_id 無索引，理由見 executeMerge docblock）
        $candidateIds = [
            ...$this->duplicateBindingsQuery($survivorId, $loserId)->pluck('id')->all(),
            ...$this->soleBindingsQuery($survivorId, $loserId)->pluck('id')->all(),
        ];

        if ($candidateIds !== []) {
            // 第二階段：對候選列按主鍵上鎖，鎖的範圍只有這幾列
            $bindings = DB::table('member_bank_accounts')
                ->whereIn('id', $candidateIds)
                ->lockForUpdate()
                ->get(['id', 'member_id', 'bank_id', 'status']);

            // 分類依據「會員有沒有 active survivor 綁定」也要在鎖內定格（走 member_id 索引）；
            // 不鎖的話，情況1／情況2 的歸類仍取決於一個可被並發翻動的事實
            $membersWithSurvivor = array_flip(array_map(
                'intval',
                DB::table('member_bank_accounts')
                    ->whereIn('member_id', $bindings->pluck('member_id')->unique()->all())
                    ->where('bank_id', $survivorId)
                    ->where('status', 1)
                    ->lockForUpdate()
                    ->pluck('member_id')
                    ->all(),
            ));

            foreach ($bindings as $binding) {
                // 鎖前的挑選是無鎖快照，鎖後逐列重驗——分類語意與兩個 *BindingsQuery 一致
                if ((int) $binding->bank_id !== $loserId) {
                    continue;
                }

                if (isset($membersWithSurvivor[(int) $binding->member_id])) {
                    // 情況1：會員同時綁 survivor 與 loser → 停用 loser 綁定（已停用者無事可做）
                    if ((int) $binding->status !== 1) {
                        continue;
                    }

                    DB::table('member_bank_accounts')->where('id', $binding->id)->update($this->preservingUpdatedAt(['status' => 0]));
                    $this->writeAudit($connection, $pair, $loserId, $loserCode, (int) $binding->member_id, (int) $binding->id, 'drift_deactivate_binding', $detail);
                    $summary['deactivated_bindings']++;

                    continue;
                }

                // 情況2：會員只綁 loser → repoint 至 survivor（不動 withdrawal_orders，不溯及既往）
                DB::table('member_bank_accounts')->where('id', $binding->id)->update($this->preservingUpdatedAt(['bank_id' => $survivorId]));
                $this->writeAudit($connection, $pair, $loserId, $loserCode, (int) $binding->member_id, (int) $binding->id, 'drift_repoint', $detail);
                $summary['repointed']++;
            }
        }

        // loser 銀行停用（status=0 不刪；已停用者不重複記審計）——該列已在交易入口上鎖
        $deactivated = DB::table('banks')
            ->where('id', $loserId)
            ->where('status', 1)
            ->update(['status' => 0]);

        if ($deactivated > 0) {
            $this->writeAudit($connection, $pair, $loserId, $loserCode, null, null, 'drift_deactivate_bank', $detail);
            $summary['deactivated_banks']++;
        }
    }

    /**
     * 情況1 查詢：loser 上仍啟用、且會員同時擁有 survivor 綁定的 member_bank_accounts。
     * 只取 status=1：已停用者無需再停（計劃與執行共用此語意，dry-run 報表才會冪等歸零）。
     */
    private function duplicateBindingsQuery(int $survivorId, int $loserId): \Illuminate\Database\Query\Builder
    {
        return DB::table('member_bank_accounts')
            ->where('bank_id', $loserId)
            ->where('status', 1)
            // 子查詢必須限 status=1：停用的 survivor 綁定不算「已綁 survivor」。不限的話，
            // 只有停用 survivor 綁定的會員會在這裡被停掉唯一有效的 loser 綁定，又因該列存在
            // 而被 soleBindingsQuery 的 whereNotIn 排除，最後零個有效綁定、無法提款。
            ->whereIn('member_id', function ($query) use ($survivorId): void {
                $query->select('member_id')
                    ->from('member_bank_accounts')
                    ->where('bank_id', $survivorId)
                    ->where('status', 1);
            });
    }

    /**
     * 情況2 查詢：loser 上、且會員沒有 **有效** survivor 綁定的 member_bank_accounts
     * （loser 側任意 status 均 repoint）。
     */
    private function soleBindingsQuery(int $survivorId, int $loserId): \Illuminate\Database\Query\Builder
    {
        return DB::table('member_bank_accounts')
            ->where('bank_id', $loserId)
            // 判準與 duplicateBindingsQuery 互補，同樣只認 active 的 survivor 綁定：
            // 只有停用 survivor 綁定的會員歸這裡處理，repoint 後他在 survivor 下會有一停用
            // 一啟用兩列，前台看到的仍是那筆有效綁定。
            ->whereNotIn('member_id', function ($query) use ($survivorId): void {
                $query->select('member_id')
                    ->from('member_bank_accounts')
                    ->where('bank_id', $survivorId)
                    ->where('status', 1);
            });
    }

    /**
     * @param  array{agent_group_id: int, survivor_id: int}  $pair
     */
    private function writeAudit(string $connection, array $pair, int $loserId, string $loserCode, ?int $memberId, ?int $bindingId, string $action, string $detail): void
    {
        DB::table('bank_dedup_logs')->insert([
            'connection' => $connection,
            'agent_group_id' => $pair['agent_group_id'],
            'code' => $loserCode,
            'survivor_bank_id' => $pair['survivor_id'],
            'loser_bank_id' => $loserId,
            'member_id' => $memberId,
            'member_bank_account_id' => $bindingId,
            'action' => $action,
            'detail' => $detail,
        ]);
    }
}
