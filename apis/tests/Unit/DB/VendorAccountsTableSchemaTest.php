<?php

declare(strict_types=1);

namespace Tests\Unit\DB;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * VendorAccounts 資料表結構測試
 *
 * php artisan test tests/Unit/DB/VendorAccountsTableSchemaTest.php
 */
class VendorAccountsTableSchemaTest extends TestCase
{
    public function test_vendor_accounts_table_schema()
    {
        $testedVendors = [];
        $vendors = require database_path('data/vendors.php');
        $disabledVendors = array_flip(require database_path('data/disabled_vendors.php'));
        foreach ($vendors as $vendorId => $vendor) {
            // 停用遊戲商不納入帳號表規範檢查（清單見 data/disabled_vendors.php）
            if (isset($disabledVendors[$vendorId])) {
                continue;
            }

            $tname = $vendor['tname'];
            if (isset($testedVendors[$tname])) {
                continue;
            }

            $testedVendors[$tname] = true;

            if (!Schema::hasTable($tname . '_accounts')) {
                continue;
            }

            $this->assertTrue(Schema::hasColumn($tname . '_accounts', 'status'), "{$tname}_accounts 資料表缺少 status 欄位");
        }
    }
}
