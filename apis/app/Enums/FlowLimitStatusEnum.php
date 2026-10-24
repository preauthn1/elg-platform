<?php

declare(strict_types=1);

namespace App\Enums;

enum FlowLimitStatusEnum: int
{
    case PENDING = 0; // 未通過

    case PASSED = 1; // 通過

    case CANCELLED = 9; // 取消稽核
}
