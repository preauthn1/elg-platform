<?php

declare(strict_types=1);

namespace App\Services\EventV2;

use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberGroup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * 活動 V2 目標用戶的可選範圍（target/tickets#4234）：以「活動的代理商帳號」為準，不是以操作者為準。
 *
 * 活動只對 agent_id 那條線可見，所以目標用戶只能從這條線裡選：
 * - 會員：上層代理是活動代理本身或它的下線（hierarchy_path 相等或以「路徑/」開頭，結尾補 / 防 id 前綴碰撞）。
 * - 會員群組：建立者是活動代理的上層或自己（這些群組可套用到它底下的會員，同 MemberGroupService 的適用性軸
 *   lineOwnerIds），或是它的下線；只列啟用中的群組。
 * 後台選項接口與建立／更新驗證共用這一份規則，避免「下拉看得到、存檔卻被擋」或反之。
 */
final class EventV2TargetScope
{
    private const int MEMBER_SEARCH_LIMIT = 50;

    public function __construct(private readonly Agent $agent) {}

    public static function forAgentId(int $agentId): ?self
    {
        $agent = Agent::query()->find($agentId, ['id', 'hierarchy_path']);

        return $agent ? new self($agent) : null;
    }

    /** @return Builder<MemberGroup> */
    public function groupsQuery(): Builder
    {
        $path = (string) $this->agent->hierarchy_path;
        $ancestors = array_values(array_filter(array_map('intval', $this->agent->hierarchy_array), fn (int $id) => $id > 0));

        return MemberGroup::query()
            ->where('status', 1)
            ->where(function (Builder $q) use ($ancestors, $path) {
                $q->whereIn('agent_id', $ancestors ?: [$this->agent->id])
                    ->orWhereIn('agent_id', fn ($sub) => $sub->select('id')->from('agents')->where('hierarchy_path', 'like', $path . '/%'));
            });
    }

    /** @return Builder<Member> */
    public function membersQuery(): Builder
    {
        $path = (string) $this->agent->hierarchy_path;

        return Member::query()
            ->whereIn('members.parent', fn ($sub) => $sub->select('id')->from('agents')
                ->where('hierarchy_path', $path)
                ->orWhere('hierarchy_path', 'like', $path . '/%'));
    }

    /** @return Collection<int, array{id: int, name: string}> */
    public function groupOptions(): Collection
    {
        return $this->groupsQuery()
            ->orderBy('sequence', 'desc')
            ->orderBy('id')
            ->get(['id', 'name'])
            ->map(fn (MemberGroup $g) => ['id' => (int) $g->id, 'name' => (string) $g->name]);
    }

    /**
     * 下線動輒數萬筆：沒有關鍵字不查、前綴比對、限量回傳。
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function memberOptions(string $keyword): Collection
    {
        $keyword = trim($keyword);
        if ($keyword === '') {
            return collect();
        }

        return $this->membersQuery()
            ->where('members.username', 'like', addcslashes($keyword, '%_\\') . '%')
            ->orderBy('members.username')
            ->limit(self::MEMBER_SEARCH_LIMIT)
            ->get(['members.id', 'members.username'])
            ->map(fn (Member $m) => ['id' => (int) $m->id, 'name' => (string) $m->username]);
    }

    /**
     * 給的 id 是否全部在範圍內（驗證用）。
     *
     * @param  list<int>  $ids
     */
    public function containsAll(bool $isMember, array $ids): bool
    {
        $query = $isMember ? $this->membersQuery()->whereIn('members.id', $ids) : $this->groupsQuery()->whereIn('member_groups.id', $ids);

        return $query->count() === count($ids);
    }
}
