<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformAnnouncementReadFactory extends Factory
{
    public function definition(): array
    {
        return [
            'member_id' => 1,
            'last_read_time' => Carbon::now()->toDateTimeString(),
        ];
    }
}
