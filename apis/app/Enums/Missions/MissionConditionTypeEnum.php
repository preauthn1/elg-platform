<?php

declare(strict_types=1);

namespace App\Enums\Missions;

enum MissionConditionTypeEnum: string
{
    case TIMES = 'times'; // 行為次數

    case AMOUNT = 'amount'; // 累積金額

    case FIX_NUMBER_TIMES = 'fix_number_times'; // 固定數值以上進行數次

    public function translate()
    {
        return match ($this) {
            self::TIMES => __('missions.condition_type.' . self::TIMES->value),
            self::AMOUNT => __('missions.condition_type.' . self::AMOUNT->value),
            self::FIX_NUMBER_TIMES => __('missions.condition_type.' . self::FIX_NUMBER_TIMES->value),
        };
    }
}
