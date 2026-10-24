<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * php artisan db:seed --class=CurrencySeeder
 */
class CurrencySeeder extends Seeder
{
    protected string $table = 'currencies';

    protected array $data = [
        ['id' => 1, 'name' => 'TON/TON', 'code' => 'TON/TON'],
        ['id' => 2, 'name' => 'TON/USDT', 'code' => 'TON/USDT'],
    ];

    public function run(): void
    {
        $ids = DB::table($this->table)->pluck('id')->keyBy('id')->toArray();
        $insertData = array_filter($this->data, function ($item) use ($ids) {
            return !isset($ids[$item['id']]);
        });
        DB::table($this->table)->insert($insertData);
    }
}
