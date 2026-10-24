<?php

declare(strict_types=1);

namespace App\Enums\EventProgressiveJackpot;

enum EventProgressiveJackpotUserJoinStatusEnum: int
{
    case IN_PROGRESS = 0; // 進行中

    case WAITING_UNLOCK = 1; // 等待解鎖

    case COMPLETED = 2; // 已完成

    case EXPIRED = 3; // 已過期

    case LOCKED = 4; // 已鎖定(IP、手機號、真實姓名重複)

    case CLOSED = 5; // 已關閉(已完成且領取完畢，不再變動)
}
