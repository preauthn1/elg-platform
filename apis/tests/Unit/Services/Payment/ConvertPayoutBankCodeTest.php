<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Payment;

use App\Models\AgentCashFlow;
use App\Models\CashFlowBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Fixtures\Payment\PayoutBankCodeHarness;
use Tests\TestCase;

/**
 * convertPayoutBankCode 雙路徑測試
 *
 * - allowlist 外：舊 available_banks JSON 迴圈
 * - allowlist 內：查 cash_flow_banks 表（新路徑）
 * - guard 語意：對不到皆回 ''
 * - fail-silent：allowlist 含未知 provider → Warning log + 仍走舊路徑
 * - 比對語意：兩條路徑對同一組碼必須給出同一個答案（見檔尾 parity 區塊）
 *
 * ! 舊 JSON 路徑原本標注「byte-for-byte 不變」，2026-09-09 起刻意不再成立：
 * ! 該路徑的 `==` 會把數字字串強制轉型，與查表路徑判定相反，已一併收緊。
 *
 * php artisan test tests/Unit/Services/Payment/ConvertPayoutBankCodeTest.php
 */
class ConvertPayoutBankCodeTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 建立測試替身：
     *  - currencies: DB::table 直寫（rate/admin NOT NULL 無預設）
     *  - AgentCashFlow: cash_flow_id=99555（高位哨兵值，避開既有列）
     *  - info['available_banks']: JSON 路徑測試用
     */
    private function harness(string $driver): PayoutBankCodeHarness
    {
        // 用 TST 幣別代碼避開共享庫真實幣別；insertGetId 取自增 ID
        $currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TST',
            'name' => 'Test',
            'rate' => 1,
            'admin' => 0,
        ]);

        $acf = AgentCashFlow::create([
            'cash_flow_id' => 99555,
            'currency_id' => $currencyId,
            'info' => json_encode([]),
            'admin' => 0,
        ]);

        $h = new PayoutBankCodeHarness($driver);
        $h->agentCashFlow = $acf;
        $h->info = ['available_banks' => [['BankCode_ELG' => 'MAYBANK', 'BankCode_Third' => 'MBB_JSON']]];

        return $h;
    }

    // -------------------------------------------------------------------------
    // 舊路徑（JSON）
    // -------------------------------------------------------------------------

    public function test_provider_not_in_allowlist_uses_json_path(): void
    {
        config(['payment.payout_bank_lookup_providers' => []]);
        $h = $this->harness('SurePay');
        $this->assertSame('MBB_JSON', $h->call('MAYBANK'));
    }

    // -------------------------------------------------------------------------
    // 新路徑（cash_flow_banks）
    // -------------------------------------------------------------------------

    public function test_provider_in_allowlist_uses_cash_flow_banks(): void
    {
        config(['payment.payout_bank_lookup_providers' => ['SurePay']]);
        $h = $this->harness('SurePay');

        CashFlowBank::create([
            'cash_flow_id' => 99555,
            'currency_id' => $h->agentCashFlow->currency_id,
            'bank_id' => 0,
            'bank_code' => 'MAYBANK',
            'provider_bank_code' => 'MBB_TABLE',
            'status' => 1,
        ]);

        $this->assertSame('MBB_TABLE', $h->call('MAYBANK'));
    }

    public function test_new_path_returns_empty_when_no_match(): void
    {
        config(['payment.payout_bank_lookup_providers' => ['SurePay']]);
        $h = $this->harness('SurePay');
        $this->assertSame('', $h->call('NONEXISTENT'));
    }

    public function test_new_path_ignores_disabled_rows(): void
    {
        config(['payment.payout_bank_lookup_providers' => ['SurePay']]);
        $h = $this->harness('SurePay');

        CashFlowBank::create([
            'cash_flow_id' => 99555,
            'currency_id' => $h->agentCashFlow->currency_id,
            'bank_id' => 0,
            'bank_code' => 'MAYBANK',
            'provider_bank_code' => 'MBB_TABLE',
            'status' => 0,
        ]);

        $this->assertSame('', $h->call('MAYBANK'));
    }

    // -------------------------------------------------------------------------
    // Fix A：確定序 — 同 (cash_flow_id, currency_id, bank_code, status=1) 多列時穩定取最早建立列
    // -------------------------------------------------------------------------

    public function test_duplicate_rows_returns_smallest_id_row(): void
    {
        config(['payment.payout_bank_lookup_providers' => ['SurePay']]);
        $h = $this->harness('SurePay');

        // 唯一鍵為 (cash_flow_id, currency_id, bank_id)，不含 bank_code；
        // 使用不同 bank_id 讓兩列均可插入，但 bank_code 相同模擬實務重複場景。
        CashFlowBank::create([
            'cash_flow_id' => 99555,
            'currency_id' => $h->agentCashFlow->currency_id,
            'bank_id' => 1,
            'bank_code' => 'MAYBANK',
            'provider_bank_code' => 'MBB_FIRST',
            'status' => 1,
        ]);

        CashFlowBank::create([
            'cash_flow_id' => 99555,
            'currency_id' => $h->agentCashFlow->currency_id,
            'bank_id' => 2,
            'bank_code' => 'MAYBANK',
            'provider_bank_code' => 'MBB_SECOND',
            'status' => 1,
        ]);

        // orderBy('id') 保證回傳 id 較小（即先插入）的列
        $this->assertSame('MBB_FIRST', $h->call('MAYBANK'));
    }

    // -------------------------------------------------------------------------
    // Fix B：cash_flow_id null fallback — 關聯 cashFlow 已載入時仍查得到表值
    // -------------------------------------------------------------------------

    public function test_null_cash_flow_id_falls_back_to_cashflow_relation(): void
    {
        config(['payment.payout_bank_lookup_providers' => ['SurePay']]);

        $currencyId = DB::table('currencies')->insertGetId([
            'code' => 'TST',
            'name' => 'Test',
            'rate' => 1,
            'admin' => 0,
        ]);

        // 建立真實 cash_flows 列，供 cashFlow 關聯 fallback 使用
        $realCashFlowId = DB::table('cash_flows')->insertGetId([
            'name' => 'TestCashFlow',
            'rate' => 1,
            'percentage' => 0,
            'type' => 3,
            'status' => 1,
            'info' => '{}',
            'admin' => 0,
        ]);

        // AgentCashFlow 指向真實 cash_flows，以便 cashFlow 關聯可載入
        $acf = AgentCashFlow::create([
            'cash_flow_id' => $realCashFlowId,
            'currency_id' => $currencyId,
            'info' => json_encode([]),
            'admin' => 0,
        ]);

        // 預載 cashFlow 關聯後，在記憶體中將 cash_flow_id 設為 null，
        // 已快取的關聯不受影響，模擬「cash_flow_id=null 但 cashFlow 關聯存在」的場景。
        $acf->load('cashFlow');
        $acf->cash_flow_id = null;

        CashFlowBank::create([
            'cash_flow_id' => $realCashFlowId,
            'currency_id' => $currencyId,
            'bank_id' => 0,
            'bank_code' => 'MAYBANK',
            'provider_bank_code' => 'MBB_FALLBACK',
            'status' => 1,
        ]);

        $h = new PayoutBankCodeHarness('SurePay');
        $h->agentCashFlow = $acf;
        $h->info = [];

        $this->assertSame('MBB_FALLBACK', $h->call('MAYBANK'));
    }

    // -------------------------------------------------------------------------
    // Fail-silent 防護：allowlist 含未知 provider → Warning + 舊路徑
    // -------------------------------------------------------------------------

    public function test_unknown_provider_in_allowlist_warns_and_uses_json_path(): void
    {
        config(['payment.payout_bank_lookup_providers' => ['TOTALLY_UNKNOWN_XYZ']]);
        Log::spy();

        $h = $this->harness('SurePay');
        $result = $h->call('MAYBANK');

        // 行為：仍走舊 JSON 路徑（fail-safe）
        $this->assertSame('MBB_JSON', $result);

        // 可觀測性：warning 須發出，且含關鍵字
        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn (string $msg): bool => str_contains($msg, 'payout_bank_lookup_providers 含未知 provider'));
    }

    // -------------------------------------------------------------------------
    // 比對語意 parity（2026-09-09 收斂）
    //
    // 收斂前兩條路徑對「這兩個碼算不算同一個」的判定方向相反：
    //   JSON 路徑 `==`   → 大小寫敏感，但兩邊都是數字字串時會強制轉型（'007' 等於 '7'）
    //   查表路徑 SQL `=` → 不轉型，但欄位 collation 是 utf8mb4_unicode_ci、大小寫不敏感
    // 也就是說同一張提款單，在灰度開閘前後可能拿到不同的三方銀行碼。
    // 現統一為「逐字相同才算命中」，本測試就是該不變式的守門人。
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, string}> [已登記的碼, 訂單帶入的碼, 預期三方碼]
     */
    public static function codeComparisonProvider(): array
    {
        return [
            '逐字相同' => ['MAYBANK', 'MAYBANK', 'MBB'],
            '只差大小寫' => ['MAYBANK', 'MayBank', ''],
            '數字字串前導零' => ['007', '7', ''],
            '數字字串科學記號' => ['1e2', '100', ''],
            '完全不同' => ['MAYBANK', 'CIMB', ''],
        ];
    }

    #[DataProvider('codeComparisonProvider')]
    public function test_both_paths_agree_on_code_comparison(string $registered, string $queried, string $expected): void
    {
        $h = $this->harness('SurePay');
        $h->info = ['available_banks' => [['BankCode_ELG' => $registered, 'BankCode_Third' => 'MBB']]];

        CashFlowBank::create([
            'cash_flow_id' => 99555,
            'currency_id' => $h->agentCashFlow->currency_id,
            'bank_id' => 0,
            'bank_code' => $registered,
            'provider_bank_code' => 'MBB',
            'status' => 1,
        ]);

        config(['payment.payout_bank_lookup_providers' => []]);
        $this->assertSame($expected, $h->call($queried), 'JSON 路徑');

        config(['payment.payout_bank_lookup_providers' => ['SurePay']]);
        $this->assertSame($expected, $h->call($queried), '查表路徑');
    }

    /** 非字串 target（int / bool / null / 陣列）不得靠型別轉換命中，且不得炸錯。 */
    public function test_json_path_ignores_non_string_elg_entries(): void
    {
        config(['payment.payout_bank_lookup_providers' => []]);
        $h = $this->harness('SurePay');
        $h->info = ['available_banks' => [
            ['BankCode_ELG' => 7, 'BankCode_Third' => 'INT'],
            ['BankCode_ELG' => true, 'BankCode_Third' => 'BOOL'],
            ['BankCode_ELG' => null, 'BankCode_Third' => 'NULL'],
            ['BankCode_ELG' => ['X'], 'BankCode_Third' => 'ARRAY'],
            ['BankCode_Third' => 'NO_ELG_KEY'],
            'not-an-array',
        ]];

        $this->assertSame('', $h->call('7'));
        $this->assertSame('', $h->call('1'));
        $this->assertSame('', $h->call(''));
    }
}
