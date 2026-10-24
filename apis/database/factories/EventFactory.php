<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Event\EventTypeEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->word(),
            'content' => fake()->sentence(),
            'type_id' => EventTypeEnum::REGULAR->value,
            'start_time' => now()->subDays(1),
            'end_time' => now()->addDays(1),
            'agent_id' => 0,
            'status' => 1,
            'category' => '',
        ];
    }
}
