<?php

declare(strict_types=1);

namespace App\Enums;

enum InformationTypeEnum: int
{
    /**
     * 無連結
     */
    case NONE = 0;

    /**
     * 連結至遊戲類型
     */
    case GAME_TYPE = 1;

    /**
     * 連結至遊戲
     */
    case GAME = 2;

    /**
     * 連結至遊戲商
     */
    case VENDOR = 3;

    /**
     * 外部連結
     */
    case URL = 4;

    /**
     * 內部連結
     */
    case INTERNAL_URL = 5;
}
