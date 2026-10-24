<?php

declare(strict_types=1);

namespace App\Enums\EventV2;

enum EventV2PhoneBlockEnum: int
{
    case ALL_MEMBER = 1; // 檢查全站會員註冊手機號碼

    case ALL_EVENT = 2; // 檢查所有活動參加或領取過的手機號碼

    case EVENT_V2 = 3; // 檢查活動V2參加過的手機號碼

}
