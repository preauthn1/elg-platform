<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum OrderStatusEnum: int
{
    use HasOptions;

    case PENDING = 1;   // 等待付款
    case SUCCESS = 2;   // 成功
    case FAILED = 3;   // 失敗

    public function getLabel(): string
    {
        return match ($this) {
            self::PENDING => __('enums.order_status.pending'),
            self::SUCCESS => __('enums.order_status.success'),
            self::FAILED => __('enums.order_status.failed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::PENDING => 'warning',
            self::SUCCESS => 'success',
            self::FAILED => 'danger',
        };
    }
}
