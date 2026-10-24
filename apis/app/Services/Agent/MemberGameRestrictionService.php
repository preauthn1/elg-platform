<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Enums\Member\MemberRestrictScopeEnum;
use App\Enums\Member\MemberRestrictTypeEnum;
use App\Enums\StatusEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberGameRestriction;
use App\Models\MemberGameRestrictionMember;
use App\Models\MemberGameRestrictionTarget;
use App\Models\MemberGroup;
use App\Models\Vendor;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 會員遊戲限制 工單4126
 *
 * 與 GameMaintenanceService（4120 代理層級的遊戲維護）平行：
 * 該服務管「這款遊戲整條線暫時維護、到點自動恢復」，
 * 本服務管「這些會員永久不能玩這些遊戲商／遊戲」，解除即停用或刪除。
 *
 * 前台判定不在此處，而是併入 VendorService 既有的兩支查詢
 * （getMaintenanceVendorIds / getMaintenanceGameMap），前端零改動。
 */
class MemberGameRestrictionService
{
    /**
     * 限制單列表
     *
     * 會員帳號／群組篩選採「涵蓋即列出」：一張單可含多個對象，
     * 只要其中一個符合就列出——營運查某會員是為了看「有哪些限制影響到他」。
     *
     * @param  array{status?: int|string|null, restrict_type?: int|string|null, target_scope?: int|string|null, username?: string|null, member_group_id?: int|string|null, per_page?: int}  $filters
     */
    public function list(Agent $operator, array $filters): LengthAwarePaginator
    {
        $username = trim((string) ($filters['username'] ?? ''));
        $groupId = $filters['member_group_id'] ?? null;

        return MemberGameRestriction::query()
            ->with(['targets', 'members.member:id,username'])
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
            ->when(
                isset($filters['status']) && $filters['status'] !== '',
                fn (Builder $q) => $q->where('status', (int) $filters['status'])
            )
            ->when(
                isset($filters['restrict_type']) && $filters['restrict_type'] !== '',
                fn (Builder $q) => $q->where('restrict_type', (int) $filters['restrict_type'])
            )
            ->when(
                isset($filters['target_scope']) && $filters['target_scope'] !== '',
                fn (Builder $q) => $q->where('target_scope', (int) $filters['target_scope'])
            )
            ->when($username !== '', fn (Builder $q) => $q->whereHas(
                'members.member',
                fn (Builder $sub) => $sub->where('username', 'like', '%' . $username . '%')
            ))
            ->when($groupId, fn (Builder $q) => $q->whereHas(
                'members',
                fn (Builder $sub) => $sub->where('member_group_id', (int) $groupId)
            ))
            ->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 15))
            ->through(fn (MemberGameRestriction $row) => $this->attachTargetNames($row));
    }

    /**
     * 補上受限標的的名稱供列表／詳情顯示。
     *
     * target_id 依 restrict_type 分屬 vendors 或 games，兩表各查一次後回填，
     * 避免前端為了顯示名稱再打一輪下拉接口。
     */
    private function attachTargetNames(MemberGameRestriction $restriction): MemberGameRestriction
    {
        $ids = $restriction->targets->pluck('target_id')->map(fn ($id) => (int) $id)->all();

        if (empty($ids)) {
            $restriction->setAttribute('target_names', []);

            return $restriction;
        }

        if ((int) $restriction->restrict_type === MemberRestrictTypeEnum::VENDOR->value) {
            $names = Vendor::query()->whereIn('id', $ids)->pluck('name', 'id');
            $vendorNames = [];
            $vendorIds = [];
        } else {
            // 遊戲同名的情況常見（各商都有「百家樂」），一併帶出所屬遊戲商：
            // 名稱供辨識、id 供編輯時自動回填「平臺」篩選，候選清單才列得出該商的遊戲
            $games = Game::query()->whereIn('id', $ids)->get(['id', 'name', 'vendor_id']);
            $names = $games->pluck('name', 'id');
            $vendorNameMap = Vendor::query()
                ->whereIn('id', $games->pluck('vendor_id')->unique()->all())
                ->pluck('name', 'id');
            $vendorNames = $games
                ->mapWithKeys(fn ($g) => [(int) $g->id => (string) ($vendorNameMap[$g->vendor_id] ?? '')])
                ->all();
            $vendorIds = $games->mapWithKeys(fn ($g) => [(int) $g->id => (int) $g->vendor_id])->all();
        }

        $restriction->setAttribute(
            'target_names',
            collect($ids)->map(fn (int $id) => [
                'id' => $id,
                'name' => (string) ($names[$id] ?? "#{$id}"),
                'vendor_id' => (int) ($vendorIds[$id] ?? 0),
                'vendor_name' => (string) ($vendorNames[$id] ?? ''),
            ])->all()
        );

        return $restriction;
    }

    /** 單筆詳情（含受限標的與對象，供編輯回填） */
    public function view(Agent $operator, int $id): ?MemberGameRestriction
    {
        $restriction = MemberGameRestriction::query()
            ->with(['targets', 'members.member:id,username'])
            ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
            ->find($id);

        // 編輯回填也需要名稱：遊戲下拉為遠端搜尋，不給名稱就只顯示 id
        return $restriction ? $this->attachTargetNames($restriction) : null;
    }

    /**
     * 新增／編輯限制單
     *
     * 標的與對象皆採整批覆寫：送來的即為最終清單，未列出的既有關聯刪除。
     * 主表與兩張子表包在同一交易，避免出現「改了類型但標的還是舊的」中間態。
     *
     * @param  array<int>  $targetIds  受限的 vendor_id 或 game_id（依 restrict_type）
     * @param  array<int>  $memberIds  受限會員（target_scope=1 時使用）
     * @param  array<int>  $memberGroupIds  受限會員群組（target_scope=2 時使用）
     * @return int 限制單 id
     */
    public function save(
        Agent $operator,
        ?int $id,
        array $payload,
        array $targetIds,
        array $memberIds,
        array $memberGroupIds,
    ): int {
        $this->assertScopeInHierarchy(
            $operator,
            (int) $payload['agent_id'],
            (int) $payload['target_scope'] === MemberRestrictScopeEnum::GROUP->value ? [] : $memberIds,
            (int) $payload['target_scope'] === MemberRestrictScopeEnum::GROUP->value ? $memberGroupIds : [],
        );

        return DB::transaction(function () use ($operator, $id, $payload, $targetIds, $memberIds, $memberGroupIds): int {
            $attributes = [
                'restrict_type' => (int) $payload['restrict_type'],
                'target_scope' => (int) $payload['target_scope'],
                'agent_id' => (int) $payload['agent_id'],
                'status' => (int) ($payload['status'] ?? StatusEnum::ENABLE->value),
                'memo' => $payload['memo'] ?? null,
                'admin' => (int) $operator->id,
            ];

            if ($id === null) {
                $restriction = MemberGameRestriction::query()->create($attributes);
            } else {
                $restriction = MemberGameRestriction::query()
                    ->tap(fn (Builder $q) => $this->applyVisibilityScope($q, $operator))
                    ->lockForUpdate()
                    ->findOrFail($id);
                $restriction->update($attributes);
            }

            $this->syncTargets($restriction, $targetIds);
            $this->syncMembers(
                $restriction,
                (int) $payload['target_scope'] === MemberRestrictScopeEnum::GROUP->value ? [] : $memberIds,
                (int) $payload['target_scope'] === MemberRestrictScopeEnum::GROUP->value ? $memberGroupIds : [],
            );

            return (int) $restriction->id;
        });
    }

    /**
     * 限制單的代理、受限會員、受限群組都必須是操作者自己或下線（4495）。
     *
     * 否則低權限帳號可對別線玩家下遊戲限制，或把限制掛在別線代理名下。平台端不限制。
     *
     * @param  array<int>  $memberIds
     * @param  array<int>  $memberGroupIds
     *
     * @throws ApiException
     */
    private function assertScopeInHierarchy(Agent $operator, int $agentId, array $memberIds, array $memberGroupIds): void
    {
        if ($operator->isPlatformAdmin()) {
            return;
        }

        if (!$operator->canSeeAgent($agentId)) {
            throw new ApiException(__('error_message.9003'));
        }

        $visibleAgentIds = Agent::query()->visibleTo($operator)->select('id');

        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        if ($memberIds !== [] && Member::query()
            ->whereIn('id', $memberIds)
            ->whereIn('parent', $visibleAgentIds)
            ->count() !== count($memberIds)) {
            throw new ApiException(__('error_message.9003'));
        }

        $memberGroupIds = array_values(array_unique(array_map('intval', $memberGroupIds)));
        if ($memberGroupIds !== [] && MemberGroup::query()
            ->whereIn('id', $memberGroupIds)
            ->whereIn('agent_id', $visibleAgentIds)
            ->count() !== count($memberGroupIds)) {
            throw new ApiException(__('error_message.9003'));
        }
    }

    /**
     * 差集覆寫受限標的：只增刪異動的部分，不整批砍掉重建
     * （整批刪除在併發下有短暫「無任何標的」的空窗，該瞬間限制形同失效）。
     *
     * @param  array<int>  $targetIds
     */
    private function syncTargets(MemberGameRestriction $restriction, array $targetIds): void
    {
        $targetIds = array_values(array_unique(array_map('intval', $targetIds)));

        $existing = MemberGameRestrictionTarget::query()
            ->where('restriction_id', $restriction->id)
            ->pluck('target_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $toRemove = array_diff($existing, $targetIds);
        if (!empty($toRemove)) {
            MemberGameRestrictionTarget::query()
                ->where('restriction_id', $restriction->id)
                ->whereIn('target_id', $toRemove)
                ->delete();
        }

        $toAdd = array_diff($targetIds, $existing);
        if (!empty($toAdd)) {
            MemberGameRestrictionTarget::query()->insert(
                array_map(fn (int $targetId) => [
                    'restriction_id' => $restriction->id,
                    'target_id' => $targetId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ], array_values($toAdd))
            );
        }
    }

    /**
     * 差集覆寫受限對象。依 target_scope 只會有一種有值，另一種傳空陣列即整批清除。
     *
     * @param  array<int>  $memberIds
     * @param  array<int>  $memberGroupIds
     */
    private function syncMembers(MemberGameRestriction $restriction, array $memberIds, array $memberGroupIds): void
    {
        $memberIds = array_values(array_unique(array_map('intval', $memberIds)));
        $memberGroupIds = array_values(array_unique(array_map('intval', $memberGroupIds)));

        $existing = MemberGameRestrictionMember::query()
            ->where('restriction_id', $restriction->id)
            ->get(['id', 'member_id', 'member_group_id']);

        $keep = [];
        foreach ($existing as $row) {
            $isWanted = $row->member_id > 0
                ? in_array((int) $row->member_id, $memberIds, true)
                : in_array((int) $row->member_group_id, $memberGroupIds, true);

            if ($isWanted) {
                $keep[] = $row->member_id > 0
                    ? 'm:' . $row->member_id
                    : 'g:' . $row->member_group_id;
            } else {
                $row->delete();
            }
        }

        $rows = [];
        foreach ($memberIds as $memberId) {
            if (!in_array('m:' . $memberId, $keep, true)) {
                $rows[] = [
                    'restriction_id' => $restriction->id,
                    'member_id' => $memberId,
                    'member_group_id' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }
        foreach ($memberGroupIds as $groupId) {
            if (!in_array('g:' . $groupId, $keep, true)) {
                $rows[] = [
                    'restriction_id' => $restriction->id,
                    'member_id' => 0,
                    'member_group_id' => $groupId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        if (!empty($rows)) {
            MemberGameRestrictionMember::query()->insert($rows);
        }
    }

    /**
     * 表單用：依關鍵字搜尋可選會員（限操作者下線）
     *
     * 前端為遠端搜尋下拉，未輸入關鍵字不查——下線動輒數萬筆，全量回傳會凍死彈窗。
     *
     * @return Collection<int, Member>
     */
    public function searchMembers(Agent $operator, string $keyword, int $limit = 50): Collection
    {
        if (trim($keyword) === '') {
            return collect();
        }

        $path = $this->effectiveHierarchyPath($operator);

        return Member::query()
            ->select(['id', 'username'])
            ->where('username', 'like', '%' . trim($keyword) . '%')
            ->when(!$this->isAdmin($operator), fn (Builder $q) => $q->whereHas(
                'agent',
                fn (Builder $sub) => $sub->where(function (Builder $inner) use ($operator, $path) {
                    $inner->where('id', $this->ownerId($operator));
                    if ($path !== null && $path !== '') {
                        // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
                        $inner->orWhere('hierarchy_path', 'like', $path . '/%');
                    }
                })
            ))
            ->orderBy('username')
            ->limit($limit)
            ->get();
    }

    /**
     * 可見範圍：admin 不設限，其餘限自己與下線建立的單
     * （與 GameMaintenanceService 一致）
     */
    private function applyVisibilityScope(Builder $query, Agent $operator): void
    {
        if ($this->isAdmin($operator)) {
            return;
        }

        $ownerId = $this->ownerId($operator);
        $path = $this->effectiveHierarchyPath($operator);

        if ($path === null || $path === '') {
            $query->where('agent_id', $ownerId);

            return;
        }

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->where('agent_id', $ownerId)
                ->orWhereIn('agent_id', function ($sub) use ($path) {
                    // 兩段式比對：$path . '/%' 避免 /1/23 誤匹配 /1/234
                    $sub->select('id')->from('agents')->where('hierarchy_path', 'like', $path . '/%');
                });
        });
    }

    /** 子帳號（host_id != 0）的資料歸屬於其主帳號 */
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
