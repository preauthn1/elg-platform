<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Agent\RoleEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentPermissionGroup;
use App\Models\AgentPermissionGroupMenu;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 代理角色（權限群組）管理（target/tickets#4422 自 API_Agent AgentPermissionGroupController 搬入）。
 *
 * 規則來源與歷次工單：
 * - 4080 角色分線（4543 起改為「持有者」）：owner_agent_id＝建立者的主帳號；NULL／0＝admin 持有（只有 admin 能套，可套給任何人）
 * - op3168 每線一個預設角色、admin 持有的預設鎖死：不能新設、不能取消、不能搬去某條線；設預設時同持有者已有就擋下（不自動頂替）
 * - 4422 admin預設角色（owner_agent_id = 1）：限 super admin 子帳號套用；已有代理套用時不可在 admin 與其他間互換
 * - 4543 誰建立的角色只有誰能套給下級：總代／一般代理建的角色 owner 固定寫自己（不吃請求）；列表總代看自己＋下線建的
 *   （下線建的只能檢視）、一般代理只看自己建的；可套用範圍見 AgentPermissionScopeService::usablePermissionGroupIds()
 *
 * 必須與 API_Agent 版行為等價（舊後台仍走它）；錯誤檢查的先後順序也照抄，決定哪個錯誤先回。
 * 角色下拉帶 agent_id／parent 時兩邊都檢查目標在操作者範疇內（4543 起 API_Agent 也補上）。
 * 角色的選單必須在操作者自己的生效權限內（4543；super admin 不限），角色既有但操作者看不到的選單照舊保留。
 *
 * 選單樹不快取（apis 一律即時算），所以這裡改角色不用清快取；舊後台吃的是 API_Agent 的 1 小時快取，
 * 新後台改角色後舊後台的選單可能延遲，客戶已全面導向新後台，PM 接受（2026-10-05）。
 */
class AgentPermissionGroupService
{
    private const int DEFAULT_PER_PAGE = 25;

    private const int MAX_PER_PAGE = 100;

    /** admin 角色前提缺項（4498）：建立新帳號時一律不可選 admin 角色，語系鍵 admin_requires_new_account */
    private const string ADMIN_REQUIREMENT_NEW_ACCOUNT = 'new_account';

    public function __construct(
        private readonly AgentPermissionScopeService $scope,
    ) {}

    /**
     * 角色列表（target/tickets#4543）。super admin（含子帳號）看全部，可依持有者篩（0＝只看 admin 持有）；
     * 總代看自己持有的＋下線一般代理建的（can_edit=false，只能檢視）；一般代理只看自己持有的。
     * admin 持有（NULL）與 admin預設角色（1）對總代、一般代理一律不列。
     *
     * @param  array{name?: ?string, status?: ?int, owner_agent_id?: ?int, per_page?: ?int, page?: ?int}  $filters
     * @return LengthAwarePaginator<int, mixed>
     */
    public function list(Agent $operator, array $filters): LengthAwarePaginator
    {
        $query = AgentPermissionGroup::query()->withCount('agents');

        // 依所屬代理篩選（super admin 任選；總代在自己＋下線建的範圍內篩，下面的範疇條件照樣成立，篩不出範圍外的）
        $owner = $filters['owner_agent_id'] ?? null;
        if ($owner !== null) {
            $query->where(function ($q) use ($owner) {
                $owner === 0
                    ? $q->whereNull('owner_agent_id')->orWhere('owner_agent_id', 0)
                    : $q->where('owner_agent_id', $owner);
            });
        }

        if (!$this->scope->isSuperAdminOrSubAccount($operator)) {
            $hostId = $this->scope->hostId($operator);
            $query->where(function ($q) use ($operator, $hostId) {
                $q->where('owner_agent_id', $hostId);
                if ($this->scope->isTopAgent($operator)) {
                    // 下線建的：持有者在自己範疇內（agents.hierarchy 含自己），不含 admin（1）與 NULL
                    $q->orWhereIn('owner_agent_id', Agent::query()
                        ->select('id')
                        ->whereKeyNot($hostId)
                        ->where('hierarchy', 'like', '%,' . $hostId . ',%'));
                }
            });
        }

        if (($filters['name'] ?? '') !== '') {
            $query->where('name', 'like', '%' . $filters['name'] . '%');
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('status', (int) $filters['status']);
        }

        // 依所屬代理分組：admin預設角色（1）→ admin 持有（NULL）→ 各持有者；組內預設角色在前、再依 id 新到舊（Max 2026-10-20）
        $paginator = $query
            ->orderByRaw('(owner_agent_id <=> ?) DESC', [AgentPermissionGroup::ADMIN_OWNER_ID])
            ->orderByRaw('(owner_agent_id IS NULL) DESC')
            ->orderBy('owner_agent_id')
            ->orderByDesc('default_setting')
            ->orderByDesc('id')
            ->paginate(
                perPage: $this->perPage($filters['per_page'] ?? null),
                page: (int) ($filters['page'] ?? 1),
            );

        $ownerNames = $this->ownerNames($paginator->getCollection()->pluck('owner_agent_id')->all());

        return $paginator->through(fn (AgentPermissionGroup $g): array => [
            'id' => $g->id,
            'name' => $g->name,
            'owner_agent_id' => $g->owner_agent_id,
            'owner_username' => $this->ownerLabel($g->owner_agent_id, $ownerNames),
            'remark' => $g->remark,
            'status' => (int) $g->status,
            'default_setting' => (int) $g->default_setting,
            'agents_count' => (int) $g->agents_count,
            'admin' => $g->admin,
            // 4543：總代列表裡下線建的角色只能檢視，前端據此把「編輯」換成「檢視」、鎖狀態開關
            'can_edit' => $this->scope->canEditGroup($operator, $g->owner_agent_id),
            'created_at' => $g->created_at?->toBackendTime()->toDateTimeString(),
            'updated_at' => $g->updated_at?->toBackendTime()->toDateTimeString(),
        ]);
    }

    /**
     * 角色詳情（含勾選的選單 id 與各自 action）。
     *
     * @return array<string, mixed>
     */
    public function view(Agent $operator, int $id): array
    {
        $group = $this->findOrFail($id);
        $this->assertViewScope($operator, $group->owner_agent_id);

        $menus = AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $group->id)
            ->get(['agent_menu_id', 'action']);

        return [
            'id' => $group->id,
            'name' => $group->name,
            'owner_agent_id' => $group->owner_agent_id,
            'owner_username' => $this->ownerLabel($group->owner_agent_id, $this->ownerNames([$group->owner_agent_id])),
            'remark' => $group->remark,
            'status' => (int) $group->status,
            'default_setting' => (int) $group->default_setting,
            'can_edit' => $this->scope->canEditGroup($operator, $group->owner_agent_id),
            'menus' => $menus->pluck('agent_menu_id')->map(fn ($v) => (int) $v)->values()->all(),
            'menu_actions' => $menus->pluck('action', 'agent_menu_id')->all(),
        ];
    }

    /**
     * 新增角色。
     *
     * @param  array{name: string, owner_agent_id?: ?int, remark?: ?string, status: int, default_setting?: ?int, menus: array<mixed>}  $input
     */
    public function create(Agent $operator, array $input, bool $hasDefaultSetting): int
    {
        $menuIds = $this->restrictMemberBankAccountMenus($operator, $this->normalizeMenuIds($input['menus']), null);
        $this->assertMenusWithinOperatorPermissions($operator, $menuIds, null);
        $ownerId = $this->resolveOwnerAgentId($operator, $input['owner_agent_id'] ?? null);

        $isDefault = $hasDefaultSetting && (int) ($input['default_setting'] ?? 0) === 1;

        return DB::transaction(function () use ($operator, $input, $menuIds, $ownerId, $isDefault): int {
            if ($isDefault) {
                $this->assertDefaultSettable($ownerId, null);
            }
            $group = AgentPermissionGroup::query()->create([
                'name' => $input['name'],
                'owner_agent_id' => $ownerId,
                'remark' => $input['remark'] ?? null,
                // 預設角色恆為啟用：新增營運商要靠它帶出預設權限
                'status' => $isDefault ? 1 : (int) $input['status'],
                'default_setting' => $isDefault ? 1 : 0,
                'admin' => $operator->id,
            ]);
            $this->syncGroupMenus($group->id, $menuIds, $operator->id);

            return $group->id;
        });
    }

    /**
     * 修改角色。檢查順序照 API_Agent：持有者範疇 → 持有者不可改 → admin 持有的預設不得取消 → 預設可設定
     * → 選單子集（交易內、銀行卡剔除／保留之後，4543）。
     * 持有者建立後就固定（4543，Max 2026-10-20：誰建的就記誰），請求帶了不同的值一律 9003；原本 4422 的
     * 「admin預設角色與其他互換」、「admin 持有的預設不得搬家」兩道檢查因此不會再發生，一併拿掉。
     *
     * @param  array{id: int, name: string, owner_agent_id?: ?int, remark?: ?string, status: int, default_setting?: ?int, menus: array<mixed>}  $input
     */
    public function update(Agent $operator, array $input, bool $hasOwner, bool $hasDefaultSetting): void
    {
        $group = $this->findOrFail((int) $input['id']);
        $this->assertEditScope($operator, $group->owner_agent_id);

        $menuIds = $this->normalizeMenuIds($input['menus']);
        // 持有者不可改：沒帶就沿用；帶了必須等於原值（前端編輯時會原樣送回）
        if ($hasOwner && (int) ($input['owner_agent_id'] ?? 0) !== (int) $group->owner_agent_id) {
            throw new ApiException(__('error_message.9003'));
        }
        $ownerId = $group->owner_agent_id;
        // 未帶 default_setting 時維持原值
        $isDefault = $hasDefaultSetting ? (int) ($input['default_setting'] ?? 0) === 1 : (int) $group->default_setting === 1;
        $wasDefault = (int) $group->default_setting === 1;

        // admin 持有的預設任何人都不得取消；各持有者的預設可取消（才走得通「先關舊的再設新的」）
        if ($wasDefault && !$isDefault && empty($group->owner_agent_id)) {
            throw new ApiException(__('error_message.9003'));
        }

        DB::transaction(function () use ($operator, $input, $group, $menuIds, $ownerId, $isDefault, $wasDefault): void {
            // 新設為預設：同一持有者底下必須還沒有預設（不自動頂替）
            if ($isDefault && !$wasDefault) {
                $this->assertDefaultSettable($ownerId, $group->id);
            }
            $group->update([
                'name' => $input['name'],
                'remark' => $input['remark'] ?? null,
                'status' => $isDefault ? 1 : (int) $input['status'],
                'default_setting' => $isDefault ? 1 : 0,
                'admin' => $operator->id,
            ]);
            $menuIds = $this->restrictMemberBankAccountMenus($operator, $menuIds, $group->id);
            // 銀行卡兩項剔除／保留後才檢查子集（4543），剔除前的那兩項不算操作者在越權
            $this->assertMenusWithinOperatorPermissions($operator, $menuIds, $group->id);
            $this->syncGroupMenus($group->id, $menuIds, $operator->id);
        });
    }

    /** 啟用／停用。預設角色不得停用（新增營運商要靠它帶出預設權限）；狀態沒變視為失敗 */
    public function status(Agent $operator, int $id, int $status): void
    {
        $group = $this->findOrFail($id);
        $this->assertEditScope($operator, $group->owner_agent_id);

        if ((int) $group->default_setting === 1 && $status === 0) {
            throw new ApiException(__('error_message.9003'));
        }
        if ((int) $group->status === $status) {
            throw new ApiException(__('error_message.agent_permission_group.status_unchanged'));
        }

        $group->update(['status' => $status, 'admin' => $operator->id]);
    }

    /**
     * 掛在角色底下的代理。非 super admin 只列自己範疇內的（主帳號本人＋其整條下線，4543）。
     *
     * @return LengthAwarePaginator<int, mixed>
     */
    public function agents(Agent $operator, int $id, ?int $perPage, ?int $page): LengthAwarePaginator
    {
        $group = $this->findOrFail($id);
        $this->assertViewScope($operator, $group->owner_agent_id);

        $query = Agent::query()->where('agent_permission_group_id', $group->id);

        if (!$this->scope->isSuperAdminOrSubAccount($operator)) {
            // 4543：只列操作者自己範疇內的（本人＋整條下線），不再以所屬總代的線為界
            $hostId = $this->scope->hostId($operator);
            $query->where(fn ($q) => $q->where('hierarchy', 'like', '%,' . $hostId . ',%')->orWhere('id', $hostId));
        }

        $paginator = $query->orderByDesc('id')
            ->select(['id', 'username', 'status', 'parent', 'hierarchy_path'])
            ->paginate(perPage: $this->perPage($perPage), page: $page ?? 1);

        $parentNames = Agent::query()
            ->whereIn('id', $paginator->getCollection()->pluck('parent')->filter()->unique()->all())
            ->pluck('username', 'id');

        return $paginator->through(fn (Agent $a): array => [
            'id' => $a->id,
            'username' => $a->username,
            'status' => (int) $a->status,
            'parent' => $a->parent,
            'parent_username' => $parentNames[$a->parent] ?? null,
            'hierarchy_path' => $a->hierarchy_path,
        ]);
    }

    /**
     * 建立／編輯營運商的角色下拉：操作者可用（持有者規則＋權限子集，4543）× 目標帳號規則（4422）。
     * 帶 agent_id 時補回該代理已綁但本次不可用的角色（assignable=false、不開放改選；status 照實回，只有角色停用才是 0），
     * 避免下拉空白讓存檔靜默清掉綁定；
     * 它的 menus 照實回（4543）：前端靠這份知道哪些勾選來自角色、存檔時才扣得掉，回空會把角色權限整包寫進個人權限。
     * 但角色本身停用（status 0）的回空：停用角色不提供權限，回了會讓前端把代理個人權限裡重疊的項目當角色給的扣掉。
     * 補回的那筆一併回 assignable=false＋unassignable_reason（停用／持有者不同／權限超出），前端顯示「角色名（原因）」；
     * API_Agent 版下拉只帶 assignable、不帶原因文字（舊後台沒有對應畫面），是刻意的差異。
     * 目標（agent_id 或新增時的 parent）必須在操作者範疇內，否則 9003；super admin 不限。
     *
     * @return array<int, array<string, mixed>>
     */
    public function dropdown(Agent $operator, ?int $agentId, ?int $isSubAccount, ?int $parent): array
    {
        if (!$this->scope->isSuperAdminOrSubAccount($operator)) {
            $hostId = $this->scope->hostId($operator);
            foreach ([$agentId, $parent] as $targetId) {
                if ($targetId !== null && !$this->scope->isInScope($hostId, $targetId)) {
                    throw new ApiException(__('error_message.9003'));
                }
            }
        }

        $usableIds = $this->scope->usablePermissionGroupIds($operator);
        $targetHostId = $this->resolveTargetHostId($operator, $agentId, $isSubAccount, $parent);
        $targetLine = $this->resolveTargetLine($operator, $agentId, $parent);
        $isAssignable = fn (AgentPermissionGroup $g): bool => AgentPermissionGroup::isAllowedForTargetHost($g->owner_agent_id, $targetHostId)
            && $this->isInTargetLine($g->owner_agent_id, $targetLine);

        // 持有者非空者在前、預設在前，前端取第一個預設即預選到自己持有（含 admin預設角色）的預設
        $groups = AgentPermissionGroup::query()
            ->where('status', 1)
            ->whereIn('id', $usableIds)
            ->orderByRaw('(owner_agent_id IS NULL) ASC')
            ->orderByDesc('default_setting')
            ->orderBy('id')
            ->get(['id', 'name', 'owner_agent_id', 'default_setting', 'status'])
            ->filter($isAssignable)
            ->values();

        if ($agentId !== null) {
            $boundId = Agent::query()->whereKey($agentId)->value('agent_permission_group_id');
            if (!empty($boundId) && !$groups->contains('id', $boundId)) {
                $bound = AgentPermissionGroup::query()->find($boundId, ['id', 'name', 'owner_agent_id', 'default_setting', 'status']);
                if ($bound !== null) {
                    $groups->push($bound);
                }
            }
        }

        $menuMap = AgentPermissionGroupMenu::query()
            ->whereIn('agent_permission_group_id', $groups->pluck('id'))
            ->get(['agent_permission_group_id', 'agent_menu_id'])
            ->groupBy('agent_permission_group_id');
        $usableMap = array_flip($usableIds);
        $adminMissing = $this->adminRoleMissingRequirements($agentId);

        return $groups->map(function (AgentPermissionGroup $g) use ($operator, $menuMap, $usableMap, $isAssignable, $adminMissing): array {
            $isUsable = isset($usableMap[$g->id]) && $isAssignable($g);
            $missing = AgentPermissionGroup::isAdminOwner($g->owner_agent_id) ? $adminMissing : [];
            $unusableReason = $isUsable ? null : $this->unusableReason($operator, $g, $isAssignable);

            return [
                'id' => $g->id,
                'name' => $g->name,
                'owner_agent_id' => empty($g->owner_agent_id) ? null : (int) $g->owner_agent_id,
                'default_setting' => (int) $g->default_setting,
                // 照角色實際狀態回：只有角色本身停用才是 0。不可用的既有綁定靠 assignable=false 反灰、
                // 由 unassignable_reason 說明原因；回 0 會讓前端一律顯示「（停用）」把原因蓋掉（4543）
                'status' => (int) $g->status,
                // admin 角色前提（4498）：目標帳號未開 2FA 或白名單為空時不可選，前端反灰並顯示缺哪一項；
                // 既有已綁的角色照樣列出（status 不動），只是不開放「改選成它」
                'assignable' => $missing === [] && $unusableReason === null,
                'unassignable_reason' => $unusableReason !== null
                    ? __('error_message.agent_permission_group.unusable_' . $unusableReason)
                    : ($missing === [] ? null : implode('／', array_map(
                        fn (string $key): string => __('error_message.agent_permission_group.admin_requires_' . $key),
                        $missing,
                    ))),
                'menus' => (int) $g->status === 1
                    ? ($menuMap->get($g->id)?->pluck('agent_menu_id')->map(fn ($v) => (int) $v)->values()->all() ?? [])
                    : [],
            ];
        })->values()->all();
    }

    /**
     * 補回的「已綁但本次不可用」角色，不可用的原因（4543）：停用 → 持有者不同（含 admin預設角色套不到目標）→ 權限超出。
     * 鍵對應語系 error_message.agent_permission_group.unusable_*。
     *
     * @param  callable(AgentPermissionGroup): bool  $isAssignable
     */
    private function unusableReason(Agent $operator, AgentPermissionGroup $group, callable $isAssignable): string
    {
        if ((int) $group->status !== 1) {
            return 'disabled';
        }
        if (!$this->scope->ownerRuleAllows($operator, $group) || !$isAssignable($group)) {
            return 'not_owned';
        }

        return 'menus_exceed';
    }

    /**
     * 角色下拉用：目標帳號套用 admin 角色還缺什麼（target/tickets#4498）。
     * 沒帶 agent_id＝建立新帳號，一律不可選（新帳號不可能已開 2FA，要先用其他角色建立、本人開 2FA 並設白名單後再改）；
     * 帶了就看該帳號**目前**存檔的 has_2fa 與 login_ip_whitelist。
     *
     * @return list<string>
     */
    private function adminRoleMissingRequirements(?int $agentId): array
    {
        if ($agentId === null) {
            return [self::ADMIN_REQUIREMENT_NEW_ACCOUNT];
        }

        $target = Agent::query()->find($agentId, ['id', 'has_2fa', 'login_ip_whitelist']);
        if ($target === null) {
            return [self::ADMIN_REQUIREMENT_NEW_ACCOUNT];
        }

        return AgentPermissionGroup::adminRoleMissingRequirements((int) $target->has_2fa, $target->login_ip_whitelist);
    }

    /**
     * 所屬代理（持有者）選項與角色頁面的操作開關（4543）：
     * - owners：super admin 列 admin（子帳號專用）＋全部總代；總代列自己＋有建角色的下線；一般代理只有自己。
     *   用來顯示名稱與列表篩選
     * - can_select：建立／編輯角色時能不能選持有者，恆為 false（誰建的就記誰，admin 建的記 admin 持有，Max 2026-10-20）
     * - can_filter：列表能不能依所屬代理篩選，super admin 與總代（看得到多個持有者的角色；工單示意圖總代列表有此篩選）
     * - can_set_default：能不能把角色設為預設，只有總代（含子帳號）；與 assertDefaultSettable 同一條規則，
     *   一般代理的預設永遠不會被自動套用，admin 建的角色是 admin 持有、共用預設只留系統那一筆
     *
     * @return array{can_select: bool, can_filter: bool, can_set_default: bool, owners: array<int, array{id: int, username: string, is_admin: bool}>}
     */
    public function ownerDropdown(Agent $operator): array
    {
        $isSuperAdmin = $this->scope->isSuperAdminOrSubAccount($operator);
        $isTopAgent = !$isSuperAdmin && $this->scope->isTopAgent($operator);
        $hostId = $this->scope->hostId($operator);

        $query = Agent::query();
        if ($isSuperAdmin) {
            $query->where('role_id', RoleEnum::GENERAL_AGENT->value)->where('status', 1);
        } elseif ($isTopAgent) {
            // 自己＋有建角色的下線（列表看得到的持有者），沒建角色的下線列了也篩不出東西
            $query->where(fn ($q) => $q->whereKey($hostId)->orWhere(fn ($sub) => $sub
                ->where('hierarchy', 'like', '%,' . $hostId . ',%')
                ->whereIn('id', AgentPermissionGroup::query()->select('owner_agent_id')->whereNotNull('owner_agent_id'))));
        } else {
            $query->whereKey($hostId);
        }

        $owners = $query->orderBy('username')->get(['id', 'username'])
            ->map(fn (Agent $a): array => ['id' => $a->id, 'username' => $a->username, 'is_admin' => false])
            ->all();

        if ($isSuperAdmin) {
            array_unshift($owners, [
                'id' => AgentPermissionGroup::ADMIN_OWNER_ID,
                'username' => AgentPermissionGroup::ADMIN_OWNER_LABEL,
                'is_admin' => true,
            ]);
        }

        return [
            'can_select' => false,
            'can_filter' => $isSuperAdmin || $isTopAgent,
            'can_set_default' => $isTopAgent,
            'owners' => $owners,
        ];
    }

    private function findOrFail(int $id): AgentPermissionGroup
    {
        $group = AgentPermissionGroup::query()->find($id);
        if ($group === null) {
            throw new ApiException(__('error_message.agent_permission_group.not_found'));
        }

        return $group;
    }

    /** 只能檢視自己持有或（總代）下線建的角色，其餘 9003；規則見 AgentPermissionScopeService::canViewGroup */
    private function assertViewScope(Agent $operator, ?int $ownerAgentId): void
    {
        if (!$this->scope->canViewGroup($operator, $ownerAgentId)) {
            throw new ApiException(__('error_message.9003'));
        }
    }

    /** 只能修改／停用自己持有的角色（super admin 全部），其餘 9003；下線建的只能檢視不能改 */
    private function assertEditScope(Agent $operator, ?int $ownerAgentId): void
    {
        if (!$this->scope->canEditGroup($operator, $ownerAgentId)) {
            throw new ApiException(__('error_message.9003'));
        }
    }

    /**
     * 新增角色的持有者（4543）：誰建的就記誰——super admin（含子帳號）記 admin 持有（NULL），其他人記自己的主帳號。
     * 請求帶了別的值一律 9003（不信任前端，避免把角色掛到別人名下、或新建 admin預設角色）。
     */
    private function resolveOwnerAgentId(Agent $operator, ?int $ownerAgentId): ?int
    {
        $owner = $this->scope->ownerIdForCreator($operator);
        if ($ownerAgentId !== null && $ownerAgentId !== $owner) {
            throw new ApiException(__('error_message.9003'));
        }

        return $owner;
    }

    /**
     * 設預設角色的把關：admin 持有（NULL）的預設身分鎖死；持有者必須是 admin預設角色（1）或總代（4543：一般代理不設預設，
     * 它的預設永遠不會被自動套用，只會讓下拉預選混亂）；同一持有者已有預設就擋下，不自動頂替。
     *
     * 必須在交易內、且是交易的第一句 SQL：先鎖持有者那一列（主鍵 record lock），同持有者並發設預設時排隊，
     * 後到的那筆拿到鎖後才做一般讀，讀得到先到那筆已 commit 的預設。不直接鎖 agent_permission_groups 的查詢結果——
     * 「不該存在」的查詢下 FOR UPDATE 會拿 gap lock，兩筆並發新增反而 deadlock。
     */
    private function assertDefaultSettable(?int $ownerId, ?int $exceptGroupId): void
    {
        if (empty($ownerId)) {
            throw new ApiException(__('error_message.9003'));
        }

        $owner = Agent::query()->whereKey($ownerId)->lockForUpdate()->first(['id', 'role_id']);
        if (!AgentPermissionGroup::isAdminOwner($ownerId)
            && (int) ($owner?->role_id ?? 0) !== RoleEnum::GENERAL_AGENT->value
        ) {
            throw new ApiException(__('error_message.9003'));
        }

        $existing = AgentPermissionGroup::query()
            ->where('default_setting', 1)
            ->where('owner_agent_id', $ownerId)
            ->when($exceptGroupId !== null, fn ($q) => $q->whereKeyNot($exceptGroupId))
            ->first(['id', 'name']);

        if ($existing !== null) {
            throw new ApiException(__('error_message.agent_permission_group.default_exists', ['name' => $existing->name]));
        }
    }

    /**
     * menus 相容兩種格式：[1,2,3] 取值；{"1":"view"} 值非數字時取鍵。只留存在且啟用的選單。
     *
     * @param  array<mixed>  $menus
     * @return array<int, int>
     */
    private function normalizeMenuIds(array $menus): array
    {
        $ids = [];
        foreach ($menus as $key => $value) {
            $ids[] = is_numeric($value) ? (int) $value : (int) $key;
        }
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        return $this->scope->activeMenuIds($ids);
    }

    /**
     * target/tickets#4543：角色的選單必須在操作者自己的生效權限內，super admin 不限。
     * 畫面上的權限樹本來就是操作者自己的樹，勾不到自己沒有的；這裡擋的是直接打 API 塞別的選單 id：
     * 角色一旦多了操作者沒有的選單，綁著它的人全部一起拿到，等於自我提權。
     * 只檢查「這次新增」的：角色既有、但操作者看不到的選單（例如 4486 的銀行卡兩項）照舊保留，不算違規。
     *
     * @param  array<int, int>  $menuIds
     */
    private function assertMenusWithinOperatorPermissions(Agent $operator, array $menuIds, ?int $groupId): void
    {
        if ($menuIds === [] || $this->scope->isSuperAdminOrSubAccount($operator)) {
            return;
        }

        $existing = $groupId === null ? [] : AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $groupId)
            ->pluck('agent_menu_id')
            ->map(fn ($v) => (int) $v)
            ->all();
        $added = array_diff($menuIds, $existing);
        if ($added === []) {
            return;
        }

        $own = array_flip($this->scope->effectiveMenuIds($operator->id));
        foreach ($added as $menuId) {
            if (!isset($own[$menuId])) {
                throw new ApiException(__('error_message.agent_permission_group.menu_out_of_scope'));
            }
        }
    }

    /**
     * target/tickets#4486：不能使用會員銀行卡個資的操作者，銀行卡管理兩個選單不在其可增刪範圍內。
     * 這種操作者的選單樹已濾掉這兩項，角色管理頁存檔送來的 menus 必然不含它們；不處理的話差集同步會刪掉
     * 角色既有的這兩項（影響所有套用者）。所以送來的一律剔除，角色既有的照留：
     * (menuIds − 兩項) ∪ (角色既有 ∩ 兩項)；新增角色（$groupId 為 null）沒有既有，只剔除。
     * 能使用的操作者（含 super admin）原樣回傳。與 API_Agent 同步修正。
     *
     * @param  array<int, int>  $menuIds
     * @return array<int, int>
     */
    private function restrictMemberBankAccountMenus(Agent $operator, array $menuIds, ?int $groupId): array
    {
        if ($this->scope->canAccessMemberBankAccount($operator)) {
            return $menuIds;
        }

        $bankMenuIds = $this->scope->memberBankAccountMenuIds();
        $kept = $groupId === null ? [] : AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $groupId)
            ->whereIn('agent_menu_id', $bankMenuIds)
            ->pluck('agent_menu_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        return array_values(array_unique(array_merge(array_diff($menuIds, $bankMenuIds), $kept)));
    }

    /**
     * 角色選單差集同步：刪掉不在新清單的、補上新增的（action 空字串）；既有列不動。
     *
     * @param  array<int, int>  $menuIds
     */
    private function syncGroupMenus(int $groupId, array $menuIds, int $adminId): void
    {
        $existing = AgentPermissionGroupMenu::query()
            ->where('agent_permission_group_id', $groupId)
            ->pluck('agent_menu_id')
            ->map(fn ($v) => (int) $v)
            ->all();

        $toDelete = array_values(array_diff($existing, $menuIds));
        if ($toDelete !== []) {
            AgentPermissionGroupMenu::query()
                ->where('agent_permission_group_id', $groupId)
                ->whereIn('agent_menu_id', $toDelete)
                ->delete();
        }

        foreach (array_diff($menuIds, $existing) as $menuId) {
            AgentPermissionGroupMenu::query()->create([
                'agent_permission_group_id' => $groupId,
                'agent_menu_id' => $menuId,
                'action' => '',
                'admin' => $adminId,
            ]);
        }
    }

    /**
     * 角色下拉的目標帳號 host_id：編輯帶 agent_id 讀該代理現值；新增時子帳號＝主帳號（未帶 parent 取操作者 HOST），
     * 非子帳號為 0；都沒帶視為一般帳號（0）。
     */
    private function resolveTargetHostId(Agent $operator, ?int $agentId, ?int $isSubAccount, ?int $parent): int
    {
        if ($agentId !== null) {
            return (int) Agent::query()->whereKey($agentId)->value('host_id');
        }
        if (!$isSubAccount) {
            return 0;
        }

        return $parent ?: $this->scope->hostId($operator);
    }

    /**
     * super admin（含子帳號）幫別人選角色時，目標帳號所屬總代的 id；其他操作者回 false＝不另外過濾。
     *
     * 4543 起 super admin 可套的只剩 admin 持有（NULL）與 admin預設角色（1），兩者都不分線，這道過濾實際不再濾掉東西；
     * 留著是為了 usablePermissionGroupIds 日後若再放寬，仍不會把某持有者的角色綁到別條線（target/tickets#4422）。
     */
    private function resolveTargetLine(Agent $operator, ?int $agentId, ?int $parent): int|false|null
    {
        if (!$this->scope->isSuperAdminOrSubAccount($operator)) {
            return false;
        }

        $baseId = $agentId ?? ($parent ?: $this->scope->hostId($operator));
        $base = Agent::query()->find($baseId, ['id', 'hierarchy_path']);

        return $base === null ? null : $this->scope->topAgentId($base);
    }

    /** 角色是否屬於目標帳號那條線：admin 持有（NULL）與 admin預設角色（1）不分線（後者另由 isAllowedForTargetHost 管）；false＝不過濾 */
    private function isInTargetLine(?int $ownerAgentId, int|false|null $targetLine): bool
    {
        if ($targetLine === false || empty($ownerAgentId) || AgentPermissionGroup::isAdminOwner($ownerAgentId)) {
            return true;
        }

        return $targetLine !== null && (int) $ownerAgentId === $targetLine;
    }

    /**
     * @param  array<int, null|int>  $ownerIds
     * @return array<int, string>
     */
    private function ownerNames(array $ownerIds): array
    {
        $ids = array_values(array_unique(array_filter($ownerIds)));

        return $ids === [] ? [] : Agent::query()->whereIn('id', $ids)->pluck('username', 'id')->all();
    }

    /**
     * 持有者顯示名稱：admin 持有（NULL）回 null（前端顯示「admin」）、admin預設角色（1）固定 admin、其餘查帳號。
     *
     * @param  array<int, string>  $ownerNames
     */
    private function ownerLabel(?int $ownerAgentId, array $ownerNames): ?string
    {
        if (empty($ownerAgentId)) {
            return null;
        }
        if (AgentPermissionGroup::isAdminOwner($ownerAgentId)) {
            return AgentPermissionGroup::ADMIN_OWNER_LABEL;
        }

        return $ownerNames[$ownerAgentId] ?? null;
    }

    private function perPage(?int $perPage): int
    {
        return ($perPage !== null && $perPage > 0 && $perPage <= self::MAX_PER_PAGE) ? $perPage : self::DEFAULT_PER_PAGE;
    }
}
