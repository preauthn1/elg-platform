<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventDetail>
 */
class EventDetailFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'draw_time' => fake()->dateTime(), // 只有老虎機輸分回扣用到
            'status' => 1,
            'rate' => 0.00,
            'point' => 100,
            'start_time' => now()->subDays(1),
            'end_time' => now()->addDays(1),
        ];
    }
}
