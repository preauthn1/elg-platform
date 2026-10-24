<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 通用狀態: 0=停用, 1=啟用
 */
enum StatusEnum: int
{
    case DISABLE = 0; // 停用

    case ENABLE = 1; // 啟用
}
