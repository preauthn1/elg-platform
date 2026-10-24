<?php

declare(strict_types=1);

namespace App\Services\Agent;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;
use App\Services\MemberAccessTokenService;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * 在線會員。
 *
 * 「在線」定義＝持有未過期 access token（member_access_tokens.expire_date > now()）；
 * 最後活動時間（member_statistics.last_activity_at）僅作顯示欄位，不參與在線判定。
 * issue() 發新 token 前會先 revoke，正常情況每會員至多一筆有效 token。
 */
class MemberOnlineService
{
    public function __construct(
        private MemberAccessTokenService $memberAccessTokenService,
    ) {}

    public function list(Agent $agent, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->baseQuery($agent);

        if (!empty($filters['username'])) {
            $query->where('m.username', $filters['username']);
        }
        if (!empty($filters['name'])) {
            $query->where('m.first_name', 'like', '%' . $filters['name'] . '%');
        }
        if (!empty($filters['ip'])) {
            $ip = ip2long($filters['ip']);
            // last_login_ip 以 ip2long 整數儲存；無效 IP 直接無資料
            $query->where('m.last_login_ip', $ip === false ? -1 : $ip);
        }

        $total = (clone $query)->count('m.id');

        $rows = $query->select([
            'm.id as member_id',
            'm.username',
            'm.first_name',
            'mg.name as group_name',
            'm.last_login',
            'm.last_login_ip',
            'ms.last_activity_at',
        ])
            ->orderByRaw('ms.last_activity_at IS NULL')
            ->orderByDesc('ms.last_activity_at')
            ->orderByDesc('m.last_login')
            ->orderByDesc('m.id')
            ->forPage($page, $perPage)
            ->get();

        return new LengthAwarePaginator($rows, $total, $perPage, $page);
    }

    /**
     * 強制登出：作廢會員全部有效 token（含 Redis 快取），前台下一個請求即被登出。
     */
    public function kick(Agent $agent, int $memberId): void
    {
        $member = Member::find($memberId);
        if (!$member) {
            throw new ApiException(__('error_message.1005'));
        }

        if (!$this->isAdmin($agent) && !$this->memberInScope($agent, $member)) {
            throw new ApiException(__('error_message.1007'));
        }

        $this->memberAccessTokenService->revoke($memberId);
    }

    /**
     * 在線會員 base query，含可視範疇：自己直屬＋下線（admin 不限，子帳號以主帳號為準）。
     *
     * 在線判定用 whereExists（而非 join token 表），確保一會員一列；
     * hierarchy_path 為空時 fail-closed（只看直屬會員）；
     * 下線比對用 '{path}/%'，避免代理 id 前綴碰撞（/1/23 誤匹配 /1/234）。
     */
    private function baseQuery(Agent $agent): Builder
    {
        $query = DB::table('members as m')
            ->join('agents as a', 'a.id', '=', 'm.parent')
            ->leftJoin('member_statistics as ms', 'ms.member_id', '=', 'm.id')
            ->leftJoin('member_groups as mg', 'mg.id', '=', 'm.member_group_id')
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('member_access_tokens as t')
                    ->whereColumn('t.member_id', 'm.id')
                    ->where('t.expire_date', '>', date('Y-m-d H:i:s'));
            });

        if ($this->isAdmin($agent)) {
            return $query;
        }

        $ownerId = $this->ownerId($agent);
        $path = $this->effectiveHierarchyPath($agent);

        if ($path === null || $path === '') {
            $query->where('a.id', $ownerId);

            return $query;
        }

        $query->where(function (Builder $q) use ($ownerId, $path) {
            $q->where('a.id', $ownerId)
                ->orWhere('a.hierarchy_path', 'like', $path . '/%');
        });

        return $query;
    }

    /** 與 baseQuery 同語意：直屬會員 OR 下線代理的會員；無階層路徑時 fail-closed 只允許直屬。 */
    private function memberInScope(Agent $agent, Member $member): bool
    {
        if ((int) $member->parent === $this->ownerId($agent)) {
            return true;
        }

        $hierarchyPath = $this->effectiveHierarchyPath($agent);
        if ($hierarchyPath === null || $hierarchyPath === '') {
            return false;
        }

        $memberAgent = Agent::find($member->parent);
        if (!$memberAgent) {
            return false;
        }

        // 結尾補 '/' 比對，避免代理 id 前綴碰撞（/1/23 誤匹配 /1/234）
        return str_starts_with((string) $memberAgent->hierarchy_path, $hierarchyPath . '/');
    }

    /** 子帳號（host_id != 0）以其主帳號的階層為準。 */
    private function effectiveHierarchyPath(Agent $agent): ?string
    {
        $path = $agent->hierarchy_path;
        if ($agent->host_id != 0) {
            $upper = Agent::find($agent->host_id);
            $path = $upper->hierarchy_path ?? $path;
        }

        return $path;
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
