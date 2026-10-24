<?php

declare(strict_types=1);

namespace Tests\Feature\StandardBanks;

use App\Models\Currency;
use App\Models\StandardBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

class SeedStandardBanksCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        // xsgcasino_testing.currencies 為空表；用 DB::table 繞過 model $guarded，
        // 並提供 rate/admin 等必填欄位（Currency model 不提供這些欄位的預設）。
        foreach (['MYR', 'VND', 'SGD', 'IDR', 'INR'] as $code) {
            if (!DB::table('currencies')->where('code', $code)->exists()) {
                DB::table('currencies')->insert(['code' => $code, 'name' => $code, 'rate' => 1, 'admin' => 0]);
            }
        }
    }

    public function test_seeds_stripped_code_and_maps_currency(): void
    {
        $this->artisan('cash-flow:seed-standard-banks')->assertSuccessful();

        $myrId = Currency::query()->where('code', 'MYR')->value('id');
        $maybank = StandardBank::query()->where('currency_id', $myrId)->where('code', 'MYR_MAYBANK')->first();

        $this->assertNotNull($maybank, 'code 應原樣保留幣別前綴存為 MYR_MAYBANK');
        $this->assertSame('MYR', $maybank->currency_code);
        $this->assertContains('MBB', $maybank->aliases);
    }

    public function test_is_idempotent(): void
    {
        $this->artisan('cash-flow:seed-standard-banks')->assertSuccessful();
        $first = StandardBank::query()->count();
        $this->artisan('cash-flow:seed-standard-banks')->assertSuccessful();
        $this->assertSame($first, StandardBank::query()->count(), '重跑不得新增重複列');
    }

    #[Group('rules/standard-bank-list#R-8')]
    public function test_dry_run_writes_nothing(): void
    {
        // 共享庫已有既存資料，用相對比較（dry-run 前後 count 應相等）
        $before = StandardBank::query()->count();
        $this->artisan('cash-flow:seed-standard-banks', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, StandardBank::query()->count(), 'dry-run 不得寫入任何新列');
    }

    #[Group('rules/standard-bank-list#R-6')]
    public function test_skips_entry_when_currency_missing(): void
    {
        // 刪除 IDR currency，使 config 中 IDR_* 條目全部被略過
        DB::table('currencies')->where('code', 'IDR')->delete();

        $this->artisan('cash-flow:seed-standard-banks')->assertSuccessful();

        // IDR_BRI 剝前綴後 code=BRI，對應 IDR；currency 缺失時不得寫入
        $idrId = DB::table('currencies')->where('code', 'IDR')->value('id');
        $this->assertNull($idrId, 'IDR currency 應已被刪除（DatabaseTransactions 下不污染共享庫）');
        $this->assertDatabaseMissing('standard_banks', ['code' => 'BRI']);
    }
}
