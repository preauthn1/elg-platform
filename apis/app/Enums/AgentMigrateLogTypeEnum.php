<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * agent_migrate_logs.type：被搬移的身份。
 *
 * 語意目前只有 migration 註解「身份; 1:代理, 2:會員」一個來源：2026-09-07 六站實查此表皆為空表，
 * 本機的四個舊 repo（API_Agent／API_Frontend／API_External／API_CustomerServiceBackend）也找不到
 * 寫入端，值的語意尚未經資料反證。APIs 只讀不寫這張表。
 */
enum AgentMigrateLogTypeEnum: int
{
    /** 代理 */
    case AGENT = 1;

    /** 會員 */
    case MEMBER = 2;
}
