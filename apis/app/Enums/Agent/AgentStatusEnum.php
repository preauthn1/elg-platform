<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * 代理帳號狀態。
 *
 * DISABLED / ENABLED 對應 agents.status。
 * 限制登入為獨立維度（agents.sys_lock），列表篩選與
 * agent_status_logs 皆以獨立的 sys_lock 欄位表達，不混用本狀態值。
 */
enum AgentStatusEnum: int
{
    case DISABLED = 0;
    case ENABLED = 1;
}
