<?php

declare(strict_types=1);

namespace App\Enums\Member;

/**
 * 會員遊戲限制的限制類型 工單4126
 *
 * 決定 member_game_restriction_targets.target_id 的解讀方式，
 * 以及前台由哪一支查詢併入：
 *   VENDOR → VendorService::getMaintenanceVendorIds（遊戲商整批灰化）
 *   GAME   → VendorService::getMaintenanceGameMap（單一遊戲灰化）
 */
enum MemberRestrictTypeEnum: int
{
    case VENDOR = 1; // 遊戲商：target_id 為 vendors.id
    case GAME = 2;   // 遊戲：target_id 為 games.id
}
