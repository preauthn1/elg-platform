<?php

declare(strict_types=1);

namespace App\Console\Commands\Patch;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use App\Models\AgentPermissionGroupMigrationLog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 預設角色分線：每個總代各自擁有一個預設角色。
 *
 * 【分類】Patch — 六站各執行一次，可重跑。
 *
 * ## 發生原因
 * 角色層（工單4080）上線時只建了**一個全域預設角色**（`owner_agent_id = NULL`、
 * `default_setting = 1`），新增代理時無條件套用。但 VWorld 庫同時住著 vworldmy、
 * Acompany、wintoto、vworldsg 等多條營運線，共用同一份預設角色的後果是：
 * 某條線根本用不到的權限（實例：無限代六項，wintoto 總代自己都沒有）會被帶進
 * 該線子帳號，於是子帳號編輯下線時因「授出自己沒有的權限」被判提權而擋下（9003）。
 * 這正是 target/op_tickets#3168 的現場。
 *
 * ## 做什麼
 * 1. 每個總代（`agents.role_id = 11`）建立一個自己的預設角色，`owner_agent_id` 指向它，
 *    名稱為「<總代帳號>預設角色」。
 * 2. 新角色的權限**完整複製該站現行全域預設角色**——遷移後所有代理的生效權限零變動，
 *    各線之後再自行調整。⚠️ 各站權限數本來就不同（實測 TPower 12／Yes2Win 26／
 *    其餘四站 19），故一律以「當站的全域預設角色」為來源，不寫死任何清單。
 * 3. 把原本掛在全域預設角色的代理，改掛到它所屬總代的新角色。
 *
 * ## 歸屬判定：由近而遠取最近的總代
 * `hierarchy_path` 由遠而近（`/1/33893/66829`），**反轉後取第一個 `role_id = 11` 者**。
 * 必須如此，不可用單純的路徑前綴比對——正式庫存在巢狀總代（VWorld 的 Acompany
 * 掛在 vworldmy 底下、fongamer 與 tggame 掛在 gffusdt 底下），單純比對會讓同一個
 * 代理同時命中兩條線。
 *
 * 注意：不可改用 `Agent::general_agent_id` accessor——它是 `whereIn(...)->first()`，
 * 順序交給 MySQL 決定，巢狀總代下取到哪一個不保證。
 *
 * ## 不動的東西
 * - **全域預設角色保留不刪不改**，續當兜底：新總代尚未建立自己的預設角色時仍有退路。
 * - **孤兒帳號不動**：往上找不到任何總代者（直接掛在 Admin 底下，六站實測共 9 個）
 *   維持指向全域角色。
 * - 已經掛著「非全域預設角色」的代理不動：那是人工指定的，不該被補丁改掉。
 *
 * ## 冪等性與可逆性
 * **完全冪等**：以 `owner_agent_id` ＋名稱查找既有角色，已存在則只補權限差集；
 * 代理已掛在正確的目標角色即跳過。
 *
 * **可回滾**：每筆改掛都寫進 `agent_permission_group_migration_logs`，`--rollback`
 * 以批次為單位還原 `agent_permission_group_id`。新建的角色不會被回滾刪除——可能已有
 * 人工綁定，刪掉會讓那些代理失去角色；要清除請人工確認後再處理。
 *
 * ⚠️ **回滾只還原存量，不還原增量行為**：回滾後 DB 仍有各線的預設角色，若 API_Agent
 * 新版已部署，新建代理仍會掛到線路角色。要完全回到遷移前，得同時回退 API_Agent。
 *
 * **中斷後續跑**：逐總代一個交易，中斷時前面已完成的線保持已提交、後面的完全沒動。
 * 直接重跑是安全的（冪等），但會產生新的批次代碼，回滾時得兩個批次各跑一次。
 * 帶 `--batch=<原批次>` 續跑可讓異動集中在同一批，回滾一次到位。
 *
 * ## 執行順序：先部署 API_Agent，再跑本補丁
 * 反過來會開一段空窗：補丁跑完後 `default_setting = 1` 從 1 筆變 44 筆，而舊版的
 * `resolvePermissionGroupId()` 取法沒有 `ORDER BY`，MySQL 回哪一筆不保證——空窗期內
 * 建立的代理可能掛到別條線的預設角色，正是本單要消滅的跨線污染。
 *
 * 先部署程式則全程安全：此時 DB 仍只有一筆全域預設，新邏輯查不到線路角色會 fallback
 * 到全域，行為與現況完全相同。
 *
 * ## 關聯
 * - 工單：target/op_tickets#3168
 * - 前置：`patch:create-default-agent-permission-group`（建立全域預設角色，工單4080）
 *
 * ## --tops：只處理指定的總代
 * 一個庫裡的 `role_id = 11` 遠多於真正在營運的站台——VWorld 庫有 31 個總代，
 * 實際營運的線只有 Acompany／vworldsg／wintoto 三條，其餘多是測試線或早已停用的線。
 * 全建會留下一批永遠沒人綁的空角色，營運看到會來問「這是什麼、可不可以刪」。
 *
 * 給了名單就只建名單內的；不在名單內的總代**等同不存在**，其線下代理會繼續往上找，
 * 找不到就留在共用角色不動——這正是要的語意：沒有自己預設角色的線，行為維持現狀。
 *
 * 各站名單（2026-09-17 PM 定案）：
 *   VWorld     --tops=66829,49726,59457   Acompany／vworldsg／wintoto
 *   TPower     --tops=71541               T-POWER
 *   Yes2Win    --tops=100003              Yes2Win
 *   Alfawin77  --tops=100003              AlfaWin77
 *   Me77       --tops=100005              me77
 *   VPlus33    --tops=100006              VPLUS33
 *
 * 注意 togo（111620）與 77win（111557）不在名單內也不該加：它們的 role_id 非 11，
 * 且業務上依附 Acompany，其線下代理會被歸到 Acompany 的預設角色。
 *
 * php artisan patch:split-default-agent-permission-group --dry-run
 * php artisan patch:split-default-agent-permission-group --tops=66829,49726,59457 --dry-run
 * php artisan patch:split-default-agent-permission-group --tops=66829,49726,59457
 * php artisan patch:split-default-agent-permission-group --tops=... --batch=split_20260917082821
 * php artisan patch:split-default-agent-permission-group --rollback=<batch>
 */
class SplitDefaultAgentPermissionGroup extends Command
{
    protected $signature = 'patch:split-default-agent-permission-group
        {--tops= : 只處理這些總代（逗號分隔的 agents.id）；不給則處理全部 role_id=11}
        {--batch= : 沿用指定的批次代碼（中斷後續跑用，讓回滾能涵蓋前後兩段）}
        {--dry-run : 只預覽變更，不寫入資料庫}
        {--rollback= : 還原指定批次的代理角色綁定}';

    protected $description = '預設角色分線：每個總代各建一個預設角色，並把代理改掛到所屬線 (target/op_tickets#3168)';

    /** 角色名稱後綴，與 owner_agent_id 一併構成該線的預設角色識別 */
    private const NAME_SUFFIX = '預設角色';

    /** `agent_permission_groups.name` 的欄位上限，超過會被 DB 靜默截斷 */
    private const NAME_MAX_LENGTH = 50;

    public function handle(): int
    {
        if ($rollbackBatch = (string) $this->option('rollback')) {
            return $this->rollback($rollbackBatch);
        }

        $isDryRun = (bool) $this->option('dry-run');

        // 來源：當站的全域預設角色。它是權限內容的唯一依據，沒有它就無從複製
        $globalGroup = AgentPermissionGroup::query()
            ->whereNull('owner_agent_id')
            ->where('default_setting', 1)
            ->first();

        if (!$globalGroup) {
            $this->error('查無全域預設角色（owner_agent_id 為 NULL 且 default_setting = 1）');
            $this->line('請先執行 patch:create-default-agent-permission-group');

            return self::FAILURE;
        }

        $sourceMenuIds = AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $globalGroup->id)
            ->pluck('agent_menu_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $this->info("來源角色：id={$globalGroup->id} {$globalGroup->name}（" . count($sourceMenuIds) . ' 項權限）');

        $topAgents = $this->loadTopAgents();

        if ($topAgents === null) {
            return self::FAILURE;
        }

        // 待改掛的代理：目前掛在全域預設角色者。已掛其他角色的是人工指定，不動
        $agentsOnGlobal = Agent::query()
            ->where('agent_permission_group_id', $globalGroup->id)
            // agent_permission_group_id 必須一起撈：remapAgents 要拿它寫進備份表當回滾依據，
            // 漏掉會讓備份記成 null，回滾時把代理的角色清空而不是還原
            ->get(['id', 'username', 'hierarchy_path', 'agent_permission_group_id']);

        $this->info('掛在全域預設角色的代理：' . $agentsOnGlobal->count() . ' 個');

        // 先算歸屬，讓 dry-run 能完整預覽（含孤兒與壞資料）
        $orphans = [];
        $assignments = $this->classifyAgents($agentsOnGlobal, $topAgents, $orphans);

        if ($isDryRun) {
            return $this->preview($topAgents, $assignments, $sourceMenuIds);
        }

        // 遷移前快照：所有會被改掛的代理，其生效權限集合。
        // 這是「零變動」唯一有意義的驗證依據——跑完再算一次比對，不相等即為事故。
        $affectedIds = [];

        foreach ($assignments as $agents) {
            foreach ($agents as $agent) {
                $affectedIds[] = (int) $agent->id;
            }
        }

        $before = $this->snapshotEffectiveMenus($affectedIds);

        // 批次代碼可傳入：中途中斷後續跑時帶上同一個 --batch，回滾才涵蓋得到前後兩段。
        // 不傳則以當下時間新建一個。
        $batch = trim((string) $this->option('batch')) ?: 'split_' . now()->format('YmdHis');

        // 先印再跑：跑到一半炸掉時，例外會冒到 Console handler、跳過下面的收尾輸出，
        // 沒有批次代碼就無從回滾，只能自己去備份表撈
        $this->info("批次代碼：{$batch}（回滾用 --rollback={$batch}；中斷續跑用 --batch={$batch}）");

        $created = 0;
        $menusAdded = 0;
        $remapped = 0;

        foreach ($topAgents as $topAgent) {
            DB::transaction(function () use (
                $topAgent,
                $sourceMenuIds,
                $assignments,
                $batch,
                &$created,
                &$menusAdded,
                &$remapped
            ): void {
                $group = $this->ensureGroup($topAgent, $created);
                $menusAdded += $this->syncMenus($group->id, $sourceMenuIds);
                $remapped += $this->remapAgents($assignments[(int) $topAgent->id] ?? [], $group->id, $topAgent, $batch);
            });
        }

        $this->info("完成：新建角色 {$created} 個、補權限 {$menusAdded} 項、改掛代理 {$remapped} 個");

        $ok = $this->verify($globalGroup->id, $orphans, $before, $affectedIds, $agentsOnGlobal->count());

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * 各代理的生效權限集合（個人 ∪ 啟用中角色）。
     *
     * 照 API_Agent `AgentService::getEffectiveMenuIds()`：角色 status != 1 時不採計角色權限。
     * （寫這支補丁時 apis 的 `AgentMenuPermissionService` 還不檢查角色 status，故自算；
     * target/tickets#4422 起兩邊已一致。）
     *
     * @param  array<int>  $agentIds
     * @return array<int, string> agent_id => 排序後的選單 id 指紋
     */
    private function snapshotEffectiveMenus(array $agentIds): array
    {
        if ($agentIds === []) {
            return [];
        }

        $personal = DB::table('agent_permissions')
            ->whereIn('agent_id', $agentIds)
            ->select('agent_id', 'agent_menu_id')
            ->get();

        // 角色權限：只採計啟用中的角色，與 API_Agent 的判定一致
        $fromGroup = DB::table('agents as a')
            ->join('agent_permission_groups as g', function ($join): void {
                $join->on('g.id', '=', 'a.agent_permission_group_id')
                    ->where('g.status', 1);
            })
            ->join('agent_permission_group_menus as gm', 'gm.agent_permission_group_id', '=', 'g.id')
            ->whereIn('a.id', $agentIds)
            ->select('a.id as agent_id', 'gm.agent_menu_id')
            ->get();

        $map = [];

        foreach ([$personal, $fromGroup] as $rows) {
            foreach ($rows as $row) {
                $map[(int) $row->agent_id][(int) $row->agent_menu_id] = true;
            }
        }

        $result = [];

        foreach ($map as $agentId => $menuIds) {
            $ids = array_keys($menuIds);
            sort($ids);
            $result[$agentId] = implode(',', $ids);
        }

        return $result;
    }

    /**
     * 載入本次要處理的總代。
     *
     * `--tops` 限定名單：實務上一個庫裡的 `role_id = 11` 遠多於真正在營運的站台
     * （VWorld 庫 31 個總代，實際營運的線只有 Acompany／vworldsg／wintoto 三條），
     * 全建會留下一批永遠沒人綁的空角色。給了名單就只處理名單內的。
     *
     * @return null|Collection<int, Agent> null 代表參數有誤或查無，呼叫端應中止
     */
    private function loadTopAgents(): ?Collection
    {
        $onlyTopIds = $this->parseTopIds();

        if ($onlyTopIds === null) {
            return null;
        }

        $topAgents = Agent::query()
            ->where('role_id', RoleEnum::GENERAL_AGENT->value)
            ->when($onlyTopIds !== [], fn ($q) => $q->whereIn('id', $onlyTopIds))
            ->get(['id', 'username', 'hierarchy_path', 'status']);

        if ($topAgents->isEmpty()) {
            $this->error($onlyTopIds === []
                ? '查無任何總代（role_id = ' . RoleEnum::GENERAL_AGENT->value . '）'
                : '--tops 指定的 id 都不是總代（role_id = ' . RoleEnum::GENERAL_AGENT->value . '）');

            return null;
        }

        // 指定了卻沒全數命中：多半是 id 打錯或給錯站，不要默默少建一條線
        if ($onlyTopIds !== [] && $topAgents->count() !== count($onlyTopIds)) {
            $found = $topAgents->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $missing = array_diff($onlyTopIds, $found);

            $this->error('--tops 有 ' . count($missing) . ' 個 id 不是總代或不存在：' . implode(', ', $missing));

            return null;
        }

        $this->info('總代：' . $topAgents->count() . ' 個'
            . ($onlyTopIds === [] ? '（全部）' : '（--tops 限定）'));

        return $topAgents;
    }

    /**
     * 把待處理的代理依所屬總代分類，並就地回報孤兒與壞資料。
     *
     * @param  Collection<int, Agent>  $agentsOnGlobal  目前掛在全域預設角色的代理
     * @param  Collection<int, Agent>  $topAgents  本次要處理的總代
     * @param  array<Agent>  $orphans  依參照回填：往上查無總代者（維持不動）
     * @return array<int, array<Agent>> 總代 id => 該線要改掛的代理
     */
    private function classifyAgents(Collection $agentsOnGlobal, Collection $topAgents, ?array &$orphans): array
    {
        $topAgentIds = $topAgents->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $assignments = [];
        $orphans = [];
        $brokenPath = [];

        foreach ($agentsOnGlobal as $agent) {
            // hierarchy_path 為空＝資料損壞（見 target/op_tickets#3168 的 hierarchy 缺鏈案例），
            // 與「路徑完整但沒有總代祖先」的正常孤兒分開處理：前者應先修資料再遷移
            if (trim((string) $agent->hierarchy_path, '/') === '') {
                $brokenPath[] = $agent;

                continue;
            }

            $topId = $this->resolveTopAgentId($agent, $topAgentIds);

            if ($topId === null) {
                $orphans[] = $agent;

                continue;
            }

            $assignments[$topId][] = $agent;
        }

        if (!empty($brokenPath)) {
            $this->error('⚠️ hierarchy_path 為空 ' . count($brokenPath) . ' 個（資料損壞，不遷移，請先修資料）：'
                . implode(', ', array_map(fn (Agent $a): string => "{$a->username}(id={$a->id})", $brokenPath)));
        }

        if (!empty($orphans)) {
            $this->warn('孤兒帳號 ' . count($orphans) . ' 個（往上查無總代，維持指向全域角色）：'
                . implode(', ', array_map(fn (Agent $a): string => $a->username, $orphans)));
        }

        return $assignments;
    }

    /**
     * 解析 `--tops` 的總代 id 清單。
     *
     * @return null|array<int> 空陣列＝未指定（處理全部）；null＝格式錯誤，呼叫端應中止
     */
    private function parseTopIds(): ?array
    {
        $raw = trim((string) $this->option('tops'));

        if ($raw === '') {
            return [];
        }

        $ids = [];

        foreach (explode(',', $raw) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            // 不接受 "66829x" 這種：ctype_digit 擋掉後才轉型，避免 (int) 靜默截成 66829
            if (!ctype_digit($part)) {
                $this->error("--tops 含非數字的值：{$part}");

                return null;
            }

            $ids[] = (int) $part;
        }

        if ($ids === []) {
            $this->error('--tops 未指定任何有效的 id');

            return null;
        }

        return array_values(array_unique($ids));
    }

    /**
     * 由近而遠取最近的總代。
     *
     * `hierarchy_path` 形如 `/1/33893/66829`，由遠而近；反轉後第一個命中總代清單者即為所屬線。
     * 代理自己就是總代時，回傳自己。
     *
     * ⚠️ `$topAgentIds` 是**本次要處理的總代**，不是全部 role_id=11。用 `--tops` 限定時，
     * 不在名單內的總代等同不存在——它線下的代理會繼續往上找，找不到就留在共用角色。
     * 這正是要的語意：沒有自己預設角色的線，行為維持現狀。
     *
     * @param  array<int>  $topAgentIds
     */
    private function resolveTopAgentId(Agent $agent, array $topAgentIds): ?int
    {
        $path = (string) $agent->hierarchy_path;

        if ($path === '') {
            return null;
        }

        $segments = array_values(array_filter(
            array_map('intval', explode('/', trim($path, '/')))
        ));

        $topMap = array_flip($topAgentIds);

        foreach (array_reverse($segments) as $id) {
            if (isset($topMap[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * 取得或建立該總代的預設角色。
     *
     * 以 `owner_agent_id` ＋ `default_setting` 識別，**不以名稱查找**：某線可能自建過
     * 同名角色（`default_setting = 0`），以名稱認領會把營運自己配的權限改掉，
     * 違反「遷移後生效權限零變動」。撞到同名的另一筆一律中止，交人工處理。
     *
     * @throws RuntimeException 名稱超長或撞到既有同名角色
     */
    private function ensureGroup(Agent $topAgent, int &$created): AgentPermissionGroup
    {
        $name = $topAgent->username . self::NAME_SUFFIX;

        // 以 owner ＋ 名稱識別，**不把 default_setting 當查找條件**：
        // 前置補丁 patch:create-default-agent-permission-group 會把「其他角色」的
        // default_setting 一律清零（它的世界觀是全庫唯一預設）。若有人事後重跑它，
        // 本補丁建的角色會全部被降級；此時若還以 default_setting=1 查找就會查無、
        // 走建立分支、撞上下面的同名檢查，於是每條線都拋例外、補丁再也跑不動。
        // default_setting 是本補丁負責維持的**狀態**，不是識別條件。
        $group = AgentPermissionGroup::query()
            ->where('owner_agent_id', $topAgent->id)
            ->where('name', $name)
            ->first();

        if ($group) {
            // 重跑時把狀態修回來（可能被前置補丁降級，或被人工停用）
            $group->update(['default_setting' => 1, 'status' => 1]);

            return $group;
        }

        // agent_permission_groups.name 為 varchar(50)；非 strict mode 下會靜默截斷，
        // 兩個長帳號可能截成同名，故先擋下而不是讓 DB 自己處理
        if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw new RuntimeException(
                '角色名稱超過 ' . self::NAME_MAX_LENGTH . " 字元：{$name}（總代 id={$topAgent->id}）"
            );
        }

        // 同名但 owner 不是這個總代＝營運自建的角色，不可認領也不可撞名
        $conflict = $this->nameTakenByOthers($name, (int) $topAgent->id);

        if ($conflict) {
            throw new RuntimeException(
                "角色名稱已被占用：{$name}（既有 id={$conflict->id}、"
                . 'owner=' . ($conflict->owner_agent_id ?? 'NULL')
                . "、default={$conflict->default_setting}），請人工確認後再執行"
            );
        }

        $created++;

        return AgentPermissionGroup::query()->create([
            'name' => $name,
            'owner_agent_id' => $topAgent->id,
            'remark' => null,
            'status' => 1,
            'default_setting' => 1,
            'admin' => 0,
        ]);
    }

    /**
     * 這個名稱是否已被「別的總代或全域角色」占用。
     *
     * ensureGroup() 與 preview() 共用，確保 dry-run 預告的中止條件與正式執行一致。
     *
     * @return null|AgentPermissionGroup 撞名的那一筆；沒撞則為 null
     */
    private function nameTakenByOthers(string $name, int $ownerAgentId): ?AgentPermissionGroup
    {
        return AgentPermissionGroup::query()
            ->where('name', $name)
            ->where(fn ($q) => $q->whereNull('owner_agent_id')->orWhere('owner_agent_id', '!=', $ownerAgentId))
            ->first(['id', 'owner_agent_id', 'default_setting']);
    }

    /**
     * 補齊權限差集。
     *
     * 只補不刪：該線可能已自行增減過，重跑不該覆蓋他們的調整。
     *
     * @param  array<int>  $sourceMenuIds
     */
    private function syncMenus(int $groupId, array $sourceMenuIds): int
    {
        $existing = AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $groupId)
            ->pluck('agent_menu_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $toAdd = array_diff($sourceMenuIds, $existing);

        foreach ($toAdd as $menuId) {
            AgentPermissionGroupMenu::query()->create([
                'agent_permission_group_id' => $groupId,
                'agent_menu_id' => $menuId,
                'action' => '',
            ]);
        }

        return count($toAdd);
    }

    /**
     * 把該線的代理改掛到新角色，並逐筆寫備份。
     *
     * @param  array<Agent>  $agents
     */
    private function remapAgents(array $agents, int $toGroupId, Agent $topAgent, string $batch): int
    {
        $count = 0;

        foreach ($agents as $agent) {
            $fromGroupId = $agent->agent_permission_group_id === null
                ? null
                : (int) $agent->agent_permission_group_id;

            if ($fromGroupId === $toGroupId) {
                continue;
            }

            // 先寫備份再改值：改完才寫的話，中途失敗就失去還原依據
            AgentPermissionGroupMigrationLog::query()->create([
                'batch' => $batch,
                'agent_id' => $agent->id,
                'from_group_id' => $fromGroupId,
                'to_group_id' => $toGroupId,
                'top_agent_id' => $topAgent->id,
                'rolled_back' => 0,
            ]);

            Agent::query()
                ->where('id', $agent->id)
                ->update(['agent_permission_group_id' => $toGroupId]);

            $count++;
        }

        return $count;
    }

    /**
     * 預覽。
     *
     * @param  Collection<int, Agent>  $topAgents
     * @param  array<int, array<Agent>>  $assignments
     * @param  array<int>  $sourceMenuIds
     */
    private function preview(Collection $topAgents, array $assignments, array $sourceMenuIds): int
    {
        $this->line('');
        $this->comment('[dry-run] 不會寫入資料庫');

        $rows = [];

        foreach ($topAgents as $topAgent) {
            $name = $topAgent->username . self::NAME_SUFFIX;

            // 與 ensureGroup() 同一組查找條件，dry-run 才預告得準
            $existing = AgentPermissionGroup::query()
                ->where('owner_agent_id', $topAgent->id)
                ->where('name', $name)
                ->first(['id', 'name']);

            $agentCount = count($assignments[(int) $topAgent->id] ?? []);

            $note = '';

            if (mb_strlen($name) > self::NAME_MAX_LENGTH) {
                $note = '⚠️ 名稱超長，將中止';
            } elseif (!$existing && $this->nameTakenByOthers($name, (int) $topAgent->id)) {
                // 同名檢查也要在 dry-run 預告，否則會出現「dry-run 全綠、正式跑到一半炸掉」
                $note = '⚠️ 名稱已被占用，將中止';
            } elseif ($agentCount === 0) {
                // 巢狀外層總代（VWorld 的 vworldmy、gffusdt）因「取最近總代」規則可能零使用者，
                // 標示出來供判斷要不要建
                $note = '零使用者';
            }

            $rows[] = [
                $topAgent->id,
                $topAgent->username,
                $existing ? "已存在 id={$existing->id}" : "將建立 {$name}",
                $existing ? '補差集' : count($sourceMenuIds) . ' 項',
                $agentCount,
                $note,
            ];
        }

        $this->table(['總代 id', '總代帳號', '角色', '權限', '將改掛代理數', '備註'], $rows);

        return self::SUCCESS;
    }

    /** 回滾：以批次為單位還原 agent_permission_group_id */
    private function rollback(string $batch): int
    {
        $logs = AgentPermissionGroupMigrationLog::query()
            ->where('batch', $batch)
            ->where('rolled_back', 0)
            ->get();

        if ($logs->isEmpty()) {
            $this->error("批次 {$batch} 查無待回滾紀錄");

            return self::FAILURE;
        }

        // 其他掛在同一批角色上、但不屬於本批次的代理。
        //
        // ⚠️ 這裡**只告知不擋下**。早期版本擋了，實測會把回滾鎖死：
        //   - 補丁跑完後 API_Agent 會讓新代理自動掛上該線的預設角色（正是本單目的），
        //     六站營運中，跑完幾小時內必然出現「不在本批次」的綁定；
        //   - 中斷重跑會產生第二個批次，回滾第一批時第二批的代理也算「不在本批次」。
        // 兩者都是正常現象，卻會讓唯一的還原手段永久失效——回滾是六個正式庫的保險，
        // 不能因為可預期的正常情況就關掉它。
        //
        // 這些代理回滾後仍留在新角色上，屬正確行為：它們本來就不是補丁搬過去的。
        $targetGroupIds = $logs->pluck('to_group_id')->filter()->unique()->all();
        $migratedAgentIds = $logs->pluck('agent_id')->all();

        $others = Agent::query()
            ->whereIn('agent_permission_group_id', $targetGroupIds === [] ? [0] : $targetGroupIds)
            ->whereNotIn('id', $migratedAgentIds === [] ? [0] : $migratedAgentIds)
            ->get(['id', 'username', 'agent_permission_group_id']);

        if ($others->isNotEmpty()) {
            $this->warn('另有 ' . $others->count() . ' 筆綁定掛在同一批角色上，但不屬於本批次：');

            foreach ($others->take(10) as $agent) {
                $this->line("  id={$agent->id} {$agent->username} → group {$agent->agent_permission_group_id}");
            }

            if ($others->count() > 10) {
                $this->line('  …共 ' . $others->count() . ' 筆');
            }

            $this->warn('它們不是本批次搬過去的，回滾後會留在原地（正確行為）。');
        }

        $this->warn("即將還原 {$logs->count()} 筆代理角色綁定（批次 {$batch}）");

        if (!$this->confirm('確定執行？', false)) {
            $this->line('已取消');

            return self::SUCCESS;
        }

        $restored = 0;

        foreach ($logs as $log) {
            DB::transaction(function () use ($log, &$restored): void {
                Agent::query()
                    ->where('id', $log->agent_id)
                    ->update(['agent_permission_group_id' => $log->from_group_id]);

                $log->update(['rolled_back' => 1]);
                $restored++;
            });
        }

        $this->info("已還原 {$restored} 筆");
        $this->warn('新建的角色未刪除——可能已有人工綁定，請人工確認後再處理');

        return self::SUCCESS;
    }

    /**
     * 驗證：改掛後全域角色應只剩孤兒，且每個代理都掛在自己線的角色上。
     *
     * @param  array<Agent>  $orphans
     */
    private function verify(int $globalGroupId, array $orphans, array $before, array $affectedIds, int $beforeOnGlobal): bool
    {
        $ok = true;

        // ① 全域角色剩下的人數要等於「遷移前的人數 − 本次搬走的」。
        //
        // ⚠️ 預期值必須用**遷移前**算好的 $beforeOnGlobal，不能在遷移後現查。
        //    早期版本用 `whereNotIn($affectedIds)` 在遷移後現算，那是恆真式：
        //    搬成功的代理已不在全域角色上，whereNotIn 等於沒過濾，兩邊必然相等。
        $remaining = Agent::query()
            ->where('agent_permission_group_id', $globalGroupId)
            ->count();

        $expected = $beforeOnGlobal - count($affectedIds);

        if ($remaining === $expected) {
            $this->info("驗證通過：全域角色剩 {$remaining} 個代理（孤兒 " . count($orphans) . ' 個＋未遷移者）');
        } else {
            $this->error("驗證未過：全域角色剩 {$remaining} 個代理，預期 {$expected} 個");
            $ok = false;
        }

        // ② 歸屬正確性：每個被改掛的代理，其新角色的 owner 必須是它真正的上層總代。
        //
        // 這一項是 ③ 的盲點所在——所有線的權限都複製自同一個來源，掛錯線時
        // 「生效權限零變動」依然成立，只有比對 hierarchy 才抓得出來。
        if ($affectedIds !== []) {
            $wrongLine = DB::table('agents as a')
                ->join('agent_permission_groups as g', 'g.id', '=', 'a.agent_permission_group_id')
                ->join('agents as t', 't.id', '=', 'g.owner_agent_id')
                ->whereIn('a.id', $affectedIds)
                ->whereRaw("a.id <> t.id AND a.hierarchy_path NOT LIKE CONCAT(t.hierarchy_path, '/%')")
                ->count();

            if ($wrongLine === 0) {
                $this->info('驗證通過：' . count($affectedIds) . ' 個代理都掛在自己線的角色上');
            } else {
                $this->error("驗證未過：{$wrongLine} 個代理被掛到不屬於自己的線");
                $ok = false;
            }
        }

        // ③ 生效權限零變動——本補丁的核心承諾，不相等就是事故
        $after = $this->snapshotEffectiveMenus($affectedIds);
        $changed = [];

        foreach ($before as $agentId => $fingerprint) {
            if (($after[$agentId] ?? null) !== $fingerprint) {
                $changed[] = $agentId;
            }
        }

        // 反向：遷移後才冒出來的（理論上不該有，代理數不變）
        foreach (array_keys($after) as $agentId) {
            if (!array_key_exists($agentId, $before)) {
                $changed[] = $agentId;
            }
        }

        if ($changed === []) {
            $this->info('驗證通過：' . count($affectedIds) . ' 個代理的生效權限零變動');

            return $ok;
        }

        $this->error('驗證未過：' . count($changed) . ' 個代理的生效權限有變動，agent_id='
            . implode(',', array_slice($changed, 0, 20)) . (count($changed) > 20 ? ' …' : ''));
        $this->warn('請以 --rollback 還原後查明原因');

        return false;
    }
}
