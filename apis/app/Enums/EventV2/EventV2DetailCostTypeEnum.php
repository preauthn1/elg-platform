<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

enum EventV2DetailCostTypeEnum: int
{
    case JOIN = 1; // 參加活動紀錄成本

    case COMPLETE = 2; // 完成活動紀錄成本
}
