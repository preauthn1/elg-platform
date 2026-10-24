<?php

declare(strict_types=1);

namespace App\Services\Payment\Enums;

enum ProviderOrderStatusEnum: int
{
    case PENDING = 0; // 初始建立
    case PROCESSING = 1; // 处理中
    case SUCCESS = 2; // 成功
    case SUCCESS_CALLBACK = 3; // 成功且收到我司回調
    case CANCEL = 4; // 取消
    case REFUND = 5; // 退款

    // ! 負面場景使用負數
    case ERROR = -1; // 錯誤
    case FAIL = -2; // 失败
    case FAIL_CALLBACK = -3; // 失败且收到我司回調
    case EXPIRED = -4; // 过期
    case CLOSED = -5; // 关闭
}
