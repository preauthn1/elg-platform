<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Vendor\VendorIdEnum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\VendorInfo>
 */
class VendorInfoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'test', // 遊戲商名稱
            'type' => 0,
            // vendor_id 在資料表是 NOT NULL 且無預設值，少了它 create() 會直接拋 1364。
            // 這裡給一個能落庫的值即可；測試若在意是哪一家遊戲商，自行覆寫。
            'vendor_id' => VendorIdEnum::AG->value,
            'currency' => 'HKD',
            // 0 是「尚未回填」的 sentinel，不給隨機幣別：忘了設定幣別的測試案例應該明確失敗，而不是誤打誤撞通過
            'currency_id' => 0,
            'status' => 1,
            'account_prefix' => 'devTEST',
            'prepared_account_number' => 10,
            'rolling_chip_status' => 0,
            'info' => '',
            'cost' => 0,
            'admin' => 0,
        ];
    }
}
