<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberGroup;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 會員群組後台管理（Agent 面向 CRUD）。
 *
 * 本檔有兩條方向相反的軸，不可混用：
 *
 * 1.【可見性軸】誰能管理這筆群組設定（list / view / dropdown 無 memberId / create / update）
 *    → 自己＋下線（applyVisibilityScope）；admin 不設限，子帳號以主帳號階層為準
 *
 * 2.【適用性軸】這筆設定管到哪些會員（dropdown 帶 memberId / assignMember / 註冊自動帶入）
 *    → 自己＋上級（lineOwnerIds / defaultGroupIdForAgent）
 *    與 API_Agent MemberController::resolveMemberGroupId 的寫入驗證等價，翻方向會造成
 *    同一筆指派「一端放行、另一端 1010」。
 *
 * 前台限額 enforcement 走 MemberLimitResolver。
 *
 * @knowledge rules/member-group-fee-tiers
 */
class MemberGroupService
{
    public function __construct(
        private AgentService $agentService,
    ) {}

    public function paginateForAgent(Agent $agent, array $filters): LengthAwarePaginator
    {
        return MemberGroup::query()
            ->withCount('members')
            ->with('agent:id,username,hierarchy_path')
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
            ->when(!empty($filters['keyword']), fn ($q) => $q->where('name', 'like', '%' . $filters['keyword'] . '%'))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['agent_id']), fn ($q) => $q->where('agent_id', $filters['agent_id']))
            ->orderBy('sequence', 'desc')
            ->orderBy('id', 'asc')
            ->paginate($filters['per_page']);
    }

    /**
     * 群組下拉；僅回啟用中的群組。
     * 帶 memberId（會員編輯指派用）走【適用性軸】，admin 同樣受線的限制；
     * 不帶 memberId（列表篩選用）走【可見性軸】。
     */
    public function dropdown(Agent $agent, ?int $memberId = null): Collection
    {
        $query = MemberGroup::query();

        if ($memberId) {
            $member = Member::find($memberId);
            if (!$member || (!$this->isAdmin($agent) && !$this->memberInScope($agent, $member))) {
                return collect();
            }
            $query->whereIn('agent_id', $this->lineOwnerIds((int) $member->parent));
        } else {
            $this->applyVisibilityScope($query, $agent);
        }

        return $query
            ->where('status', 1)
            ->orderBy('sequence', 'desc')
            ->orderBy('id', 'asc')
            ->get(['id', 'name', 'default_setting']);
    }

    /** 檢視單一群組（查無或越權拋 1010）；帶 bankAccounts 供編輯回填。 */
    public function findVisibleForAgent(Agent $agent, int $id): MemberGroup
    {
        $group = MemberGroup::query()
            ->withCount('members')
            ->with(['bankAccounts:id', 'agent:id,username,hierarchy_path'])
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
            ->where('id', $id)
            ->first();

        if (!$group) {
            throw new ApiException(__('error_message.1010'));
        }

        return $group;
    }

    /** 取單一群組供 update 守衛用（同 findVisibleForAgent 的 scope，不帶檢視用的 eager load）。 */
    public function findOwnedForAgent(Agent $agent, int $id): MemberGroup
    {
        $group = MemberGroup::query()
            ->with('agent:id,username,hierarchy_path')
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
            ->where('id', $id)
            ->first();

        if (!$group) {
            throw new ApiException(__('error_message.1010'));
        }

        return $group;
    }

    public function create(Agent $agent, array $data, ?array $bankAccountIds): MemberGroup
    {
        $ownerId = $this->resolveOwnerAgentId($agent, $data['agent_id'] ?? null);

        $data = $this->normalizeFeeTiers($data);

        return DB::transaction(function () use ($ownerId, $agent, $data, $bankAccountIds) {
            $data['agent_id'] = $ownerId;
            $data['admin'] = $agent->id;
            $group = MemberGroup::create($data);

            if (!empty($data['default_setting'])) {
                $this->clearOtherDefault($ownerId, $group->id);
            }

            if ($bankAccountIds !== null) {
                $group->bankAccounts()->sync($this->filterOwnBankAccounts($agent, $bankAccountIds));
            }

            return $group;
        });
    }

    public function update(Agent $agent, MemberGroup $group, array $data, ?array $bankAccountIds): MemberGroup
    {
        $data = $this->normalizeFeeTiers($data);

        return DB::transaction(function () use ($agent, $group, $data, $bankAccountIds) {
            unset($data['agent_id']); // 擁有者建立後不可變更（避免已指派會員被重新歸屬換算）
            $data['admin'] = $agent->id;
            $group->update($data);

            if (!empty($data['default_setting'])) {
                $this->clearOtherDefault((int) $group->agent_id, $group->id);
            }

            if ($bankAccountIds !== null) {
                $group->bankAccounts()->sync($this->filterOwnBankAccounts($agent, $bankAccountIds));
            }

            return $group->refresh();
        });
    }

    /**
     * 指派會員到群組（member_group_id；null=取消指派）。
     * 越權防護：會員直屬代理須在當前代理階層內，目標群組須屬該會員所屬線且啟用中。
     */
    public function assignMember(Agent $agent, int $memberId, ?int $groupId): void
    {
        $member = Member::find($memberId);
        if (!$member) {
            throw new ApiException(__('error_message.1005'));
        }

        if (!$this->isAdmin($agent) && !$this->memberInScope($agent, $member)) {
            throw new ApiException(__('error_message.1007'));
        }

        $resolvedGroupId = null;
        if ($groupId) {
            $group = MemberGroup::where('id', $groupId)
                ->whereIn('agent_id', $this->lineOwnerIds((int) $member->parent))
                ->where('status', 1)
                ->first();
            if (!$group) {
                throw new ApiException(__('error_message.1010'));
            }
            $resolvedGroupId = $group->id;
        }

        $member->member_group_id = $resolvedGroupId;
        $member->save();
    }

    /**
     * 銀行管理 tab 勾選清單：該代理自助存款可用帳號（重用前台既有查詢）。
     *
     * @return \App\Data\BankAccountData[]
     */
    public function bankAccounts(Agent $agent): array
    {
        return $this->agentService->getAvailableBankAccounts($agent);
    }

    /**
     * 取該代理所屬線「最近」的預設群組 id（default_setting=1 且啟用中），沿 hierarchy 由近到遠找；無則 null。
     * 供會員註冊時自動帶入 member_group_id。
     */
    public function defaultGroupIdForAgent(Agent $agent): ?int
    {
        $agentIds = array_values(array_filter(
            array_map('intval', $agent->hierarchy_array),
            fn ($id) => $id > 0,
        ));
        if ($agentIds === []) {
            return null;
        }

        $order = implode(',', $agentIds);
        $id = MemberGroup::query()
            ->whereIn('agent_id', $agentIds)
            ->where('default_setting', 1)
            ->where('status', 1)
            ->orderByRaw("FIELD(agent_id, {$order})")
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * 平台手續費階梯寫入前正規化：null／空陣列存成 null（未啟用），否則依 from 升冪排序並轉型
     * （from/type 為 int、value 為字串）。未帶該欄位（部分更新）則不動，避免誤清既有設定。
     */
    private function normalizeFeeTiers(array $data): array
    {
        if (!array_key_exists('withdraw_fee_tiers', $data)) {
            return $data;
        }

        $tiers = $data['withdraw_fee_tiers'];
        if (!is_array($tiers) || $tiers === []) {
            $data['withdraw_fee_tiers'] = null;

            return $data;
        }

        $data['withdraw_fee_tiers'] = MemberGroup::normalizeWithdrawFeeTiers($tiers);

        return $data;
    }

    private function clearOtherDefault(int $ownerId, int $exceptId): void
    {
        MemberGroup::where('agent_id', $ownerId)
            ->where('id', '!=', $exceptId)
            ->where('default_setting', 1)
            ->update(['default_setting' => 0]);
    }

    /**
     * 僅保留該代理實際可用的自助存款帳號 id，防止塞入他人帳號。
     *
     * @param  int[]  $ids
     * @return int[]
     */
    private function filterOwnBankAccounts(Agent $agent, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $available = collect($this->agentService->getAvailableBankAccounts($agent))->pluck('bank_id')->all();

        return array_values(array_intersect($ids, $available));
    }

    private function memberInScope(Agent $agent, Member $member): bool
    {
        $hierarchyPath = $agent->hierarchy_path;
        if ($agent->host_id != 0) {
            $upper = Agent::find($agent->host_id);
            $hierarchyPath = $upper->hierarchy_path ?? $hierarchyPath;
        }

        $memberAgent = Agent::find($member->parent);
        if (!$memberAgent) {
            return false;
        }

        // 結尾補 '/' 比對，避免代理 id 前綴碰撞（/1/23 誤匹配 /1/234）
        return $memberAgent->hierarchy_path === $hierarchyPath
            || str_starts_with((string) $memberAgent->hierarchy_path, $hierarchyPath . '/');
    }

    /**
     * 【可見性軸】限縮為自己＋下線；admin 不設限，子帳號以主帳號階層為準。
     *
     * hierarchy_path 為空時 fail-closed（只看自己），空字串進 LIKE 會變成全站可見；
     * 下線比對用 '{path}/%'，避免 /1/23 誤匹配 /1/234。
     */
    private function applyVisibilityScope(Builder $query, Agent $agent): void
    {
        if ($this->isAdmin($agent)) {
            return;
        }

        $ownerId = $this->ownerId($agent);
        $path = $this->effectiveHierarchyPath($agent);

        if ($path === null || $path === '') {
            $query->where('agent_id', $ownerId);

            return;
        }

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->where('agent_id', $ownerId)
                ->orWhereIn('agent_id', function ($sub) use ($path) {
                    $sub->select('id')->from('agents')->where('hierarchy_path', 'like', $path . '/%');
                });
        });
    }

    /**
     * 指定代理所屬線可用的群組擁有者集合＝該代理＋其所有上級。
     *
     * @return int[]
     */
    private function lineOwnerIds(int $agentId): array
    {
        $agent = Agent::find($agentId);

        return $agent ? $this->hierarchyIds($agent) : [$agentId];
    }

    /**
     * 自己＋所有上級的 agent id（濾除空值 / hierarchy_path 為 null 的雜訊）。
     *
     * @return int[]
     */
    private function hierarchyIds(Agent $agent): array
    {
        return array_values(array_filter(
            array_map('intval', $agent->hierarchy_array),
            fn ($id) => $id > 0,
        ));
    }

    /**
     * 驗證並解析欲指派的擁有者代理 id：空 / 等於本人 → 本人；admin → 任一存在代理；非 admin → 僅本人或其下線。
     */
    private function resolveOwnerAgentId(Agent $agent, $requestedAgentId): int
    {
        $ownerId = $this->ownerId($agent);
        $requested = (int) $requestedAgentId;

        if ($requested <= 0 || $requested === $ownerId) {
            return $ownerId;
        }

        if ($this->isAdmin($agent)) {
            if (!Agent::whereKey($requested)->exists()) {
                throw new ApiException(__('error_message.1007'));
            }

            return $requested;
        }

        // 非 admin：指定的擁有者須為當前代理（子帳號歸主帳號）的下線
        $callerPath = $this->effectiveHierarchyPath($agent);
        $target = Agent::find($requested);
        if ($target !== null && $callerPath !== null
            && str_starts_with((string) $target->hierarchy_path, $callerPath . '/')) {
            return $requested;
        }

        throw new ApiException(__('error_message.1007'));
    }

    /**
     * 取當前代理（子帳號歸主帳號）的 hierarchy_path，供下線判定用。
     */
    private function effectiveHierarchyPath(Agent $agent): ?string
    {
        if ($agent->host_id != 0) {
            return Agent::find($agent->host_id)?->hierarchy_path;
        }

        return $agent->hierarchy_path;
    }

    private function ownerId(Agent $agent): int
    {
        return $agent->host_id != 0 ? (int) $agent->host_id : (int) $agent->id;
    }

    private function isAdmin(Agent $agent): bool
    {
        return $agent->id == 1 || $agent->host_id == 1;
    }
}
