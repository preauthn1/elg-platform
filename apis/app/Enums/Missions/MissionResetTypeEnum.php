<?php

declare(strict_types=1);

namespace App\Enums\Missions;

use App\Models\Mission;
use Carbon\Carbon;

enum MissionResetTypeEnum: string
{
    case DAILY = 'daily';

    case WEEKLY = 'weekly';

    case MONTHLY = 'monthly';

    case ONE_TIME = 'one_time';

    public function translate(): array
    {
        return match ($this) {
            self::DAILY => __('missions.reset_type.' . self::DAILY),
            self::WEEKLY => __('missions.reset_type.' . self::WEEKLY),
            self::MONTHLY => __('missions.reset_type.' . self::MONTHLY),
            self::ONE_TIME => __('missions.reset_type.' . self::ONE_TIME),
        };
    }

    public function getTimeRange(Mission $mission, ?string $time = null): array
    {
        $now = $time === null ? Carbon::now() : Carbon::parse($time);

        return match ($this) {
            self::DAILY => [
                $now->copy()->toFrontendTime()->startOfDay()->toSystemTime()->toDateTimeString(),
                $now->copy()->toFrontendTime()->startOfDay()->addDay()->toSystemTime()->toDateTimeString(),
            ],
            self::WEEKLY => [
                $now->copy()->toFrontendTime()->startOfWeek()->toSystemTime()->toDateTimeString(),
                $now->copy()->toFrontendTime()->startOfWeek()->addWeek()->toSystemTime()->toDateTimeString(),
            ],
            self::MONTHLY => [
                $now->copy()->toFrontendTime()->startOfMonth()->toSystemTime()->toDateTimeString(),
                $now->copy()->toFrontendTime()->endOfMonth()->addSecond()->toSystemTime()->toDateTimeString(),
            ],
            self::ONE_TIME => [
                $mission->start_time,
                $mission->end_time,
            ],
            default => throw new \Exception("Invalid reset type: {$this->value}")
        };
    }
}
