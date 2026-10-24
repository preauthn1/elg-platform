<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CashFlowSeeder extends Seeder
{
    public function run(): void
    {
        $list = [
            ['id' => 5, 'third_party_code' => 'D_LOCAL', 'name' => 'AutoDeposit', 'rate' => '1', 'percentage' => '0', 'type' => '2', 'status' => '1', 'remark' => '自助開分', 'info' => '', 'admin' => '0'],
            ['id' => 6, 'third_party_code' => 'W_LOCAL', 'name' => 'AutoWithdrawal', 'rate' => '1', 'percentage' => '0', 'type' => '4', 'status' => '1', 'remark' => '自助洗分', 'info' => '', 'admin' => '0'],
        ];

        DB::table('cash_flows')->upsert($list, ['id']);
    }
}
