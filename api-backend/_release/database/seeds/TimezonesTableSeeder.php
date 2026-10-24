<?php

use Illuminate\Database\Seeder;
use App\Timezone;

class TimezonesTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        for ($i = 12; $i > 0; $i--) {
            Timezone::create([
                'time_offset' => 'UTC-' . str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
                'is_summer' => 0,
                'summer_start' => '0000-01-01',
                'summer_end' => '0000-01-01',
                'status' => 1,
                'admin' => 1,
            ]);
        }
        Timezone::create([
            'time_offset' => 'UTC±00:00',
            'is_summer' => 0,
            'summer_start' => '0000-01-01',
            'summer_end' => '0000-01-01',
            'status' => 1,
            'admin' => 1,
        ]);

        for ($i = 1; $i < 15; $i++) {
            Timezone::create([
                'time_offset' => 'UTC+' . str_pad($i, 2, '0', STR_PAD_LEFT) . ':00',
                'is_summer' => 0,
                'summer_start' => '0000-01-01',
                'summer_end' => '0000-01-01',
                'status' => 1,
                'admin' => 1,
            ]);
        }
    }
}
