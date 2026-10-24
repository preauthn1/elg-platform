<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsDisableRemovedOrderTypesTest.php
 *
 * 驗證 cash-flow:sync 代收側（type=1）會「下架」config 已移除的父層 OrderType（連同銀行子項），
 * 對稱代付側 disableRemovedBanks。下架＝status=0（不刪列）。
 *
 * 穩定性設計（避免跨 CI runner 的 testing DB 參考資料差異造成 flaky）：
 *  - 幣別用 base 幣別 MYR、id 動態解析（與 sync 內 getCurrencyId('MYR') 同源）。
 *  - 全部斷言以自播 row 的 id 進行，不依賴 sync 於測試 DB 現建 config 通道、不依賴既有 cf 資料。
 *  - 以 cash_flow 119（D_DGPAY）為載具；MYR config 現存 key：5_10 / 5_20 / 49_15。
 */
class SyncCashFlowsDisableRemovedOrderTypesTest extends TestCase
{
    private int $cashFlowId = 119;

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function makeOrderType(int $currencyId, array $attrs = []): int
    {
        return (int) DB::table('order_types')->insertGetId(array_merge([
            'cash_flow_id' => $this->cashFlowId,
            'parent' => 0,
            'name' => 'ZZ Test Channel',
            'key' => '__RM_TEST__',
            'type' => 3,
            'currency_id' => $currencyId,
            'choose_bank' => 0,
            'status' => 1,
            'admin' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ], $attrs));
    }

    public function test_sync_disables_deposit_order_types_removed_from_config(): void
    {
        // CI testing DB 的 currencies 未必有預存資料 → 以既有 CurrencySeeder 補齊（insertOrIgnore、
        // 專為測試 DatabaseTransactions 設計、冪等）。sync 內 getCurrencyId('MYR') 查同一張表一併吃到。
        $this->seed(CurrencySeeder::class);
        $myrCurrencyId = (int) DB::table('currencies')->where('code', 'MYR')->value('id');

        // config 已不存在的父層（bogus key）＋銀行子項（status=1）→ 應被下架
        $strayParentId = $this->makeOrderType($myrCurrencyId, ['key' => '__RM_TEST__', 'choose_bank' => 1]);
        $strayChildId = $this->makeOrderType($myrCurrencyId, [
            'parent' => $strayParentId,
            'name' => 'ZZ Removed Child',
            'key' => '__RM_CHILD__',
        ]);

        // config 仍存在的 MYR 父層（key=5_10）status=1 → key 在 config，應維持啟用
        $keptParentId = $this->makeOrderType($myrCurrencyId, ['key' => '5_10', 'name' => 'ZZ Kept 5_10']);

        $this->artisan('cash-flow:sync', ['--ids' => (string) $this->cashFlowId])
            ->assertExitCode(0);

        // 已移除的父層與銀行子項皆被下架（status=0）
        $this->assertSame(0, (int) DB::table('order_types')->where('id', $strayParentId)->value('status'));
        $this->assertSame(0, (int) DB::table('order_types')->where('id', $strayChildId)->value('status'));

        // config 仍存在的父層（key=5_10）維持啟用（status=1）
        $this->assertSame(1, (int) DB::table('order_types')->where('id', $keptParentId)->value('status'));
    }
}
