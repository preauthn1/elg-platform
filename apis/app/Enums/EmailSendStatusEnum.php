<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 電子郵件發送狀態
 */
enum EmailSendStatusEnum: int
{
    /** 未發送 */
    case PENDING = 0;
    /** 發送成功 */
    case SUCCESS = 1;
    /** 發送失敗 */
    case FAILED = 2;
}
