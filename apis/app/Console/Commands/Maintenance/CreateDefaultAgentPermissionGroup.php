<?php

declare(strict_types=1);

namespace App\Console\Commands\Maintenance;

use App\Models\AgentMenu;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 建立「預設角色」，取代前端寫死的新增營運商預設勾選權限。
 *
 * 【分類】Maintenance — 各白標建置後執行一次。⚠️ target/tickets#4543 起只要資料庫已有持有者專屬的預設角色
 * （op3168 每線預設、4543 總代預設、admin預設角色）就拒絕執行，因為它會把那些預設全部降級；要補總代預設改用
 * 由 admin 到角色管理替該總代新增「<總代帳號>預設角色」。
 *
 * ## 發生原因
 * 新增營運商時要自動勾上一組基本權限，原本寫死在前端
 * `agent-backend/apps/web-ele/src/views/agent/edit.vue` 的 `DEFAULT_PERMISSIONS`
 * （24 個 agent_menus.id）。寫死在前端有兩個問題：改一次要發版、且營運無法自行調整。
 * 工單4080 引入角色層後，改以一個全域角色承載這組權限。
 *
 * ## 為何用 code 而非原本的 id 清單
 * 前端寫死的是 **id**。六站實測那 24 個 id 確實都存在且 code 相同（皆為 id 1~39
 * 的早期基礎選單，六站同源），但 id 相同只是歷史巧合——後期各站分別新增的選單
 * id 已經分歧（如 4080 自己的選單在六站是 259/247/252/245/261/247）。
 * 以 code 對應可避免日後新站或選單重建時對到錯誤功能。
 *
 * 另外原清單中的 report_sr03／report_sr05／report_sr06（id 20/21/23）**六站皆已停用**，
 * 本命令只收 status=1 的選單，順帶清掉這三個無效授權。
 *
 * ## 建立內容
 * - 一個 `owner_agent_id = NULL` 的全域角色（不限線路，各線皆可套用）
 * - `default_setting = 1`，讓建立營運商時自動預選
 * - 權限＝下列 code 中 status=1 者
 * - **同時清掉其他角色的 `default_setting`**：預設角色同時只能有一個，
 *   與後台建立／編輯角色的行為一致
 *
 * ## 冪等性與重跑安全性
 * 以角色名稱查找既有資料：已存在則只補齊權限差集（不刪既有多勾的），不存在才建立；
 * 重跑會把該角色重新設回啟用＋預設、並把其他預設清掉——所以已有持有者專屬預設時直接 FAILURE（見上）。支援 `--dry-run` 預覽。
 *
 * ## 關聯
 * - 工單：target/tickets#4080
 * - 前端對應：`agent-backend` 的 `DEFAULT_PERMISSIONS`（本命令上線後移除）
 *
 * php artisan patch:create-default-agent-permission-group
 * php artisan patch:create-default-agent-permission-group --dry-run
 */
class CreateDefaultAgentPermissionGroup extends Command
{
    protected $signature = 'patch:create-default-agent-permission-group
        {--dry-run : 只預覽變更，不寫入資料庫}
        {--name=預設角色 : 角色名稱，用於識別既有資料}';

    protected $description = '建立預設角色，取代前端寫死的新增營運商預設權限 (工單4080)';

    /**
     * 前端 DEFAULT_PERMISSIONS 那 24 個 id 對應的選單 code。
     *
     * 順序比照原清單，便於與 `edit.vue` 的字串逐一核對。
     */
    private const DEFAULT_MENU_CODES = [
        'system_management',        // 1
        'agent_management',         // 4
        'agent',                    // 5
        'member_management',        // 7
        'member',                   // 8
        'statistics',               // 11
        'report_sr02',              // 19
        'report_sr03',              // 20 ← 六站皆 status=0，會被濾掉
        'report_sr05',              // 21 ← 六站皆 status=0，會被濾掉
        'report_sr06',              // 22
        'report_sr11_new',          // 23 ← 六站皆 status=0，會被濾掉
        'report_sr12',              // 24
        'unlimit',                  // 27
        'unlimit_search',           // 28
        'unlimit_record',           // 29
        'unlimit_agent_search',     // 32
        'unlimit_sr02',             // 33
        'unlimit_performance',      // 34
        'vendor_maintenances',      // 37
        'report_sr14',              // 39
        'report_sr07',              // 31
        'report_sr13',              // 38
        'reward_mode_management',   // 165
        'display_function_column',  // 168
    ];

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');
        $name = (string) $this->option('name');

        // 只收啟用中的選單：停用選單進不了選單樹，授出去也不會給到實權
        $menus = AgentMenu::query()
            ->whereIn('code', self::DEFAULT_MENU_CODES)
            ->where('status', 1)
            ->get(['id', 'code']);

        $foundCodes = $menus->pluck('code')->all();
        $missing = array_diff(self::DEFAULT_MENU_CODES, $foundCodes);

        $this->info("預設角色名稱：{$name}");
        $this->info('可用選單：' . $menus->count() . ' / ' . count(self::DEFAULT_MENU_CODES) . ' 個');

        if (!empty($missing)) {
            $this->warn('查無或已停用（略過）：' . implode(', ', $missing));
        }

        if ($menus->isEmpty()) {
            $this->error('查無任何可用選單，請確認 agent_menus 是否已建置');

            return self::FAILURE;
        }

        // target/tickets#4543 防呆：已有任何持有者專屬的預設角色（總代或 admin預設角色）就拒絕執行。
        // 本命令是 4080 的一次性升級，會把其他所有預設降級；4543 之後重跑等於清掉每個總代的預設
        $ownedDefaults = AgentPermissionGroup::query()->whereNotNull('owner_agent_id')->where('default_setting', 1)->count();
        if ($ownedDefaults > 0) {
            $this->error("已有 {$ownedDefaults} 個持有者專屬的預設角色（op3168 每線預設／4543 總代與 admin預設角色），本命令會把它們全部降級，拒絕執行。");
            $this->line('要替總代補預設角色請由 admin 到角色管理新增「<總代帳號>預設角色」並設為預設');

            return self::FAILURE;
        }

        // 只認 admin 持有（owner NULL）的同名角色：總代／一般代理自己取名「預設角色」的不能被認領成 admin 的兜底（4543）
        $group = AgentPermissionGroup::query()->whereNull('owner_agent_id')->where('name', $name)->first();
        $menuIds = $menus->pluck('id')->all();

        if ($isDryRun) {
            $this->line('');
            $this->comment('[dry-run] 不會寫入資料庫');
            $this->line($group
                ? "既有角色 id={$group->id}，將補齊權限至 " . count($menuIds) . ' 項'
                : '將建立新角色，並授予 ' . count($menuIds) . ' 項權限');
            $this->line('選單 id：' . implode(',', $menuIds));

            $others = AgentPermissionGroup::query()
                ->when($group, fn ($q) => $q->where('id', '!=', $group->id))
                ->where('default_setting', 1)
                ->get(['id', 'name']);

            foreach ($others as $o) {
                $this->line("將取消預設：id={$o->id} {$o->name}");
            }

            return self::SUCCESS;
        }

        DB::transaction(function () use (&$group, $name, $menuIds): void {
            if (!$group) {
                $group = AgentPermissionGroup::query()->create([
                    'name' => $name,
                    'owner_agent_id' => null,   // 全域角色，各線皆可套用
                    'remark' => '系統建立：新增營運商時的預設權限',
                    'status' => 1,
                    'default_setting' => 1,
                    'admin' => 0,
                ]);
            } else {
                // 既有角色也要確保是預設（重跑時可能已被改掉）
                $group->update(['default_setting' => 1, 'status' => 1]);
            }

            // 預設角色同時只能有一個（4080 的世界觀）：把其他角色的預設旗標清掉。
            // ⚠️ target/tickets#4543 起每個總代與 admin預設角色各有自己的預設（owner_agent_id 非 NULL），
            // 這一步會把它們全部降級，所以上面 handle() 一開始就擋下：已有任何持有者專屬預設就拒絕執行
            $demoted = AgentPermissionGroup::query()
                ->where('id', '!=', $group->id)
                ->where('default_setting', 1)
                ->update(['default_setting' => 0]);

            if ($demoted > 0) {
                $this->warn("已取消其他 {$demoted} 個角色的預設設定");
            }

            // 只補不刪：營運可能已依需求增減，重跑不該覆蓋他們的調整
            $existing = AgentPermissionGroupMenu::query()
                ->where('agent_permission_group_id', $group->id)
                ->pluck('agent_menu_id')
                ->all();

            $toAdd = array_diff($menuIds, $existing);

            foreach ($toAdd as $menuId) {
                AgentPermissionGroupMenu::query()->create([
                    'agent_permission_group_id' => $group->id,
                    'agent_menu_id' => $menuId,
                    'action' => '',
                ]);
            }

            $this->info("角色 id={$group->id}，新增 " . count($toAdd) . ' 項權限');
        });

        $total = AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $group->id)
            ->count();

        $this->info("完成，該角色目前共 {$total} 項權限");

        return self::SUCCESS;
    }
}
