<?php

declare(strict_types=1);

namespace App\Services\LivechatBot;

use App\Exceptions\ApiException;
use App\Models\Member;

/**
 * Livechat Bot 跨 agent 存取防護。
 *
 * 規則：
 * - bot 綁定的 agent_id 必須出現在 member.agent.hierarchy_array 中（含自身或祖先），
 *   否則視為跨代理存取，統一回 404，避免洩漏「此 user 存在但你無權查」。
 */
class MemberAgentGuard
{
    /**
     * @throws ApiException 當 member.agent 不存在或 bot 的 agent 不在 member 的階層上時拋出 404
     */
    public function assert(Member $member, LivechatBotContext $context): void
    {
        $agent = $member->agent;

        if ($agent === null) {
            throw new ApiException('Member not found', 404);
        }

        $hierarchy = array_map(static fn ($id): int => (int) $id, $agent->hierarchy_array ?? []);

        if (!in_array($context->agentId(), $hierarchy, true)) {
            throw new ApiException('Member not found', 404);
        }
    }
}
