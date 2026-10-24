<?php

declare(strict_types=1);

namespace Database\Seeders;

/**
 * 更新指定 CashFlow 的 Seeder
 *
 * 使用方式：
 *   1. 修改下方的 $targetCashFlowIds 陣列，指定要更新的 CashFlow ID
 *   2. 執行：php artisan db:seed --class=UpdateSpecificCashFlowSeeder
 *
 * 範例：
 *   protected ?array $targetCashFlowIds = [1, 5, 10];
 */
class UpdateSpecificCashFlowSeeder extends SyncCashFlowsSeeder
{
    /**
     * 指定要處理的 CashFlow ID 列表
     *
     * 請在此修改要更新的 CashFlow ID
     */
    protected ?array $targetCashFlowIds = [67];

    /**
     * 是否為測試模式
     *
     * true: 執行後會回滾，不會真正寫入資料庫
     * false: 會真正寫入資料庫
     */
    protected bool $isTest = false;  // 先使用測試模式，確認無誤後改為 false
}
