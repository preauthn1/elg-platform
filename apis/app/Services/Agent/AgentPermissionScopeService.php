<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\RoleEnum;
use App\Models\Agent;
use App\Models\AgentMenu;
use App\Models\AgentPermission;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;

/**
 * 代理權限的共用規則（target/tickets#4422 自 API_Agent 搬入，新後台的權限接口全走這裡）。
 *
 * 必須與 API_Agent 的 AgentService／AgentRepository／Common 同名規則等價——舊後台仍走 API_Agent，
 * 兩邊讀寫同一批表（agent_permissions、agent_permission_groups(_menus)、agents.agent_permission_group_id）。
 * 規則調整一律兩邊同步，或先確認舊後台已不再使用該路徑。
 *
 * 名詞：
 * - 生效權限＝個人權限 ∪ 啟用中角色的權限（不過濾 agent_menus.status，與 API_Agent 一致）
 * - HOST＝操作者的主帳號（子帳號取 host_id，主帳號取自己），範疇判斷以它為準
 * - 所屬總代＝hierarchy_path 上「由近而遠」第一個 role_id=11 的代理（含自己）；系統帳號沒有
 * - 角色持有者＝agent_permission_groups.owner_agent_id（target/tickets#4543 起）：NULL＝admin 持有、
 *   1＝admin預設角色（限 admin 子帳號，4422）、其他＝建立者的主帳號 agents.id；誰持有誰才能套給下級
 */
class AgentPermissionScopeService
{
    /**
     * 會員銀行卡管理的兩個選單代碼（target/tickets#4486）：不能使用會員銀行卡個資的代理（{@see canAccessMemberBankAccount()}），
     * 自己的選單樹與權限地圖一律濾掉這兩項，存個人權限與角色時這兩項也不在其可增刪範圍內。一起處理，不留孤兒節點。
     */
    public const array MEMBER_BANK_ACCOUNT_MENU_CODES = ['member_bank_account', 'member_bank_account_edit_permission'];

    /**
     * 能使用會員銀行卡個資的角色 id（target/tickets#4486）：系統管理員、總代、客服。
     * 判斷一律走 {@see canAccessMemberBankAccount()}，不要各處另比 role_id。
     */
    private const array MEMBER_BANK_ACCOUNT_ROLE_IDS = [
        RoleEnum::ADMIN->value,
        RoleEnum::GENERAL_AGENT->value,
        RoleEnum::CUSTOMER_SERVICE->value,
    ];

    /** super admin 本人或其子帳號（API_Agent AgentRepository::isSuperAdminOrSubAccount） */
    public function isSuperAdminOrSubAccount(Agent $agent): bool
    {
        return $agent->id === AgentPermissionGroup::ADMIN_OWNER_ID
            || (int) $agent->host_id === AgentPermissionGroup::ADMIN_OWNER_ID;
    }

    /** 操作者的主帳號 id：子帳號取 host_id，主帳號取自己（API_Agent 的 $request->HOST） */
    public function hostId(Agent $agent): int
    {
        return (int) $agent->host_id !== 0 ? (int) $agent->host_id : $agent->id;
    }

    /** 主帳號是不是總代（role_id = 11）；子帳號看主帳號的 role_id（子帳號自己多半是 0） */
    public function isTopAgent(Agent $agent): bool
    {
        $hostId = $this->hostId($agent);
        $roleId = $hostId === $agent->id
            ? (int) $agent->role_id
            : (int) Agent::query()->whereKey($hostId)->value('role_id');

        return $roleId === RoleEnum::GENERAL_AGENT->value;
    }

    /**
     * 操作者建立角色時的持有者（target/tickets#4543）：誰建的就記誰，不吃請求帶的值。
     * super admin（含子帳號）回 null＝admin 持有；其他人是自己的主帳號。
     * admin 不能替別人建角色，也不能再新建 admin預設角色（1）——那一筆由系統建立（Max 2026-10-20）。
     */
    public function ownerIdForCreator(Agent $operator): ?int
    {
        return $this->isSuperAdminOrSubAccount($operator) ? null : $this->hostId($operator);
    }

    /**
     * 操作者能不能修改／停用這個角色（target/tickets#4543）：只有持有者本人（含子帳號）能改。
     * super admin（含子帳號）只能改 admin 持有（NULL）與 admin預設角色（1）；代理持有的只能檢視（Max 2026-10-20）。
     * NULL 與 1 對非 super admin 一律 false。
     */
    public function canEditGroup(Agent $operator, ?int $ownerAgentId): bool
    {
        if ($this->isSuperAdminOrSubAccount($operator)) {
            return empty($ownerAgentId) || AgentPermissionGroup::isAdminOwner($ownerAgentId);
        }

        return !empty($ownerAgentId) && (int) $ownerAgentId === $this->hostId($operator);
    }

    /**
     * 操作者能不能檢視這個角色（target/tickets#4543）：super admin 全部都能看（代理持有的只看不改）；
     * 其他人能改的都能看，總代另可看下線一般代理建的（只看不改）；一般代理只看自己建的。
     */
    public function canViewGroup(Agent $operator, ?int $ownerAgentId): bool
    {
        if ($this->isSuperAdminOrSubAccount($operator) || $this->canEditGroup($operator, $ownerAgentId)) {
            return true;
        }
        if (empty($ownerAgentId) || AgentPermissionGroup::isAdminOwner($ownerAgentId) || !$this->isTopAgent($operator)) {
            return false;
        }

        return $this->isInScope($this->hostId($operator), (int) $ownerAgentId);
    }

    /**
     * 能不能使用會員詳情抽屜（target/tickets#4438 第 7 項，PM 定案）：
     * 系統管理者（含子帳號）、總代理（含子帳號）、客服可用；其他身份（一般代理及其子帳號）不可用，
     * 帳號管理改走「功能」下拉。
     * 身份看 role_id：子帳號自己沒設（0）就看主帳號的（正式站總代的子帳號多半 role_id 為 0）。
     */
    public function canUseMemberDrawer(Agent $agent): bool
    {
        if ($this->isSuperAdminOrSubAccount($agent)) {
            return true;
        }

        $roleId = (int) $agent->role_id;
        if ($roleId === 0 && (int) $agent->host_id !== 0) {
            $roleId = (int) (Agent::query()->whereKey($agent->host_id)->value('role_id') ?? 0);
        }

        return in_array($roleId, [RoleEnum::GENERAL_AGENT->value, RoleEnum::CUSTOMER_SERVICE->value], true);
    }

    /**
     * 這個代理能不能使用會員銀行卡個資（target/tickets#4486）。
     *
     * 必須與 API_Agent Common::canAccessMemberBankAccount 同一口徑：只限 {@see MEMBER_BANK_ACCOUNT_ROLE_IDS}；
     * 子帳號（host_id != 0）看主帳號的角色（比照 #4498）；super admin（agents.id = 1）本人與其子帳號
     * （host_id = 1）一律允許；主帳號查無資料一律不允許。
     */
    public function canAccessMemberBankAccount(Agent $agent): bool
    {
        if ($this->isSuperAdminOrSubAccount($agent)) {
            return true;
        }

        $hostId = $this->hostId($agent);
        $roleId = $hostId === $agent->id
            ? (int) $agent->role_id
            : (int) Agent::query()->whereKey($hostId)->value('role_id');

        return in_array($roleId, self::MEMBER_BANK_ACCOUNT_ROLE_IDS, true);
    }

    /**
     * 會員銀行卡管理兩個選單（{@see MEMBER_BANK_ACCOUNT_MENU_CODES}）的 id（target/tickets#4486）。不過濾 status。
     *
     * @return array<int, int>
     */
    public function memberBankAccountMenuIds(): array
    {
        return AgentMenu::query()
            ->whereIn('code', self::MEMBER_BANK_ACCOUNT_MENU_CODES)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /**
     * 所屬總代：hierarchy_path（/1/33893/66829）上由近而遠第一個總代，含自己；找不到回 null。
     *
     * ⚠️ 不可改用 Agent::$general_agent_id：那個 accessor 取路徑上「任一個」總代（無排序），
     * 路徑上有兩個總代時會跟 API_Agent 的 getTopAgentId 不一致，造成線路判定分岔。
     */
    public function topAgentId(Agent $agent): ?int
    {
        $segments = array_values(array_filter(array_map('intval', explode('/', (string) $agent->hierarchy_path))));
        if ($segments === []) {
            return null;
        }

        $topIds = Agent::query()
            ->whereIn('id', $segments)
            ->where('role_id', RoleEnum::GENERAL_AGENT->value)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
        if ($topIds === []) {
            return null;
        }

        $topMap = array_flip($topIds);
        foreach (array_reverse($segments) as $id) {
            if (isset($topMap[$id])) {
                return $id;
            }
        }

        return null;
    }

    /**
     * 目標代理是否在 host 的範疇內（本人或下線）：API_Agent Common::isAgentInScope。
     * agents.hierarchy 格式為 [-1,自己,上層...,1,-2]，含自己，所以子帳號也算主帳號的下線。
     */
    public function isInScope(int $hostId, int $targetId): bool
    {
        if ($hostId <= 0 || $targetId <= 0) {
            return false;
        }
        if ($hostId === $targetId) {
            return true;
        }

        return Agent::query()
            ->whereKey($targetId)
            ->where('hierarchy', 'like', '%,' . $hostId . ',%')
            ->exists();
    }

    /**
     * 生效選單 id：個人 ∪（代理有綁角色且角色啟用時）角色。API_Agent AgentService::getEffectiveMenuIds。
     *
     * @return array<int, int>
     */
    public function effectiveMenuIds(int $agentId): array
    {
        $ids = AgentPermission::query()->where('agent_id', $agentId)->pluck('agent_menu_id')->all();

        $groupId = $this->activeGroupId($agentId);
        if ($groupId !== null) {
            $ids = array_merge(
                $ids,
                AgentPermissionGroupMenu::query()->where('agent_permission_group_id', $groupId)->pluck('agent_menu_id')->all(),
            );
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * 生效權限的 action 對照：個人與啟用中角色同一選單的 action 取聯集（逗號分隔、去空、去重）。
     * API_Agent AgentService::getEffectiveMenuActionMap。
     *
     * @return array<int, string> [agent_menu_id => 'view,update']
     */
    public function effectiveMenuActionMap(int $agentId): array
    {
        $map = [];
        $append = function (int $menuId, string $action) use (&$map): void {
            $parts = array_filter(explode(',', $action), static fn (string $part): bool => $part !== '');
            $map[$menuId] = array_values(array_unique(array_merge($map[$menuId] ?? [], $parts)));
        };

        foreach (AgentPermission::query()->where('agent_id', $agentId)->get(['agent_menu_id', 'action']) as $row) {
            $append((int) $row->agent_menu_id, (string) $row->action);
        }

        $groupId = $this->activeGroupId($agentId);
        if ($groupId !== null) {
            foreach (AgentPermissionGroupMenu::query()->where('agent_permission_group_id', $groupId)->get(['agent_menu_id', 'action']) as $row) {
                $append((int) $row->agent_menu_id, (string) $row->action);
            }
        }

        return array_map(static fn (array $parts): string => implode(',', $parts), $map);
    }

    /**
     * 操作者「可套用」的角色 id（API_Agent AgentService::getUsablePermissionGroupIds，兩邊必須同一套）。
     *
     * 持有者規則（target/tickets#4543，只取啟用中）：
     * - super admin（含子帳號）：admin 持有（owner NULL）＋ admin預設角色（owner 1；能不能套給目標另由 4422 判）
     * - 總代（含子帳號）：自己持有的（owner＝主帳號）
     * - 一般代理（含子帳號）：自己持有的 ＋ 所屬總代的預設角色（只有這一個）
     * 第三層以下看不到上層一般代理建的角色；admin 持有的角色總代與一般代理一律套不到。
     * 再過一道：角色權限必須是操作者生效權限的子集（super admin 也要過，空權限角色視為可用）。
     *
     * @return array<int, int>
     */
    public function usablePermissionGroupIds(Agent $operator): array
    {
        $groups = AgentPermissionGroup::query()->where('status', 1)->get(['id', 'owner_agent_id', 'default_setting']);

        $groups = $groups->filter(fn (AgentPermissionGroup $g): bool => $this->ownerRuleAllows($operator, $g));
        if ($groups->isEmpty()) {
            return [];
        }

        $own = array_flip($this->effectiveMenuIds($operator->id));
        $groupMenus = AgentPermissionGroupMenu::query()
            ->whereIn('agent_permission_group_id', $groups->pluck('id'))
            ->get(['agent_permission_group_id', 'agent_menu_id'])
            ->groupBy('agent_permission_group_id');

        $usable = [];
        foreach ($groups as $group) {
            $needed = $groupMenus->get($group->id)?->pluck('agent_menu_id')->all() ?? [];
            $isSubset = array_all($needed, static fn ($menuId): bool => isset($own[(int) $menuId]));
            if ($isSubset) {
                $usable[] = $group->id;
            }
        }

        return $usable;
    }

    /**
     * 持有者規則（4543）單獨抽出來：角色下拉要靠它分辨「套不到是因為持有者不同」還是「權限超出」。
     * super admin：admin 持有（NULL）或 admin預設角色（1）；總代：owner＝自己；一般代理：owner＝自己或所屬總代的預設。
     */
    public function ownerRuleAllows(Agent $operator, AgentPermissionGroup $group): bool
    {
        if ($this->isSuperAdminOrSubAccount($operator)) {
            return empty($group->owner_agent_id) || AgentPermissionGroup::isAdminOwner($group->owner_agent_id);
        }
        if (empty($group->owner_agent_id)) {
            return false;
        }
        if ((int) $group->owner_agent_id === $this->hostId($operator)) {
            return true;
        }
        if ($this->isTopAgent($operator)) {
            return false;
        }
        $topId = $this->topAgentId($operator);

        return $topId !== null && (int) $group->owner_agent_id === $topId && (int) $group->default_setting === 1;
    }

    /**
     * 只留存在且啟用的選單 id（轉 int、去重）。
     *
     * @param  array<int, int>  $menuIds
     * @return array<int, int>
     */
    public function activeMenuIds(array $menuIds): array
    {
        if ($menuIds === []) {
            return [];
        }

        return AgentMenu::query()
            ->whereIn('id', $menuIds)
            ->where('status', 1)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    /** 代理綁的角色 id；沒綁或角色停用回 null（停用角色不提供權限） */
    private function activeGroupId(int $agentId): ?int
    {
        $groupId = Agent::query()->whereKey($agentId)->value('agent_permission_group_id');
        if (empty($groupId)) {
            return null;
        }

        return AgentPermissionGroup::query()->whereKey($groupId)->where('status', 1)->exists() ? (int) $groupId : null;
    }
}
