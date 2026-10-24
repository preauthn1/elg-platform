<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

enum EventV2RecycleStatusEnum: int
{
    case SYSTEM_SCHEDULE = 1; // 系統排程回收

    case COMPLETE = 2; // 完成活動回收

    case QUIT = 3; // 放棄活動回收

    case SYSTEM_INIT = 4; // 系統重置回收

    case OPERATOR_QUIT = 5; // 後台操作放棄回收

    case TO_VENDOR_FAILED_REFUND = 6; // 轉入遊戲商失敗退款回收
}
