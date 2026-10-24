<?php

declare(strict_types=1);

namespace App\Enums\Vendor;

/**
 * 關閉類型: 1=遊戲跟錢包都關閉, 2=關閉遊戲, 3=關閉錢包
 */
enum VendorMaintainTypeEnum: int
{
    case BOTH_CLOSED = 1; // 遊戲跟錢包都關閉

    case GAME_CLOSED = 2; // 關閉遊戲

    case WALLET_CLOSED = 3; // 關閉錢包
}
