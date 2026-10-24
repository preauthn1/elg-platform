<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class WithdrawalTypeSeeder extends Seeder
{
    public function run(): void
    {
        $withdrawalTypes = [
            ['id' => 1, 'name' => 'Bank'],
            ['id' => 2, 'name' => 'GCash'],
            ['id' => 3, 'name' => 'Cryptocurrency'],
            ['id' => 4,  'name' => 'Foreign currency bank'],
            ['id' => 5,  'name' => 'Cash'],
            ['id' => 6, 'name' => 'Australia Bank'],
            ['id' => 7,  'name' => 'Australia Pay Id'],
        ];
        foreach ($withdrawalTypes as $key => $row) {
            $withdrawalTypes[$key]['status'] = 1;
            $withdrawalTypes[$key]['admin'] = 1;
        }
        DB::table('withdrawal_types')->upsert($withdrawalTypes, ['id']);
    }
}
