<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

enum EventV2UserJoinStatusEnum: int
{
    case JOIN = 1; // 參加活動

    case COMPLETE = 2; // 完成活動

    case QUIT = 3; // 放棄活動

    case SYSTEM_INIT = 4; // 系統重置活動

    case OPERATOR_QUIT = 5; // 後台操作放棄
}
