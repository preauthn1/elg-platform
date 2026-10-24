<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class CountrySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $datas = [
            ['id' => 1, 'code' => 'MY', 'name' => 'Malaysia', 'country_code' => '60', 'phone_rule' => '^[1-9]\d{8,9}$', 'status' => 1, 'admin' => 0],
            ['id' => 2, 'code' => 'SG', 'name' => 'Singapore', 'country_code' => '65', 'phone_rule' => '^[1-9]\d{7}$', 'status' => 1, 'admin' => 0],
            ['id' => 3, 'code' => 'CN', 'name' => 'China', 'country_code' => '86', 'phone_rule' => '^[1-9]\d{10}$', 'status' => 1, 'admin' => 0],
            ['id' => 4, 'code' => 'TW', 'name' => 'Taiwan', 'country_code' => '886', 'phone_rule' => '^[1-9]\d{8}$', 'status' => 1, 'admin' => 0],
            ['id' => 5, 'code' => 'TH', 'name' => 'Thailand', 'country_code' => '66', 'phone_rule' => '^[1-9]\d{8}$', 'status' => 1, 'admin' => 0],
            ['id' => 6, 'code' => 'LA', 'name' => 'Laos', 'country_code' => '856', 'phone_rule' => '^[1-9]\d{10}$', 'status' => 1, 'admin' => 0],
            ['id' => 7, 'code' => 'ZA', 'name' => 'South Africa', 'country_code' => '27', 'phone_rule' => '^[1-9]\d{10}$', 'status' => 1, 'admin' => 0],
            ['id' => 8, 'code' => 'BN', 'name' => 'Brunei', 'country_code' => '673', 'phone_rule' => '^[0-9]\d{6}$', 'status' => 1, 'admin' => 0],
            ['id' => 9, 'code' => 'AU', 'name' => 'Australia', 'country_code' => '61', 'phone_rule' => '^[1-9]\d{10}$', 'status' => 1, 'admin' => 0],
            ['id' => 10, 'code' => 'VN', 'name' => 'Vietnam', 'country_code' => '84', 'phone_rule' => '^[1-9]\d{8,9}$', 'status' => 1, 'admin' => 0],
            ['id' => 12, 'code' => 'HK', 'name' => 'Hong Kong', 'country_code' => '852', 'phone_rule' => '^[1-9]\d{7}$', 'status' => 1, 'admin' => 0],
            ['id' => 13, 'code' => 'ID', 'name' => 'Indonesia', 'country_code' => '62', 'phone_rule' => '^[15689]\d{10,13}$', 'status' => 1, 'admin' => 0],
            ['id' => 14, 'code' => 'MM', 'name' => 'Myanmar', 'country_code' => '95', 'phone_rule' => '^[1-9]\d{9}$', 'status' => 1, 'admin' => 0],
            ['id' => 15, 'code' => 'IN', 'name' => 'India', 'country_code' => '91', 'phone_rule' => '^[1-9]\d{9}$', 'status' => 1, 'admin' => 0],
        ];

        DB::table('countries')->upsert($datas, ['id']);
    }
}
