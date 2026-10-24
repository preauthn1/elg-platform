<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Helpers\Common;
use App\Models\Agent;
use App\Models\SummaryBetRecord;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SR29 代理遊戲明細表（target/tickets#4318）。
 *
 * 一支端點服務主表與所有下鑽層，層級由參數決定（見 AgentGameDetailRequest）：
 * - 第 0 層（主表）：登入者整條下線，依「遊戲商 × 遊戲類別」彙總成列。
 *   查詢表單的遊戲商／類別篩選只作用在這一層（`filter_vendor_id`／`filter_game_type_id`）。
 * - 第 N 層（下鑽）：鎖定被點那一列的遊戲商＋遊戲類別，列出目標代理的**直屬下級**
 *   ——直屬會員各自一列（葉節點），直屬下級代理各自一列且**數字含它整條下線**。
 *
 * 🔴 需求文件的驗收核心：「本層所有列相加 ＝ 本層合計列 ＝ 上一層被點的那一列」。
 * 這個等式之所以成立，是因為目標代理底下的每個有投注會員，必定**恰好**落在
 * 「某一個直屬下級代理的子樹」或「目標代理自己的直屬會員」其中之一，沒有重疊也
 * 沒有遺漏。改動 bucket 的算法前請先確認這個性質仍然成立。
 *
 * 玩家人數欄是唯一例外：COUNT DISTINCT 不可跨列相加。主表合計列另外算一次全期間
 * DISTINCT（同一會員玩兩種類別只算一人，所以合計 ≠ 各列相加），下鑽層的會員列
 * 回 null 讓前端顯示「—」。
 *
 * ## 分頁（使用者 2026-10-02 指定）
 *
 * - **主表不分頁**、一次回整層：排序在前端做（預設依下注金額由大到小），分頁的話前端
 *   只能排當頁。列數是「遊戲商 × 遊戲類別」的組數，最多幾百列。
 * - **下鑽層後端分頁**（10／20／50／100／500／1000，預設 10，不提供「全部」）：正式站有
 *   直屬會員近四萬的代理，一次回整層會讓 PHP 組四萬列、回十幾 MB 的 JSON，瀏覽器也接
 *   不住。注意分頁**省不了 DB 的聚合**——要依下注金額排序就得先把整層 GROUP BY 完，
 *   省下的是 PHP、傳輸與瀏覽器；總筆數用 `COUNT(*) OVER()` 跟當頁同一次查詢帶出，
 *   不另跑一次 COUNT（那會把聚合做兩遍）。
 *
 * 🔴 **合計列是整層的合計，不是當頁的合計**：「本層所有列相加 ＝ 合計」要跨所有頁才成立。
 * 合計一律由 `summaryOf()` 對整層另外算，前端不可自行加總（玩家人數是 COUNT DISTINCT 不可加）。
 *
 * ## 資料源：summary_bet_records（經 PM 確認，target/tickets#4318）
 *
 * 🔴 `summary_bet_records.bet` 欄位名叫 bet、comment 寫「投注金額」，但實際存的是
 * `SUM(total_bet_records.valid_bet)`，也就是**有效下注**
 * （見 SummaryBetRecordService::aggregateSelectColumns()）。整張彙總表沒有原始下注金額。
 * 經 PM 確認：本報表的「下注金額」與「有效下注」**都取這個欄位**，兩欄數字會相同。
 * 這是刻意的，不是 bug；要讓兩欄真正分開，得先替彙總管線補一個真正的 bet 欄位。
 *
 * 🔴 可視範疇比照 API_Agent 的慣例（`Authenticate.php` 設定 `HIERARCHY_PATH` 那段）：
 * `host_id = 0` 取自己的 `hierarchy_path`，**子帳號一律取上級（`parent`）那條線**。
 * 沒有「不限範疇」這條路——平台管理員也是拿自己的 `/1`，所以看不到其他最上層代理
 * （`parent = 0` 的另外幾條線）。主表與下鑽共用 `scopePathOf()`，起點必須一致，
 * 否則第 1 層的合計不會等於主表那一列。
 *
 * 🔴 時間欄用 `summary_time`（經 PM 確認，target/tickets#4318），調帳不另外處理。
 * 這張表另有 `data_time` 欄：調帳列（correction = 1）寫入時 `data_time` 保留資料
 * 原本的小時，`summary_time` 記的是「補寫的當下」，兩者可以差到數年（本機實測最大
 * 35064 小時）。也就是說**舊期間的更正會被算進補寫當下的期間**，與 SR12 逐筆明細
 * 在有調帳的期間會對不起來。這是刻意的取捨：代理數據看板走的也是 `summary_time`，
 * 本報表跟它一致優先。要改成「更正歸屬原期間」就把下面 `base()` 的欄位換成
 * `data_time`，其餘邏輯不動。
 *
 * ## 派彩／輸贏口徑
 *
 * 一般遊戲商的 `win_lose` 是**淨輸贏**：派彩 ＝ 有效下注 + win_lose，玩家輸贏 ＝ win_lose。
 *
 * 🔴 4D 類的 HAOLONG（4）、BOLAKK4D（104）、NEW4D2026（181）的 `win_lose` 記的其實是
 * **派彩（中獎金額）**，沒中就是 0、不會出現負數（HAOLONG 由 SP_transfer_bet_record_4D
 * 寫入 `strike`；BOLAKK4D 沿用三方原始表；NEW4D2026 由 RD-Studio-01 的
 * GetNew4d2026BetRecord 寫入 `win`，該檔註明「4D 的 win_lose 不計算 bet, 只計算 win」）。
 * 所以這三家改用（使用者 2026-10-02 指定）：
 * - 派彩 ＝ win_lose
 * - 玩家輸贏 ＝ win_lose − 有效下注
 * - 公司輸贏 ＝ −玩家輸贏（與其他遊戲商同一條規則，在 formatRow() 算）
 *
 * 遊戲商清單與 SQL 片段放在 `SummaryBetRecord::winLoseIsPayoutVendorIds()`／`betMetricSelects()`，
 * 與 SR28 代理營運彙總表共用（target/tickets#4362）。
 *
 * NEW4D2026 上線初期 win_lose 誤寫成淨輸贏，2026-06-09（RD-Studio-01 `6ac8996d`）修正，
 * 正式站與 dev 的舊資料都已一併調整（dev 於 2026-10-02 補調）。若再看到這三家的
 * win_lose 出現負數，代表資料又寫錯了，不是本報表的問題。
 *
 * SR12 只對 4、104 的派彩逐筆做 `CASE WHEN win_lose > 0 THEN valid_bet + win_lose ELSE 0 END`
 * （沒有包含 181），彙總表已失去逐筆粒度無法重現，這幾家與 SR12 的數字會不同。
 */
class AgentGameDetailService
{
    /** 金額一律 4 位小數，對齊 summary_bet_records 的 decimal(18,4) */
    private const SCALE = 4;

    /** 下鑽層預設單頁筆數，與前端下拉的第一個選項一致 */
    private const DEFAULT_PER_PAGE = 10;

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function list(Agent $viewer, array $input, int $languageId): array
    {
        [$start, $end] = $this->range($input);
        $vendorId = $input['vendor_id'] ?? null;

        return $vendorId === null
            ? $this->mainTable($viewer, $input, $start, $end, $languageId)
            : $this->drillDown(
                $viewer,
                $input,
                $start,
                $end,
                (int) $vendorId,
                (int) $input['game_type_id'],
            );
    }

    /**
     * 第 0 層：依「遊戲商 × 遊戲類別」彙總登入者整條下線。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function mainTable(
        Agent $viewer,
        array $input,
        string $start,
        string $end,
        int $languageId,
    ): array {
        $scoped = $this->scopedBase($viewer, $input, $start, $end);

        $grouped = (clone $scoped)
            ->selectRaw('sbr.vendor_id as vendor_id')
            ->selectRaw('COALESCE(g.type_id, 0) as game_type_id')
            ->selectRaw('COUNT(DISTINCT sbr.member_id) as player_count')
            ->selectRaw(...$this->metricSelects())
            ->groupBy('sbr.vendor_id')
            ->groupByRaw('COALESCE(g.type_id, 0)');

        // 前端會再依使用者點的欄位排序；這裡先給預設順序（下注金額由大到小）
        $rows = $grouped
            ->orderByDesc('valid_bet')
            ->get();

        $vendorNames = $this->vendorNames($rows->pluck('vendor_id')->all());
        $gameTypeNames = $this->gameTypeNames($rows->pluck('game_type_id')->all(), $languageId);

        return [
            'level' => 0,
            'list' => $rows->map(fn (object $row) => $this->formatRow($row, [
                'row_type' => 'vendor',
                'vendor_id' => (int) $row->vendor_id,
                'vendor_name' => $vendorNames[(int) $row->vendor_id] ?? null,
                'game_type_id' => (int) $row->game_type_id,
                'game_type_name' => $gameTypeNames[(int) $row->game_type_id] ?? null,
            ]))->all(),
            'summary' => $this->summaryOf($scoped),
            'time_basis' => $this->timezoneOf((int) $input['tz_offset']),
        ];
    }

    /**
     * 第 N 層：鎖定遊戲商＋遊戲類別，列出目標代理的直屬下級。
     *
     * 目標代理預設為登入者自己（第 1 層）；帶 `agent_id` 就是再往下一層。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function drillDown(
        Agent $viewer,
        array $input,
        string $start,
        string $end,
        int $vendorId,
        int $gameTypeId,
    ): array {
        $root = $this->drillRoot($viewer, $input);

        if ($root === null) {
            return $this->emptyLevel($input, (int) ($input['agent_id'] ?? $viewer->id));
        }

        [$targetId, $prefix, $username] = $root;

        // 直屬會員與直屬下級代理是兩種不同的分組方式，UNION 起來才能一起排序分頁
        // （先各自取回再用 PHP 排序切頁的話，代理底下會員一多就得整批撈回記憶體）
        $union = $this->directMemberRows($targetId, $start, $end, $vendorId, $gameTypeId)
            ->unionAll($this->childAgentRows($prefix, $start, $end, $vendorId, $gameTypeId));

        [$page, $perPage] = $this->pageOf($input);

        // 直屬會員全部排在前、下級代理在後，兩組內各自依下注金額由大到小（使用者
        // 2026-09-29 指定，2026-10-02 再次確認）；下鑽層不開放前端排序，這就是畫面的順序。
        // row_type 是 UNION 兩邊各自 SELECT 的常值，不含使用者輸入。
        // row_id 當最後的 tie-breaker：同金額時順序固定，翻頁才不會漏列或重複。
        // total_rows：COUNT(*) OVER() 在 LIMIT 之前算整層列數，與當頁同一次查詢帶出
        $rows = DB::query()
            ->fromSub($union, 'lvl')
            ->select('lvl.*')
            ->selectRaw('COUNT(*) OVER () as total_rows')
            ->orderByRaw("CASE lvl.row_type WHEN 'member' THEN 0 ELSE 1 END")
            ->orderByDesc('lvl.valid_bet')
            ->orderBy('lvl.row_id')
            ->forPage($page, $perPage)
            ->get();

        // 頁碼超出最後一頁時當頁沒有列、也就拿不到 total_rows，才另外數一次
        $total = $rows->isEmpty()
            ? ($page > 1 ? DB::query()->fromSub($union, 'lvl')->count() : 0)
            : (int) $rows->first()->total_rows;

        return [
            'level' => null,
            'agent_id' => $targetId,
            'agent_username' => $username,
            'list' => $this->formatLevelRows($rows),
            'summary' => $this->summaryOf(
                $this->subtreeBase($targetId, $prefix, $start, $end, $vendorId, $gameTypeId),
            ),
            'time_basis' => $this->timezoneOf((int) $input['tz_offset']),
            'pagination' => $this->pagination($total, $page, $perPage),
        ];
    }

    /**
     * 決定下鑽的起點。
     *
     * 🔴 起點必須與**主表的範疇同一個節點**，否則第 1 層的合計不會等於主表那一列
     * （需求文件的驗收核心）。踩過的坑：起點原本直接取登入者自己、主表卻用可視範疇，
     * 於是 Admin 的子帳號看主表有資料、下鑽卻從自己那個沒有下線的節點開始，
     * 第 1 層必定空白。兩邊現在共用 `scopePathOf()`。
     *
     * 依序決定：
     * 1. 有帶 `agent_id` → 那個代理（先過 `canSee()`）。
     * 2. 主表有「代理商帳號」篩選 → 那個代理（同樣先過 `canSee()`）。
     * 3. 都沒有 → 可視範疇那條線的根代理。
     *
     * 🔴 第 2 步踩過的坑：原本沒有這一步，起點一律落到可視範疇的根。主表已經依
     * `agent_username` 收斂到該代理那條線，第 1 層卻列出登入者的直屬下線、合計也是
     * 登入者整條線——使用者得再往下點好幾層才找得到自己查的代理，而且第 1 層的
     * 合計 ≠ 主表被點的那一列。起點必須跟 `scopedBase()` 的篩選是同一個節點。
     *
     * @param  array<string, mixed>  $input
     * @return array{0: int, 1: string, 2: string|null}|null [起點代理 id, 前綴, 帳號]
     */
    private function drillRoot(Agent $viewer, array $input): ?array
    {
        if (isset($input['agent_id'])) {
            return $this->visibleTarget($viewer, (int) $input['agent_id']);
        }

        if (($input['agent_username'] ?? '') !== '') {
            $filterAgentId = Agent::query()
                ->where('username', $input['agent_username'])
                ->value('id');

            // 查無此帳號就是查無資料，不退回可視範疇的根——那會回一組與主表對不起來的數字
            return $filterAgentId === null
                ? null
                : $this->visibleTarget($viewer, (int) $filterAgentId);
        }

        $scopePath = $this->scopePathOf($viewer);

        if ($scopePath === '') {
            // fail-closed：查不到階層就只看自己，不放寬成整站
            return [(int) $viewer->id, rtrim((string) $viewer->hierarchy_path, '/') . '/', $viewer->username];
        }

        $root = Agent::query()
            ->where('hierarchy_path', $scopePath)
            ->first(['id', 'username', 'hierarchy_path']);

        return $root === null
            ? null
            : [(int) $root->id, rtrim($scopePath, '/') . '/', $root->username];
    }

    /**
     * 登入者看得到的指定代理，當作下鑽起點。
     *
     * fail-closed：範疇外的代理一律當查無資料，不透露該代理存在。
     * 🔴 用本類別的 canSee() 而不是 Agent::canSeeAgent()：後者走
     * visibleScopePath()，對平台管理員的子帳號一律回 true，會讓這種帳號
     * 帶著別條線的 agent_id／agent_username 繞過主表的範疇收斂。
     *
     * @return array{0: int, 1: string, 2: string|null}|null [起點代理 id, 前綴, 帳號]
     */
    private function visibleTarget(Agent $viewer, int $targetId): ?array
    {
        if (!$this->canSee($viewer, $targetId)) {
            return null;
        }

        $target = Agent::query()
            ->whereKey($targetId)
            ->first(['id', 'username', 'hierarchy_path']);

        return $target === null
            ? null
            : [$targetId, rtrim((string) $target->hierarchy_path, '/') . '/', $target->username];
    }

    /**
     * 把 UNION 回來的列補上帳號並轉成輸出格式。
     *
     * 會員的帳號在 SQL 裡就取好了；代理列的 row_id 是 hierarchy_path 算出來的
     * 分桶值，要另外查一次 agents（一次 whereIn，不逐列查）。
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatLevelRows(Collection $rows): array
    {
        $agentIds = $rows->where('row_type', 'agent')->pluck('row_id')->all();
        $usernames = $agentIds === []
            ? []
            : Agent::query()->whereIn('id', $agentIds)->pluck('username', 'id')->all();

        return $rows->map(function (object $row) use ($usernames) {
            $isAgent = $row->row_type === 'agent';

            return $this->formatRow($row, [
                'row_type' => $row->row_type,
                'row_id' => (int) $row->row_id,
                'username' => $isAgent
                    ? ($usernames[(int) $row->row_id] ?? null)
                    : $row->username,
            ]);
        })->all();
    }

    /**
     * 目標代理的直屬會員，每人一列（葉節點）。
     *
     * 🔴 SELECT 的欄位順序必須與 `childAgentRows()` 完全一致：兩者要 UNION，
     * UNION 是照位置對欄不是照別名，順序一動就會把代理的數字接到會員欄位上。
     */
    private function directMemberRows(
        int $targetId,
        string $start,
        string $end,
        int $vendorId,
        int $gameTypeId,
    ): Builder {
        return $this->base($start, $end, $vendorId, $gameTypeId)
            ->where('m.parent', $targetId)
            ->selectRaw('sbr.member_id as row_id')
            ->selectRaw("'member' as row_type")
            ->selectRaw('ANY_VALUE(m.username) as username')
            // 會員列的人數恆為 1 無意義，回 NULL 讓前端顯示「—」
            ->selectRaw('NULL as player_count')
            ->selectRaw(...$this->metricSelects())
            ->groupBy('sbr.member_id');
    }

    /**
     * 目標代理的直屬下級代理，每個代理一列，數字含它整條下線。
     *
     * 分桶方式：會員掛在 `a`（= m.parent）底下，`a.hierarchy_path` 形如
     * `/1/100/200/300/400`；砍掉目標代理的前綴 `/1/100/200/` 後取第一段（`300`）
     * 就是該會員所屬的直屬下級代理。代理本身（path 剛好等於前綴＋自己的 id）
     * 取出來也是自己，同一條規則涵蓋。
     *
     * 🔴 前綴一律以「/」結尾當節點邊界，否則 `/1/10009` 會誤中 `/1/100092`。
     * 🔴 SELECT 的欄位順序必須與 `directMemberRows()` 完全一致，理由見該方法。
     * 代理帳號不在這裡查：分桶值是算出來的 id，取回後在 formatLevelRows() 一次補上。
     */
    private function childAgentRows(
        string $prefix,
        string $start,
        string $end,
        int $vendorId,
        int $gameTypeId,
    ): Builder {
        $bucket = 'CAST(SUBSTRING_INDEX(SUBSTRING(a.hierarchy_path, ?), ?, 1) AS UNSIGNED)';
        $bindings = [strlen($prefix) + 1, '/'];

        return $this->base($start, $end, $vendorId, $gameTypeId)
            ->where('a.hierarchy_path', 'like', $prefix . '%')
            ->selectRaw($bucket . ' as row_id', $bindings)
            ->selectRaw("'agent' as row_type")
            ->selectRaw('NULL as username')
            // 同一個會員只掛在一個代理底下，所以桶內 DISTINCT 相加仍是正確人數
            ->selectRaw('COUNT(DISTINCT sbr.member_id) as player_count')
            ->selectRaw(...$this->metricSelects())
            ->groupByRaw($bucket, $bindings);
    }

    /**
     * 登入者的可視範疇（`hierarchy_path`），比照 API_Agent 的既有慣例。
     *
     * 對照 `API_Agent/app/Http/Middleware/Authenticate.php` 設定 `HIERARCHY_PATH`
     * 那段：`host_id = 0` 取自己的，否則取**上級（`parent`）**且該上級 `status = 1`
     * 的 `hierarchy_path`。取 `parent` 不是 `host_id` 是刻意對齊舊版——子帳號的
     * 這兩個欄位實務上指向同一個代理，但慣例以 `parent` 為準。
     *
     * 🔴 **沒有「不限範疇」這條路**：平台管理員拿到的也是自己的 `/1`，因此看不到
     * 其他 `parent = 0` 的最上層代理。這與 APIS 既有的代理數據看板不同（那支對
     * `isPlatformAdmin()` 不設限），是本報表經確認後刻意對齊 API_Agent 的行為。
     *
     * 這裡刻意**不用** `Agent::visibleScopePath()`：那支的 `isPlatformAdmin()`
     * （`id == 1 || host_id == 1`）會在讀 `host_id` 之前就短路回 null，於是 Admin 的
     * 子帳號被當成平台端。那支方法還被 `canSeeAgent()` 以及簽到、活動V2、提款、
     * 代理數據看板共用，改它等於改掉那些功能的權限判定，不在本工單範圍。
     *
     * 回空字串代表查不到階層，由呼叫端 fail-closed 收斂成「只看自己」。
     */
    private function scopePathOf(Agent $viewer): string
    {
        if ($viewer->host_id == 0) {
            return (string) $viewer->hierarchy_path;
        }

        $parentPath = Agent::query()
            ->whereKey($viewer->parent)
            ->where('status', 1)
            ->value('hierarchy_path');

        // 上級停用或查無時退回自己那條線，不放寬
        return (string) ($parentPath ?? $viewer->hierarchy_path);
    }

    /**
     * 目標代理是否落在登入者的可視範疇內（fail-closed）。
     *
     * 比對以「/」為節點邊界，避免 `/1/10009` 誤中 `/1/100092`。
     */
    private function canSee(Agent $viewer, int $agentId): bool
    {
        $scopePath = $this->scopePathOf($viewer);

        if ($scopePath === '') {
            return $agentId === (int) $viewer->id;
        }

        $targetPath = Agent::query()->whereKey($agentId)->value('hierarchy_path');

        if ($targetPath === null) {
            return false;
        }

        return $targetPath === $scopePath
            || str_starts_with((string) $targetPath, rtrim($scopePath, '/') . '/');
    }

    /**
     * 主表的查詢底稿：期間內、登入者可視範疇內的下線投注。
     *
     * @param  array<string, mixed>  $input
     */
    private function scopedBase(Agent $viewer, array $input, string $start, string $end): Builder
    {
        // 查詢表單的遊戲商／類別篩選，兩者各自獨立、可只給一個。
        // 🔴 不可讀 vendor_id／game_type_id：那兩個是下鑽鍵，見 AgentGameDetailRequest
        $filterVendorId = isset($input['filter_vendor_id']) ? (int) $input['filter_vendor_id'] : null;
        $filterGameTypeId = isset($input['filter_game_type_id']) ? (int) $input['filter_game_type_id'] : null;

        $query = $this->base($start, $end, $filterVendorId, $filterGameTypeId);
        $scopePath = $this->scopePathOf($viewer);

        if ($scopePath === '') {
            // fail-closed：查不到階層就只看自己的直屬會員，不放寬成整站
            $query->where('m.parent', $viewer->id);
        } else {
            $prefix = rtrim($scopePath, '/') . '/';
            $query->where(function ($where) use ($scopePath, $prefix) {
                $where->where('a.hierarchy_path', $scopePath)
                    ->orWhere('a.hierarchy_path', 'like', $prefix . '%');
            });
        }

        // 需求文件「我方主動加」的代理商帳號篩選：收斂到該代理自己＋其下線
        if (($input['agent_username'] ?? '') !== '') {
            $query->whereExists(function ($exists) use ($input) {
                $exists->select(DB::raw(1))
                    ->from('agents as filter_agent')
                    ->where('filter_agent.username', $input['agent_username'])
                    ->whereRaw(
                        '(a.hierarchy_path = filter_agent.hierarchy_path
                          OR a.hierarchy_path LIKE CONCAT(filter_agent.hierarchy_path, ?))',
                        ['/%'],
                    );
            });
        }

        return $query;
    }

    /** 起點底下整條線（起點的直屬會員＋所有下級的會員），用於下鑽層的合計列 */
    private function subtreeBase(
        int $targetId,
        string $prefix,
        string $start,
        string $end,
        int $vendorId,
        int $gameTypeId,
    ): Builder {
        return $this->base($start, $end, $vendorId, $gameTypeId)
            ->where(function ($where) use ($targetId, $prefix) {
                $where->where('m.parent', $targetId)
                    ->orWhere('a.hierarchy_path', 'like', $prefix . '%');
            });
    }

    /**
     * 所有層共用的 FROM／JOIN／期間條件。
     *
     * `games` 走 leftJoin：彙總列的 game_id 可能是 0（來源沒給遊戲代碼），
     * 那些列的遊戲類別歸 0「未分類」，不能因為 join 不到就整列消失。
     */
    private function base(
        string $start,
        string $end,
        ?int $vendorId = null,
        ?int $gameTypeId = null,
    ): Builder {
        $query = DB::table('summary_bet_records as sbr')
            ->join('members as m', 'm.id', '=', 'sbr.member_id')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->leftJoin('games as g', 'g.id', '=', 'sbr.game_id')
            // summary_time 而非 data_time，理由見 class docblock
            ->whereBetween('sbr.summary_time', [$start, $end]);

        if ($vendorId !== null) {
            $query->where('sbr.vendor_id', $vendorId);
        }

        if ($gameTypeId !== null) {
            $query->whereRaw('COALESCE(g.type_id, 0) = ?', [$gameTypeId]);
        }

        return $query;
    }

    /**
     * 各層共用的金額聚合，回 `[SQL, bindings]` 供 `selectRaw(...)` 展開。
     *
     * `bet_amount` 與 `valid_bet` 取同一個欄位是 PM 確認過的取捨，理由見 class docblock。
     *
     * 派彩與玩家輸贏的 CASE 放在 SUM 內部逐列判斷，所以主表（跨遊戲商分組）、下鑽層
     * （鎖定單一遊戲商）與合計列（跨遊戲商）三種情境共用同一段 SQL 都正確。
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private function metricSelects(): array
    {
        // 口徑與 SR28 共用一份（4D 類 win_lose 即派彩的特例也在裡面）
        return SummaryBetRecord::betMetricSelects('sbr');
    }

    /**
     * 合計列。玩家人數另外算一次全域 DISTINCT，不是各列相加
     * （需求文件註記②：同一會員玩兩種類別只算一人）。
     *
     * @return array<string, mixed>
     */
    private function summaryOf(Builder $scoped): array
    {
        $row = (clone $scoped)
            ->selectRaw('COUNT(DISTINCT sbr.member_id) as player_count')
            ->selectRaw(...$this->metricSelects())
            ->first();

        return $row === null
            ? $this->zeroMetrics()
            : $this->formatRow($row, []);
    }

    /**
     * 一列的共用欄位。
     *
     * 🔴 DB 取出的 decimal 是字串、SUM 可能回 float，一律在**進入時**經
     * `Common::numAdd('0', …, 4)` 正規化再參與運算（CLAUDE.md 金流紅線：
     * 直接 `(string) $value` 會把 float 的精度誤差帶進來）。
     *
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function formatRow(object $row, array $extra): array
    {
        $memberWinLose = $this->normalize($row->member_win_lose ?? 0);

        return array_merge([
            'player_count' => isset($row->player_count) ? (int) $row->player_count : null,
            'bet_count' => (int) ($row->bet_count ?? 0),
            'bet_amount' => $this->normalize($row->bet_amount ?? 0),
            'valid_bet' => $this->normalize($row->valid_bet ?? 0),
            'payout' => $this->normalize($row->payout ?? 0),
            // 需求文件⑥：資料源尚未確定，要逐家遊戲商確認；先留版位回 null，
            // 前端顯示「—」。確認後把這兩個 key 換成真正的聚合即可，欄位不用改。
            'third_party_fee' => null,
            'company_fee' => null,
            'member_win_lose' => $memberWinLose,
            // 需求文件：公司輸贏恆為玩家輸贏的相反數，不含任何費用、本期不做淨額
            'company_win_lose' => Common::numMul($memberWinLose, '-1', self::SCALE),
        ], $extra);
    }

    /** 查無資料時的合計列：欄位形狀與有資料時完全一致，前端不必分支 */
    private function zeroMetrics(): array
    {
        return [
            'player_count' => 0,
            'bet_count' => 0,
            'bet_amount' => $this->normalize(0),
            'valid_bet' => $this->normalize(0),
            'payout' => $this->normalize(0),
            'third_party_fee' => null,
            'company_fee' => null,
            'member_win_lose' => $this->normalize(0),
            'company_win_lose' => $this->normalize(0),
        ];
    }

    /**
     * 範疇外或查無代理時的空層。
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function emptyLevel(array $input, int $targetId): array
    {
        return [
            'level' => null,
            'agent_id' => $targetId,
            'agent_username' => null,
            'list' => [],
            'summary' => $this->zeroMetrics(),
            'time_basis' => $this->timezoneOf((int) $input['tz_offset']),
            'pagination' => $this->pagination(0, 1, self::DEFAULT_PER_PAGE),
        ];
    }

    /**
     * 下鑽層的頁碼與單頁筆數。畫面下拉只給 10／20／50／100／500／1000，匯出會送一個大數字
     * 把整層一次取回（需求文件③「匯出當前所在層」），上限由 Request 驗證把關。
     *
     * @param  array<string, mixed>  $input
     * @return array{0: int, 1: int}
     */
    private function pageOf(array $input): array
    {
        return [
            max(1, (int) ($input['page'] ?? 1)),
            max(1, (int) ($input['per_page'] ?? self::DEFAULT_PER_PAGE)),
        ];
    }

    /** @return array<string, int> */
    private function pagination(int $total, int $page, int $perPage): array
    {
        return [
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
        ];
    }

    private function normalize(string|int|float $value): string
    {
        return Common::numAdd('0', $value, self::SCALE);
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function vendorNames(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));

        return $ids === []
            ? []
            : DB::table('vendors')->whereIn('id', $ids)->pluck('name', 'id')->all();
    }

    /**
     * 遊戲類別名稱依代理語系取，語系缺漏時退回 `game_types.name`
     * （與 SR27 RewardDistributionDetailsService 同一套作法）。
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, string>
     */
    private function gameTypeNames(array $ids, int $languageId): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids))));

        if ($ids === []) {
            return [];
        }

        return DB::table('game_types as gt')
            ->leftJoin('game_type_names as gtn', function ($join) use ($languageId) {
                $join->on('gtn.game_type_id', 'gt.id')->where('gtn.language_id', $languageId);
            })
            ->whereIn('gt.id', $ids)
            ->selectRaw("gt.id, COALESCE(NULLIF(gtn.name, ''), gt.name) as name")
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * 查詢區間：使用者所選時區的牆鐘 ⇒ UTC（DB 儲存基準）。
     *
     * 與代理數據看板（target/tickets#4180）同一套規則，時間解析只有一份。
     *
     * @param  array<string, mixed>  $input
     * @return array{0: string, 1: string}
     */
    private function range(array $input): array
    {
        $timezone = $this->timezoneOf((int) $input['tz_offset']);

        return [
            Carbon::parse($input['start_time'], $timezone)->utc()->format('Y-m-d H:i:s'),
            Carbon::parse($input['end_time'], $timezone)->utc()->format('Y-m-d H:i:s'),
        ];
    }

    /** 偏移小時 ⇒ `+08:00` / `-05:00` 形式（新後台只提供整數時區） */
    private function timezoneOf(int $offsetHours): string
    {
        return sprintf('%s%02d:00', $offsetHours < 0 ? '-' : '+', abs($offsetHours));
    }
}
