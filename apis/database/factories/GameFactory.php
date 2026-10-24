<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Game>
 */
class GameFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type_id' => 0,
            'name' => 'test',
            'game_type' => 'TEST',
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid',
            'admin' => 0,
        ];
    }
}
