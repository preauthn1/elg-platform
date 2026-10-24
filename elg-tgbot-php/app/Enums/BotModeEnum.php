<?php

namespace App\Enums;

enum BotModeEnum: int
{
    /**
     * 一代, 使用 chat_id 註冊跟登入
     */
    case FIRST = 1;
    /**
     * 二代, 使用 telegram 的 sdk 取得 user_id 註冊跟登入
     */
    case SECOND = 2;
}
