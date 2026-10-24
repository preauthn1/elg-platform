<?php

declare(strict_types=1);

namespace App\Enums\Vendor;

/**
 * 遊戲維護的解鎖方式 工單4120
 *
 * MANUAL 為保留選項：4120 只實作自動解鎖，手動需改動前台的生效判定
 * （現行判定看 end_time，手動模式得改成只看 status），待需求確認後再開。
 */
enum GameMaintainUnlockTypeEnum: int
{
    case AUTO = 1; // 自動：結束時間一到自動解鎖

    case MANUAL = 2; // 手動：結束時間到不解鎖，需人工關閉（尚未實作）
}
