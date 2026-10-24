<?php

declare(strict_types=1);

namespace App\Services\Agent\Report\Sr02n;

use App\Data\Report\Sr02nScope;
use App\Enums\Agent\ReservedAgentIdEnum;
use App\Models\Agent;

/**
 * SR02N 資料可見範圍解析（沿用 SR02 的登入視角，target/tickets#4356 D3）。
 *
 * - 會員兩段：未指定代理時限登入者（子帳號取主帳號）線下，Admin 線不限；指定代理時限該代理線下。
 * - 代理兩段：操作者須在登入者主帳號路徑下（Admin 用 `/1`），另由各段依 targetAgent／hostId 收斂。
 * - agent_username 查無或不在線下：回 {@see Sr02nScope::empty()}，等價 SR02 的 `1=2`，但不必打 union。
 *
 * @knowledge rules/report-sr02n
 */
class Sr02nScopeResolver
{
    public function resolve(Agent $viewer, string $agentUsername): Sr02nScope
    {
        $hostId = $viewer->host_id != 0 ? (int) $viewer->host_id : (int) $viewer->id;
        $hostPath = $viewer->isPlatformAdmin()
            ? '/' . ReservedAgentIdEnum::ADMIN->value
            : (string) $viewer->visibleScopePath();

        if ($agentUsername === '') {
            return new Sr02nScope(
                scopePath: $viewer->visibleScopePath(),
                hostPath: $hostPath,
                hostId: $hostId,
                targetAgent: null,
            );
        }

        $target = Agent::query()->where('username', $agentUsername)->first();

        if ($target === null || !$viewer->canSeeAgent($target->id)) {
            return Sr02nScope::empty();
        }

        return new Sr02nScope(
            scopePath: (string) $target->hierarchy_path,
            hostPath: $hostPath,
            hostId: $hostId,
            targetAgent: $target,
        );
    }
}
