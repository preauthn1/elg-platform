<?php

declare(strict_types=1);

namespace App\Enums\Missions;

enum MissionRewardTypeEnum: string
{
    /**
     * 平台點數
     */
    case BALANCE = 'balance';

    /**
     * 禮碼
     */
    case ROLLING_CHIP = 'rolling_chip';

    /**
     * 商城點數
     */
    case SHOP_POINT = 'shop_point';

    public function translate()
    {
        return match ($this) {
            self::BALANCE => __('param.platform_point'),
            self::ROLLING_CHIP => __('param.rolling_chip'),
            // self::SHOP_POINT => __('missions.reward_type.' . self::SHOP_POINT),
        };
    }
}
