<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventV2DetailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $startDate = now()->subDay(1);
        $endDate = $startDate->copy()->addDays(7);

        return [
            'start_time' => $startDate,
            'end_time' => $endDate,
            'status' => 1,
        ];
    }
}
