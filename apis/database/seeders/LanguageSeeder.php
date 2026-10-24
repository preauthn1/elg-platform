<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class LanguageSeeder extends Seeder
{
    public function run(): void
    {
        // 使用 insertOrIgnore（INSERT IGNORE）取代 upsert（INSERT ... ON DUPLICATE KEY UPDATE）
        // 原因：測試使用 DatabaseTransactions，upsert 在 InnoDB 會對固定 PK 行取 next-key lock，
        // 前一個 test rollback 尚未釋放鎖時，下一個 test 就會 Lock wait timeout。
        // 測試場景只需確保資料存在，不需要更新，INSERT IGNORE 完全足夠且不會鎖行。
        DB::table('languages')->insertOrIgnore([
            ['id' => 1, 'name' => 'English', 'code' => 'en', 'sort' => 0, 'status' => 1, 'admin' => 0],
            ['id' => 2, 'name' => '繁體中文', 'code' => 'zh-tw', 'sort' => 3, 'status' => 1, 'admin' => 0],
            ['id' => 3, 'name' => '简体中文', 'code' => 'zh-cn', 'sort' => 1, 'status' => 1, 'admin' => 0],
            ['id' => 4, 'name' => 'Melayu', 'code' => 'ms-my', 'sort' => 2, 'status' => 1, 'admin' => 0],
            ['id' => 5, 'name' => 'Indonesia', 'code' => 'id-id', 'sort' => 4, 'status' => 1, 'admin' => 0],
            ['id' => 6, 'name' => 'Vietnamese', 'code' => 'vi-vn', 'sort' => 5, 'status' => 1, 'admin' => 0],
            ['id' => 7, 'name' => 'Thai', 'code' => 'th-th', 'sort' => 6, 'status' => 1, 'admin' => 0],
            ['id' => 8, 'name' => 'Taml', 'code' => 'ta', 'sort' => 7, 'status' => 1, 'admin' => 0],
            ['id' => 9, 'name' => 'Pilipino', 'code' => 'tl-ph', 'sort' => 8, 'status' => 1, 'admin' => 0],
            ['id' => 10, 'name' => 'Burmese', 'code' => 'my-mm', 'sort' => 9, 'status' => 1, 'admin' => 0],
        ]);
    }
}
