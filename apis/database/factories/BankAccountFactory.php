<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\BankAccount>
 */
class BankAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_name' => 'test',
            'account_nickname' => 'test',
            'account_no' => 'test',
            'is_deposit' => 0,
            'is_withdrawal' => 0,
            'min_amount' => 10.0000,
            'max_amount' => 10000.0000,
            'use_times' => 0,
            'rate' => 1.000,
            'status' => 1,
        ];
    }
}
