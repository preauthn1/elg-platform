<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum OrderLogTypeEnum: int
{
    use HasOptions;

    case CREATE = 1;   // 建立訂單紀錄
    case TRANSACTION = 2;   // ton交易紀錄
    case CALLBACK = 3;   // callback紀錄

    public function getLabel(): string
    {
        return match ($this) {
            self::CREATE => __('enums.order_log_type.create'),
            self::TRANSACTION => __('enums.order_log_type.transaction'),
            self::CALLBACK => __('enums.order_log_type.callback'),
        };
    }
}
