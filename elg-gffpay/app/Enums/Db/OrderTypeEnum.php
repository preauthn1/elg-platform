<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum OrderTypeEnum: int
{
    use HasOptions;

    case DEPOSIT = 1;   // 代收, 用戶轉錢到子錢包
    case WITHDRAW = 2;   // 代付, 主錢包轉錢到用戶
    case SWEEP = 3;   // 歸集, 將子錢包的錢轉到主錢包

    public function getLabel(): string
    {
        return match ($this) {
            self::DEPOSIT => __('enums.order_type.deposit'),
            self::WITHDRAW => __('enums.order_type.withdraw'),
            self::SWEEP => __('enums.order_type.sweep'),
        };
    }
}
