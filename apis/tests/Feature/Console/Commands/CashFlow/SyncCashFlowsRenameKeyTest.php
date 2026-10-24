<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow;

use Database\Seeders\CurrencySeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Console/Commands/CashFlow/SyncCashFlowsRenameKeyTest.php
 *
 * 顯式改鍵軌（--rename-key old=new）：config key 修正時就地 update order_types.key，
 * 不走「新建 new key ＋ 下架 old key」——OT id 不變，ACF / ACFOT 掛的是 order_type_id，
 * 整條 agent 層關聯自動跟上、零搬移。
 *
 * 穩定性設計同 SyncCashFlowsDisableRemovedOrderTypesTest：
 *  - 以 cash_flow 119（D_DGPAY）為載具；MYR config 現存 key：5_10 / 5_20 / 49_15。
 *  - 斷言以自播 row 的 id 進行；幣別 id 動態解析。
 */
class SyncCashFlowsRenameKeyTest extends TestCase
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
            'name' => 'ZZ Rename Test Channel',
            'key' => '__RN_TEST__',
            'type' => 3,
            'currency_id' => $currencyId,
            'choose_bank' => 0,
            'status' => 1,
            'admin' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ], $attrs));
    }

    private function myrCurrencyId(): int
    {
        $this->seed(CurrencySeeder::class);

        return (int) DB::table('currencies')->where('code', 'MYR')->value('id');
    }

    public function test_rename_key_updates_order_type_in_place(): void
    {
        $myrCurrencyId = $this->myrCurrencyId();

        // 前置：清掉 config key 49_15 既有列（若基線 DB 已有），確保唯一列來源是本測試的改鍵結果
        DB::table('order_types')
            ->where('cash_flow_id', $this->cashFlowId)
            ->where('currency_id', $myrCurrencyId)
            ->where('parent', 0)
            ->where('key', '49_15')
            ->delete();

        // DB 既有列掛「配錯的 key」49_99（模擬 config 修正前已同步出去的錯鍵）
        $wrongKeyOtId = $this->makeOrderType($myrCurrencyId, ['key' => '49_99', 'name' => 'ZZ Wrong Key']);

        $this->artisan('cash-flow:sync', [
            '--ids' => (string) $this->cashFlowId,
            '--rename-key' => ['49_99=49_15'],
        ])->assertExitCode(0);

        // 就地改鍵：同一列 id、key 更新為 config 新鍵（ACF/ACFOT 掛 id 故關聯不動）
        $row = DB::table('order_types')->where('id', $wrongKeyOtId)->first();
        $this->assertSame('49_15', (string) $row->key, '應就地 update key，不新建');

        // 不得出現第二列 49_15（新建＋下架路徑的產物）
        $count = DB::table('order_types')
            ->where('cash_flow_id', $this->cashFlowId)
            ->where('currency_id', $myrCurrencyId)
            ->where('parent', 0)
            ->where('key', '49_15')
            ->count();
        $this->assertSame(1, $count, '改鍵後 49_15 僅此一列，不得另行新建');

        // 舊鍵列不復存在（就是同一列被改走了）
        $oldCount = DB::table('order_types')
            ->where('cash_flow_id', $this->cashFlowId)
            ->where('currency_id', $myrCurrencyId)
            ->where('parent', 0)
            ->where('key', '49_99')
            ->count();
        $this->assertSame(0, $oldCount);
    }

    public function test_disable_message_hints_rename_key_option(): void
    {
        $myrCurrencyId = $this->myrCurrencyId();

        // config 已不存在的 key → 會被 disableRemovedOrderTypes 下架；訊息應提示改鍵選項
        $this->makeOrderType($myrCurrencyId, ['key' => '__RN_STRAY__', 'name' => 'ZZ Stray']);

        $this->artisan('cash-flow:sync', ['--ids' => (string) $this->cashFlowId])
            ->expectsOutputToContain('--rename-key')
            ->assertExitCode(0);
    }

    public function test_invalid_rename_key_format_fails(): void
    {
        $this->artisan('cash-flow:sync', [
            '--ids' => (string) $this->cashFlowId,
            '--rename-key' => ['49_99to49_15'],
        ])->assertExitCode(1);
    }
}
