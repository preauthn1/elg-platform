<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Vendor;

/**
 * php artisan db:seed --class=SyncVendorSeeder
 *
 * @knowledge rules/vendor-and-channel-management
 */
class SyncVendorSeeder extends AbstractSyncSeeder
{
    protected string $dataFile = 'vendors.php';

    protected string $modelClass = Vendor::class;

    protected string $uniqueKey = 'id';

    protected bool $isTest = false;

    protected array $default = [
        'id' => '',
        'name' => '',
        'code' => '',
        'tname' => '',
        'third_party_code' => '',
        'rate' => 1.0, // 平台點數與遊戲點數的比例
        'percentage' => 0.0, // 拆帳比例
        'valid_bet_rate' => 1.0, // 有效投注轉換比例
        'sort' => 0, // 排序
        'only_one_game' => 0, // 只有一款遊戲，0:否、1:是
        'is_free' => 0, // 是否為免盤費商戶
        'is_single_wallet' => 0, // 是否為單一錢包遊戲，0:否、1:是
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
        'info' => '',
        'image' => '',
        'app_icon' => '',
        'calc_on_profit_share' => 1,
    ];

    protected function isValid(array $data, $key): bool
    {
        return !empty($data['code']) && !empty($data['name']);
    }

    protected function formatInsertData(array $data, $key): array
    {
        $data = array_merge($this->default, $data);

        // 已在父類合併預設值，這裡可做欄位轉型與加入時間
        $data['id'] = $key;
        $data['created_at'] = $data['created_at'] ?? now();
        $data['updated_at'] = $data['updated_at'] ?? now();

        // 範例：確保某些欄位類型
        if (isset($data['is_active'])) {
            $data['is_active'] = (bool) $data['is_active'];
        }

        return $data;
    }

    protected function getLogMessage(array $data, $key): string
    {
        return '新增 Vendor code: ' . ($data['code'] ?? $key);
    }
}
