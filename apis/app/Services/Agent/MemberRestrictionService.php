<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\MemberRestrictionTargetEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberGroup;
use App\Models\MemberRestrictionRule;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * 風控會員限制後台管理（Agent 面向 CRUD）。
 *
 * 可見性軸與 MemberGroupService 相同：自己＋下線（applyVisibilityScope）；
 * admin 不設限，子帳號以主帳號階層為準。scope 目標為規則上 denormalize 的 agent_id
 * （建立時由目標導出：member.parent / member_groups.agent_id，之後不可變）。
 *
 * 前台 enforcement 走 MemberRestrictionGuard。
 */
class MemberRestrictionService
{
    public function paginateForAgent(Agent $agent, array $filters): LengthAwarePaginator
    {
        return MemberRestrictionRule::query()
            ->with(['target', 'agent:id,username', 'updater:id,username'])
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
            ->when(!empty($filters['username']), function ($q) use ($filters) {
                $keyword = '%' . $filters['username'] . '%';
                $q->whereHasMorph('target', [Member::class, MemberGroup::class], function ($sub, $type) use ($keyword) {
                    $sub->where($type === Member::class ? 'username' : 'name', 'like', $keyword);
                });
            })
            ->when(!empty($filters['remark']), fn ($q) => $q->where('remark', 'like', '%' . $filters['remark'] . '%'))
            ->when(isset($filters['status']) && $filters['status'] !== '', fn ($q) => $q->where('status', $filters['status']))
            ->when(!empty($filters['type']), fn ($q) => $q->where(
                'target_type',
                MemberRestrictionTargetEnum::from((int) $filters['type'])->morphAlias(),
            ))
            ->orderBy('id', 'desc')
            ->paginate($filters['per_page']);
    }

    /** 檢視單筆（查無或越權拋 1010）。 */
    public function findVisibleForAgent(Agent $agent, int $id): MemberRestrictionRule
    {
        $rule = MemberRestrictionRule::query()
            ->with(['target', 'agent:id,username', 'updater:id,username'])
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
            ->where('id', $id)
            ->first();

        if (!$rule) {
            throw new ApiException(__('error_message.1010'));
        }

        return $rule;
    }

    /**
     * 建立規則。目標由 type 決定：
     *   1(会员管理)  → username 解析單一會員（越權拋 1007）
     *   2(会员群组)  → member_group_id 取可見群組（查無/越權拋 1010）
     * agent_id 由目標導出後不可變；同目標已有規則拋 member_restriction.duplicate。
     */
    public function create(Agent $agent, array $data): MemberRestrictionRule
    {
        $type = MemberRestrictionTargetEnum::from((int) $data['type']);
        [$targetId, $ownerAgentId] = $this->resolveTarget($agent, $type, $data);

        return DB::transaction(function () use ($agent, $type, $targetId, $ownerAgentId, $data) {
            $exists = MemberRestrictionRule::query()
                ->where('target_type', $type->morphAlias())
                ->where('target_id', $targetId)
                ->lockForUpdate()
                ->exists();
            if ($exists) {
                throw new ApiException(__('error_message.member_restriction.duplicate'));
            }

            return MemberRestrictionRule::create([
                'agent_id' => $ownerAgentId,
                'target_type' => $type->morphAlias(),
                'target_id' => $targetId,
                'remark' => $data['remark'] ?? null,
                'status' => $data['status'],
                'is_disabled' => $data['is_disabled'],
                'withdrawal_locked' => $data['withdrawal_locked'],
                'admin' => $agent->id,
            ]);
        });
    }

    /** 更新規則；目標與 agent_id 建立後不可變，只允許改 remark/status/is_disabled/withdrawal_locked。 */
    public function update(Agent $agent, int $id, array $data): MemberRestrictionRule
    {
        return DB::transaction(function () use ($agent, $id, $data) {
            $rule = MemberRestrictionRule::query()
                ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$rule) {
                throw new ApiException(__('error_message.1010'));
            }

            $rule->update([
                'remark' => $data['remark'] ?? null,
                'status' => $data['status'],
                'is_disabled' => $data['is_disabled'],
                'withdrawal_locked' => $data['withdrawal_locked'],
                'admin' => $agent->id,
            ]);

            return $rule->refresh();
        });
    }

    /** 刪除規則（查無或越權拋 1010）。 */
    public function delete(Agent $agent, int $id): void
    {
        DB::transaction(function () use ($agent, $id) {
            $rule = MemberRestrictionRule::query()
                ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if (!$rule) {
                throw new ApiException(__('error_message.1010'));
            }

            $rule->delete();
        });
    }

    /**
     * 解析限制目標，回傳 [target_id, 目標所屬 agent_id]。
     *
     * @return array{0: int, 1: int}
     */
    private function resolveTarget(Agent $agent, MemberRestrictionTargetEnum $type, array $data): array
    {
        if ($type === MemberRestrictionTargetEnum::MEMBER) {
            $member = Member::where('username', $data['username'] ?? '')->first();
            if (!$member) {
                throw new ApiException(__('error_message.1007'));
            }
            if (!$this->isAdmin($agent) && !$this->memberInScope($agent, $member)) {
                throw new ApiException(__('error_message.1007'));
            }

            return [(int) $member->id, (int) $member->parent];
        }

        $group = MemberGroup::query()
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $agent))
            ->where('id', (int) ($data['member_group_id'] ?? 0))
            ->first();
        if (!$group) {
            throw new ApiException(__('error_message.1010'));
        }

        return [(int) $group->id, (int) $group->agent_id];
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
     * hierarchy_path 為空時 fail-closed（只看自己）。與 MemberGroupService::applyVisibilityScope 同款。
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

    /** 取當前代理（子帳號歸主帳號）的 hierarchy_path，供下線判定用。 */
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
