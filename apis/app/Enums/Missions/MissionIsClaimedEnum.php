<?php

declare(strict_types=1);

namespace App\Enums\Missions;

enum MissionIsClaimedEnum: int
{
    /**
     * 未領取
     */
    case NOT_CLAIMED = 0;

    /**
     * 已領取
     */
    case IS_CLAIMED = 1;

    public function translate()
    {
        return match ($this) {
            self::NOT_CLAIMED => __('param.unaccalimed'),
            self::IS_CLAIMED => __('param.received'),
        };
    }
}
