<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Vendor>
 */
class VendorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'test_' . Str::random(5),
            'code' => 'test_' . Str::random(5),
            'tname' => 'test_' . Str::random(5),
            'third_party_code' => 'test_' . Str::random(5),
            'rate' => 1.0, // 平台點數與遊戲點數的比例
            'percentage' => 0.0, // 拆帳比例
            'valid_bet_rate' => 1.0, // 有效投注轉換比例
            'sort' => 0, // 排序
            'only_one_game' => 0, // 只有一款遊戲，0:否、1:是
            'is_single_wallet' => 1, // 是否為單一錢包遊戲，0:否、1:是
            'is_slow' => 0, // 遊戲商 API 回應是否較慢，0:否、1:是
            'need_login' => 0, // 是否需另外登入，0:不需要、1:需要
            'show_on_mobile' => 1, // Mobile版是否顯示，0:不顯示，1:顯示
            'show_on_pc' => 1, // PC版是否顯示，0:不顯示，1:顯示
            'show_on_app' => 1, // APP版是否顯示，0:不顯示，1顯示
            'open_window' => 0, // 是否另開視窗，0:不另開，1:另開
            'summary_daily' => 1, // summary時間，1:每小時，2:每天
            'unbind' => 0, // 是否可以解綁，0:否，1:是
            'remark' => '', // 備註
            'status' => 1,
            'admin' => 0, // 預設管理者
            'image' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/1633679945100.png',
            'app_icon' => 'https://example.invalid/cdn-betmonsters-com-cdn-bucket/163367994693.png',
            'info' => '{}',
        ];
    }
}
