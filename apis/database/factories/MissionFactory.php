<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Missions\MissionActionTypeEnum;
use App\Enums\Missions\MissionConditionTypeEnum;
use App\Enums\Missions\MissionResetTypeEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Mission>
 */
class MissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->name,
            'status' => 0,
            'reset_type' => MissionResetTypeEnum::DAILY->value,
            'action_type' => MissionActionTypeEnum::DEPOSIT->value,
            'condition_type' => MissionConditionTypeEnum::TIMES->value,
            'condition_value' => 1,
            'description' => $this->faker->sentence,
            'start_time' => date('Y-m-d H:i:s', strtotime('-1 day')),
            'end_time' => date('Y-m-d H:i:s', strtotime('+1 day')),
            'info' => [
                'action_config' => ['type' => 'deposit'],
                'reward_config' => ['type' => 'balance', 'value' => '100'],
                'condition_config' => ['type' => 'times', 'value' => '10', 'fix_number' => ''],
            ],
            'condition_type' => 'times',
            'condition_value' => 10,
        ];
    }
}
