<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformAnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return [
            'agent_id' => 1,
            'start_time' => Carbon::now()->startOfDay(),
            'end_time' => Carbon::now()->addDays(1)->endOfDay(),
            'tag' => 1,
            'type' => 1,
            'title' => 'default title en',
            'content' => 'default content en',
            'platform_announcement_image_id' => 1,
            'status' => 0,
        ];
    }
}
