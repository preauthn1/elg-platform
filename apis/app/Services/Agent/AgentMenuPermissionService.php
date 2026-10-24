<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\CacheKeys\CacheKeys;
use App\Enums\General\CacheTtl;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * 代理後台功能級授權（稽核 #13 / OWASP API5）。
 *
 * 代理最終權限 = 個人權限（`agent_permissions`）∪ 角色權限（`agent_permission_group_menus`），
 * 與工單 4080 的定義一致；此處只判斷「有沒有這個選單」，不細分 action。
 */
class AgentMenuPermissionService
{
    /**
     * 代理是否持有任一指定選單的權限。
     *
     * 傳入多個 code 時採「任一即可」：同一支 API 常被多個頁面共用（例如活動下拉
     * 被 join / recycle / reject 三個頁面共用），只要持有其中一頁的權限就該放行。
     *
     * @param  array<int, string>  $menuCodes  `agent_menus.code`
     */
    public function hasAny(Agent $agent, array $menuCodes): bool
    {
        // 平台端（admin 及其子帳號）不受選單權限限制，與範疇檢查的處置一致
        if ($agent->isPlatformAdmin()) {
            return true;
        }

        $menuIds = $this->resolveMenuIds($menuCodes);

        // 查無選單即放行（fail-open，沿用 API_Agent `Common::hasMenuPermission()` 的處置）：
        // 各白標的 agent_menus 內容不一致，擋下來會讓沒建該選單的白標整支端點不可用。
        // 代價是選單沒建＝沒防護，故留 log 供追蹤。
        if ($menuIds === []) {
            Log::warning('AgentMenuPermission: agent_menus 查無此 code，本次放行', [
                'codes' => implode(',', $menuCodes),
                'agent_id' => $agent->id,
            ]);

            return true;
        }

        $hasPersonal = AgentPermission::where('agent_id', $agent->id)
            ->whereIn('agent_menu_id', $menuIds)
            ->exists();

        if ($hasPersonal) {
            return true;
        }

        // 角色停用＝不採計角色權限（照 API_Agent `AgentService::getEffectiveMenuIds()`，與選單樹同一套規則）
        if (empty($agent->agent_permission_group_id)
            || !AgentPermissionGroup::query()->whereKey($agent->agent_permission_group_id)->where('status', 1)->exists()
        ) {
            return false;
        }

        return AgentPermissionGroupMenu::where('agent_permission_group_id', $agent->agent_permission_group_id)
            ->whereIn('agent_menu_id', $menuIds)
            ->exists();
    }

    /**
     * code ⇒ id；`agent_menus` 屬靜態主檔，整張對應表走長效快取，避免每個請求都查。
     *
     * @param  array<int, string>  $menuCodes
     * @return array<int, int>
     */
    private function resolveMenuIds(array $menuCodes): array
    {
        $map = Cache::safeRemember(
            CacheKeys::agentMenuCodeMap(),
            CacheTtl::ONE_HOUR->value,
            fn () => AgentMenu::where('status', 1)->pluck('id', 'code')->toArray(),
        );

        return array_values(array_intersect_key($map, array_flip($menuCodes)));
    }
}
