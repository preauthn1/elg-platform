<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VendorHourRecord>
 */
class VendorHourRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $bet = $this->faker->numberBetween(1000, 10000);
        $win = $this->faker->numberBetween(1000, 10000);
        $lastBetTime = date('Y-m-d H:i:s', strtotime('-10 minutes'));

        return [
            'date_hour' => date('Y-m-d H:00:00', strtotime($lastBetTime)),
            'game_id' => $this->faker->numberBetween(10, 10000),
            'bet' => $bet,
            'win' => $win,
            'win_lose' => $win - $bet,
            'last_bet_time' => $lastBetTime,
            'valid_bet_rate' => 0.00,
            'vendor_id' => $this->faker->numberBetween(1, 150),
            'member_id' => $this->faker->numberBetween(1, 150),
        ];
    }
}
