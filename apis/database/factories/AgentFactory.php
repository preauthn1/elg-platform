<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Agent>
 */
class AgentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'parent' => 0,
            'code' => Str::random(10),
            'username' => mb_substr($this->faker->name, 0, 32), // agents.username varchar(32)，faker name 可能超長（如 Prof./DDS 前後綴）
            'email' => $this->faker->email,
            'password' => Hash::make('123456'),
            'status' => 1,
            'admin' => 1,
            'frontend_register' => 1, // For member creation
            'organization_id' => 1,
            'customized_setting_group_id' => 1,
            'vendor_customize_group_id' => 1,
            'cash_flow_group_id' => 1,
            'sms_gateway_group_id' => 1,
        ];
    }
}
