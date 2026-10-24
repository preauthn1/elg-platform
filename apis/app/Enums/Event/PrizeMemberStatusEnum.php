<?php

declare(strict_types=1);

namespace App\Enums\Event;

enum PrizeMemberStatusEnum: int
{
    case UNAWARD = 0; // 未領獎
    case AWARD = 1; // 已領獎
    case CANCEL = 2; // 取消
}
