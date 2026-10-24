<?php

declare(strict_types=1);

namespace App\Enums;

enum OrderStatusEnum: int
{
    case CREATED = 0; // 建立訂單，等待審核

    case SUCCESS = 1; // 建立成功，未上分

    case FAILED = 2; // 建立失敗，審核不通過

    case DEPOSIT_FINISHED = 3; // 三方回調成功，已上分

    case WAITING_MANUAL_DEPOSIT = 4; // 三方回調成功，但充值金額與訂單不符，等待人工確認上分
}
