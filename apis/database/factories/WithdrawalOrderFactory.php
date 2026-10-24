<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\WithdrawalOrderStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\WithdrawalOrder>
 */
class WithdrawalOrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'member_id' => 0,
            'agent_id' => 0,
            'agent_group_id' => 0,
            'amount' => fake()->randomFloat(4, 10, 10000),
            'bank_id' => 0,
            'bank_name' => fake()->company(),
            'bank_code' => Str::random(5),
            'account_name' => Str::random(5),
            'account_no' => Str::random(5),
            'rate' => 1.00000,
            'point' => 0.0000,
            'tax' => 0.00000,
            'third_party_order_no' => fake()->uuid(),
            'third_party_response' => fake()->sentence(),
            'status' => WithdrawalOrderStatusEnum::ORDER_CREATED->value,
            'check_time' => null,
            'audit_time' => null,
            'audit_operator' => null,
            'payment_time' => null,
            'payment_operator' => null,
            'memo' => '',
            'admin' => 0,
            'operator' => 0,
            'change_logs' => '',
        ];
    }
}
