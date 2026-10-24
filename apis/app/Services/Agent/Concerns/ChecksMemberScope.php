<?php

declare(strict_types=1);

namespace App\Services\Agent\Concerns;

use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Member;

/**
 * 單一會員的可視範疇檢查（4274 會員詳情抽屜）。
 *
 * 規則與舊後台會員清單（API_Agent member/list）完全對齊——工單明定
 * 「可見範圍＝與既有會員清單完全一致（整棵下線）」：
 *  - 主帳號（host_id=0）→ 用自己的 hierarchy_path 當前綴
 *  - 子帳號 → 用 parent（上層代理，須啟用中）的 hierarchy_path；上級停用/查無 → fail-closed 全擋
 *  - admin（id=1）與其子帳號（host_id=1）→ 不限制
 *  - 比對用單段 LIKE 'path%'，刻意與 member/list 同款（兩段式較嚴會造成
 *    「列表看得到、抽屜打不開」的不一致；path 前綴撞號邊界屬既有行為，要修須連 member/list 一起）
 *
 * 抽屜接口刻意不掛 CheckAgentPermission 選單碼（點擊入口跨全站報表/活動等各種權限頁面，
 * 綁碼會讓僅持報表權限的人員點不開；member/list 本身亦無選單碼防線）——資料防線即本檢查。
 */
trait ChecksMemberScope
{
    /**
     * 驗證會員存在且在操作者可視範疇內，回傳該會員 Model。
     *
     * @throws ApiException member not found（查無此會員）／member out of scope（無權限查看此會員）
     */
    private function findMemberInScope(Agent $agent, int $memberId): Member
    {
        return $this->assertModelInScope($agent, Member::query()->find($memberId));
    }

    /**
     * 以帳號查會員並驗範疇（供只有帳號字串、無 member_id 的頁面開抽屜：聊天表頭、部分報表列）。
     *
     * @throws ApiException
     */
    private function findMemberInScopeByUsername(Agent $agent, string $username): Member
    {
        return $this->assertModelInScope(
            $agent,
            Member::query()->where('username', $username)->first(),
        );
    }

    /**
     * @throws ApiException
     */
    private function assertModelInScope(Agent $agent, ?Member $member): Member
    {
        if ($member === null) {
            throw new ApiException('member not found');
        }

        $scopePath = $this->memberListScopePath($agent);

        // admin 及其子帳號不限制
        if ($scopePath === null) {
            return $member;
        }

        $inScope = $scopePath !== '' && Agent::query()
            ->where('id', $member->parent)
            ->where('hierarchy_path', 'like', $scopePath . '%')
            ->exists();

        if (!$inScope) {
            throw new ApiException('member out of scope');
        }

        return $member;
    }

    /**
     * 可視範疇前綴（member/list 對齊版）：null＝不限制、空字串＝fail-closed 全擋。
     */
    private function memberListScopePath(Agent $agent): ?string
    {
        if ($agent->id == 1 || $agent->host_id == 1) {
            return null;
        }

        if ($agent->host_id == 0) {
            return (string) $agent->hierarchy_path;
        }

        // 子帳號：取上層代理（parent，與 member/list 的 Authenticate 中介層同源）的範疇；
        // 上級停用或查無 → 空字串 fail-closed（member/list 情境由登入層 hasDisabledAncestor 先擋）
        $upper = Agent::query()
            ->where('id', $agent->parent)
            ->where('status', 1)
            ->first();

        return (string) ($upper->hierarchy_path ?? '');
    }
}
