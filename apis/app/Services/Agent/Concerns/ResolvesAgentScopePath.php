<?php

declare(strict_types=1);

namespace App\Services\Agent\Concerns;

use App\Models\Agent;

/**
 * 代理可視範疇的 hierarchy_path 解析（4199 抽出，供三層代各查詢 Service 共用）。
 */
trait ResolvesAgentScopePath
{
    /**
     * 取得可視範疇的 hierarchy_path 前綴；admin(id=1)與其子帳號(host_id=1)回 null 表不限制。
     * 代理子帳號(host_id!=0)看其上層代理的範疇（比照 MemberActivityFilterService）。
     */
    private function scopePath(Agent $agent): ?string
    {
        if ($agent->id == 1 || $agent->host_id == 1) {
            return null;
        }

        $path = $agent->hierarchy_path;
        if ($agent->host_id != 0) {
            $upperAgent = Agent::where('id', $agent->host_id)->first();
            $path = $upperAgent->hierarchy_path ?? $path;
        }

        return $path;
    }
}
