<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\AgentGroup;
use App\Models\AgentGroupPermission;
use Illuminate\Support\Facades\DB;

/**
 * 代理的「組別管理權」讀寫（target/tickets#4422 自 API_Agent AgentGroupController::getAgentGroupPermissions／
 * updateAgentGroupPermissions 搬入）。管的是能編輯哪些設定組別（agent_groups），不是選單權限。
 *
 * 必須與 API_Agent 版等價（舊後台仍走它）：
 * - 可選組別＝目標所屬組織的組別；非 super admin 再限「操作者主帳號的組織」且「操作者自己有權的組別」
 * - 更新先把目標所有組別關掉，再逐一開啟送來的（含操作者範圍外的也會被關，照 API_Agent 原行為）
 *
 * 刻意的差異：讀取也檢查目標在操作者範疇內（API_Agent 版只有更新查，讀取任何人都查得到）；更新包交易。
 */
class AgentGroupPermissionService
{
    public function __construct(
        private readonly AgentPermissionScopeService $scope,
    ) {}

    /**
     * @return array{agent_id: int, agent_name: string, has_permissions: array<int, int>, groups: array<int, array<string, mixed>>}
     */
    public function view(Agent $operator, int $targetId): array
    {
        $target = $this->findInScope($operator, $targetId);
        $host = $this->hostAgent($operator);

        $query = AgentGroup::query()
            ->where('organization_id', $target->organization_id)
            ->orderBy('agent_group_type')
            ->orderBy('id');
        if (!$this->scope->isSuperAdminOrSubAccount($host)) {
            $query->where('organization_id', $host->organization_id)
                ->whereIn('id', $this->ownedGroupIds($operator->id));
        }

        return [
            'agent_id' => $target->id,
            'agent_name' => $target->username,
            'has_permissions' => $this->ownedGroupIds($target->id),
            'groups' => $query->get(['id', 'agent_group_type', 'name'])->map(fn (AgentGroup $g): array => [
                'group_id' => $g->id,
                'agent_group_type' => (int) $g->agent_group_type,
                'group_name' => $g->name,
                'group_type' => (int) $g->agent_group_type,
            ])->all(),
        ];
    }

    /**
     * @param  array<int, int>  $groupIds
     */
    public function update(Agent $operator, int $targetId, array $groupIds): void
    {
        $this->findInScope($operator, $targetId);

        if (!$this->scope->isSuperAdminOrSubAccount($this->hostAgent($operator))
            && array_diff($groupIds, $this->ownedGroupIds($operator->id)) !== []
        ) {
            throw new ApiException(__('error_message.9003'));
        }

        DB::transaction(function () use ($operator, $targetId, $groupIds): void {
            // 第一句即鎖定讀：鎖目標代理主鍵列當互斥點
            Agent::query()->whereKey($targetId)->lockForUpdate()->first(['id']);

            AgentGroupPermission::query()->where('agent_id', $targetId)->update(['status' => 0]);

            foreach (array_values(array_unique($groupIds)) as $groupId) {
                $updated = AgentGroupPermission::query()
                    ->where('agent_id', $targetId)
                    ->where('agent_group_id', $groupId)
                    ->update(['status' => 1]);
                if ($updated === 0) {
                    AgentGroupPermission::query()->create([
                        'agent_id' => $targetId,
                        'agent_group_id' => $groupId,
                        'status' => 1,
                        'admin' => $operator->id,
                    ]);
                }
            }
        });
    }

    private function findInScope(Agent $operator, int $targetId): Agent
    {
        if (!$this->scope->isInScope($this->scope->hostId($operator), $targetId)) {
            throw new ApiException(__('error_message.9003'));
        }

        $target = Agent::query()->find($targetId);
        if ($target === null) {
            throw new ApiException(__('error_message.1005'));
        }

        return $target;
    }

    /** 操作者的主帳號（super admin 判斷與組織都以主帳號為準，照 API_Agent 的 HOST） */
    private function hostAgent(Agent $operator): Agent
    {
        $hostId = $this->scope->hostId($operator);

        return $hostId === $operator->id ? $operator : (Agent::query()->find($hostId) ?? $operator);
    }

    /** @return array<int, int> 該代理有權的組別 id */
    private function ownedGroupIds(int $agentId): array
    {
        return AgentGroupPermission::query()
            ->where('agent_id', $agentId)
            ->where('status', 1)
            ->pluck('agent_group_id')
            ->map(fn ($v) => (int) $v)
            ->unique()
            ->values()
            ->all();
    }
}
