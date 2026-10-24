<?php

declare(strict_types=1);

namespace App\Enums\Agent;

/**
 * 系統保留的代理帳號 id（跨系統契約，各站點固定）。
 */
enum ReservedAgentIdEnum: int
{
    case ADMIN = 1;   // 平台 Admin（其子帳號 host_id=1 同屬 Admin 線）
    case SYSTEM = 51; // System：系統自動操作的操作者（例：佔成錢包結算）
}
