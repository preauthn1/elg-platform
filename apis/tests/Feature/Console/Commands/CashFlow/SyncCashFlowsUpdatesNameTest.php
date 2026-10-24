<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsUpdatesNameTest.php
 *
 * 驗證 cash-flow:sync 對已存在的 CashFlow 會同步 name（Data 檔為 source of truth）。
 * 背景：Dev DB cash_flows 121/122 name 停在舊拼寫 `Paymate - *`，而原 processCashFlow
 * 更新分支只同步 info，重跑 sync 無法正名 → 補 name 比對同步。
 *
 * 以 cash_flow 121（D_PAYMATE，Data 檔 name = 'PayMate - Deposit'）為載具；
 * 幣別依既有測試慣例以 CurrencySeeder 補齊（冪等），供 sync 內 getCurrencyId 解析。
 */
class SyncCashFlowsUpdatesNameTest extends TestCase
{
    public function test_sync_updates_existing_cash_flow_name_from_data_file(): void
    {
        $this->seed(CurrencySeeder::class);

        // 預植舊拼寫 name 的既有列（模擬 Dev DB 121 現況）
        DB::table('cash_flows')->updateOrInsert(['id' => 121], [
            'third_party_code' => 'D_PAYMATE',
            'name' => 'Paymate - Deposit',
            'type' => 1,
            'status' => 1,
            'admin' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);

        $this->artisan('cash-flow:sync', ['--ids' => '121'])
            ->assertExitCode(0);

        $this->assertSame(
            'PayMate - Deposit',
            DB::table('cash_flows')->where('id', 121)->value('name'),
        );
    }
}
