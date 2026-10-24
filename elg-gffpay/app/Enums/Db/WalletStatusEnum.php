<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum WalletStatusEnum: int
{
    use HasOptions;

    case IDLE = 1;   // 閒置
    case PENDING = 2;   // 等待付款
    case COOLING = 3;   // 冷卻中

    public function getLabel(): string
    {
        return match ($this) {
            self::IDLE => __('enums.wallet_status.idle'),
            self::PENDING => __('enums.wallet_status.pending'),
            self::COOLING => __('enums.wallet_status.cooling'),
        };
    }
}
