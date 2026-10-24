<?php

namespace App\Enums\Db;

use App\Enums\Concerns\HasOptions;

enum WalletDeploymentStatusEnum: int
{
    use HasOptions;

    case NOT_DEPLOYED = 0;   // 尚未部屬
    case DEPLOYING = 1;   // 部屬中
    case DEPLOYED = 2;   // 已部屬

    public function getLabel(): string
    {
        return match ($this) {
            self::NOT_DEPLOYED => __('enums.wallet_deployment_status.not_deployed'),
            self::DEPLOYING => __('enums.wallet_deployment_status.deploying'),
            self::DEPLOYED => __('enums.wallet_deployment_status.deployed'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::NOT_DEPLOYED => 'danger',
            self::DEPLOYING => 'warning',
            self::DEPLOYED => 'success',
        };
    }
}
