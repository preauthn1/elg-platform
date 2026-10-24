<?php

use Illuminate\Database\Seeder;
use App\Models\User;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        // Let's clear the users table first
        User::truncate();
        $faker = \Faker\Factory::create();
        // Let's make sure everyone has the same password and
        // let's hash it before the loop, or else our seeder
        // will be too slow.
        $password = [REDACTED]'toptal');
        User::create([
            'name' => 'Administrator',
            'parent' => 1,
            'code' => '123abc',
            'email' => 'user@example.invalid',
            'password' => $password,
            'mobile' => $faker->phoneNumber,
            'country_id' => 1,
            'zip_code' => 12345,
            'district' => $faker->century,
            'city' => $faker->city,
            'address' => $faker->streetAddress,
            'admin' => 1,
        ]);
        // And now let's generate a few dozen users for our app:
        for ($i = 0; $i < 10; $i++) {
            User::create([
                'name' => $faker->name,
                'parent' => 1,
                'code' => $faker->randomAscii,
                'email' => $faker->email,
                'password' => $password,
                'mobile' => $faker->phoneNumber,
                'country_id' => 1,
                'zip_code' => 12345,
                'district' => $faker->century,
                'city' => $faker->city,
                'address' => $faker->streetAddress,
                'admin' => 1,
            ]);

        }
    }
}