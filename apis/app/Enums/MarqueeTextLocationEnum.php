<?php

declare(strict_types=1);

namespace App\Enums;

enum MarqueeTextLocationEnum: int
{
    /** 大廳 */
    case LOBBY = 1;
    /** 聊天室 */
    case CHAT = 2;
}
