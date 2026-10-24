<?php

declare(strict_types=1);

namespace App\Services\Agent\Traits;

use App\Exceptions\ApiException;
use App\Models\Agent;

/**
 * 推廣代理 GGR（4123 遊戲商設置／4124 條件設置）的可見性軸解析。
 *
 * 兩份設置都只有總代能用，且各總代看到的資料彼此獨立，
 * 因此 list / view / update / create 一律要先拿到操作者所屬的總代 id。
 *
 * general_agent_id 是 Agent 的 accessor：沿 hierarchy_array 往上找
 * role_id = GENERAL_AGENT 的那一層，測試代理或尚未設好角色的帳號會取不到。
 */
trait ResolvesGgrGeneralAgent
{
    /**
     * 操作者所屬總代 id。取不到即拒絕——否則 create 會寫出無歸屬的孤兒資料、
     * list/view 也撈不到任何東西。
     *
     * @throws ApiException 操作者不隸屬任何總代
     */
    protected function resolveGeneralAgentId(Agent $agent): int
    {
        $generalAgentId = $agent->general_agent_id;

        if (!$generalAgentId) {
            throw new ApiException(__('error_message.ggr_setting.general_agent_required'));
        }

        return (int) $generalAgentId;
    }
}
