<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\OrderStatusEnum;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_no' => Str::random(10),
            'cash_flow_info_id' => 0,
            'third_party_order_no' => '',
            'cash_flow_id' => null,
            'currency_id' => 1,
            'agent_id' => 0,
            'amount' => 100,
            'rate' => 1,
            'point' => 100,
            'balance' => 0,
            'remark' => '',
            'status' => OrderStatusEnum::CREATED->value,
        ];
    }
}
