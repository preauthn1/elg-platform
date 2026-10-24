<?php

declare(strict_types=1);

namespace App\Enums;

enum FlowLimitTypeEnum: int
{
    case EVENT_LOCK_MODE = 105; // 鎖分模式；SP_calc_flow_limit 硬編碼判斷此值才會在達標時解除 members.event_mode，數值不可更動
}
