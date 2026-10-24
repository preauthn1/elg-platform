<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum EnableEnum: int
{
    use HasOptions;

    case ENABLE = 1;   // 啟用
    case DISABLE = 0;   // 停用

    public function getLabel(): string
    {
        return match ($this) {
            self::ENABLE => __('enums.enable.enable'),
            self::DISABLE => __('enums.enable.disable'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::ENABLE => 'success',
            self::DISABLE => 'danger',
        };
    }
}
