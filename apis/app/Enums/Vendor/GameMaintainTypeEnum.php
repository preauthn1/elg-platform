<?php

declare(strict_types=1);

namespace App\Enums\Vendor;

/**
 * 遊戲維護的關閉類型 工單4120
 *
 * ⚠️ 編號與 VendorMaintainTypeEnum 不同義：該 enum 的 1 是「遊戲跟錢包都關閉」，
 * 本 enum 是獨立的一套，目前只有關閉遊戲一種（工單需求固定，介面不給編輯）。
 */
enum GameMaintainTypeEnum: int
{
    case GAME_CLOSED = 1; // 關閉遊戲
}
