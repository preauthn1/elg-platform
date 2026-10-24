<?php

declare(strict_types=1);

namespace App\Enums\Member;

/**
 * 會員遊戲限制的對象範圍 工單4126
 *
 * 決定 member_game_restriction_members 使用哪一個欄位：
 *   MEMBER → member_id（指定會員，可多筆）
 *   GROUP  → member_group_id（整個會員群組；會員只屬單一群組，成員異動即時生效）
 */
enum MemberRestrictScopeEnum: int
{
    case MEMBER = 1; // 指定會員
    case GROUP = 2;  // 會員群組
}
