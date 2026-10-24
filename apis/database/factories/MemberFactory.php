<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Member>
 */
class MemberFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'balance' => 0.00,
            'username' => 'testuser',
            'nickname' => 'testuser',
            'password' => static::$password ??= Hash::make('password'),
            'admin' => 0,
        ];
    }

    public function realnameValidated(): static
    {
        return $this->state(fn (array $attributes) => [
            'first_name' => 'Test',
            'last_name' => 'User',
            'realname_validate' => 1,
        ]);
    }
}
