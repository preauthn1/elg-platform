<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\EventVendorInfo>
 */
class EventVendorInfoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * 只給資料表 NOT NULL 且無預設值的欄位一個能落庫的值（currency／info／account_prefix），
     * 其餘沿用 DDL 預設。幣別欄位刻意不隨機：測試在意哪個幣別就自己覆寫。
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'test-event',
            'vendor_id' => VendorIdEnum::AG->value,
            'currency' => 'MYR',
            // 0 是「尚未回填」的 sentinel，與 vendor_infos 同語意；忘了設定幣別的測試應明確失敗
            'currency_id' => 0,
            'status' => 1,
            'account_prefix' => 'evTEST',
            'prepared_account_number' => 10,
            'info' => '',
            'admin' => 0,
        ];
    }
}
