<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Agent;

/**
 * 上級代理停用 enforcement（工單4130:代理帳號停用機制優化）。
 *
 * 消費點:
 *   - Frontend LoginController::validateMember（會員登入當下給明確錯誤）
 *   - AuthMember / HasMember middleware（每次前台授權請求,停用=禁止登入 → 自然踢線,毋須 revoke token）
 *   - Agent MainController::login / AuthAgent middleware（代理後台,同上）
 *
 * 語意:
 *   - 會員視角:直屬代理即會員的上級,連同 hierarchy_path 上全部祖先,任一 status != 1 即封鎖。
 *   - 代理視角:只看自身以上的祖先（不含自身;自身停用由既有檢查各自處理、訊息不同）。
 *   - 子帳號 hierarchy_path 為「host path + 自身 id」,host 天然在祖先鏈上,毋須特判。
 *   - hierarchy_path 缺失或格式異常時只檢查解析得到的部分,不誤鎖（與可視範疇的 fail-closed
 *     不同:這裡是封鎖判定,解析不到=視為無停用祖先,維持既有可登入行為）。
 *   - 祖先查詢走 whereIn point lookup;instance 生命週期內 memo,同一請求多次消費不重複查 DB。
 */
class AgentAncestorStatusGuard
{
    /** @var array<string, bool> 以祖先 id 集合為 key 的判定快取 */
    private array $memo = [];

    /** 會員視角:直屬代理或其任一上級已停用（直屬代理即會員的上級） */
    public function isMemberAncestorDisabled(?Agent $agent): bool
    {
        if (!$agent) {
            return false;
        }

        if ($agent->status != 1) {
            return true;
        }

        return $this->hasDisabledAgent($this->ancestorIds($agent));
    }

    /** 代理視角:自身以上任一上級已停用（不含自身） */
    public function isAgentAncestorDisabled(Agent $agent): bool
    {
        return $this->hasDisabledAgent($this->ancestorIds($agent));
    }

    /**
     * 從 hierarchy_path 解析祖先 id（排除自身）。
     *
     * @return array<int, int>
     */
    private function ancestorIds(Agent $agent): array
    {
        $ids = array_filter(explode('/', (string) $agent->hierarchy_path), ctype_digit(...));

        $ids = array_map(intval(...), $ids);

        return array_values(array_diff($ids, [(int) $agent->id]));
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function hasDisabledAgent(array $ids): bool
    {
        if ($ids === []) {
            return false;
        }

        sort($ids);
        $key = implode('/', $ids);

        return $this->memo[$key] ??= Agent::query()
            ->whereIn('id', $ids)
            ->where('status', '!=', 1)
            ->exists();
    }
}
