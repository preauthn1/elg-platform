<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\CashFlow\Guard;

use App\Models\CashFlowBank;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BackfillTpowerPayoutBankCodes 測試
 *
 * 補 TPower paypro(cf102) IDR 缺口的 4 家本地碼→三方碼 cash_flow_banks 對映。
 * 用 DatabaseTransactions 避免污染共享庫；以哨兵 cash_flow_id + 測試幣別隔離真實資料。
 *
 * php artisan test tests/Feature/Console/Commands/CashFlow/Patch/BackfillTpowerPayoutBankCodesTest.php
 */
class BackfillTpowerPayoutBankCodesTest extends TestCase
{
    use DatabaseTransactions;

    private const CF = 99560;

    private const EXPECTED = [
        'TPID05' => '0010',
        'TPID06' => '0008',
        'TPID51' => 'linkaja',
        'TPID53' => 'dana',
    ];

    private const NAMES = [
        'TPID05' => '(IDR) - CIMB Bank Indonesia',
        'TPID06' => '(IDR) - Maybank Indonesia',
        'TPID51' => '(IDR) - Link Aja App wallet',
        'TPID53' => '(IDR) - DANA eWallet',
    ];

    private function insertCurrency(string $code = 'TSD'): int
    {
        return (int) DB::table('currencies')->insertGetId([
            'code' => $code,
            'name' => 'Test IDR',
            'rate' => 1,
            'admin' => 0,
        ]);
    }

    private function makeBaseBank(int $currencyId, string $code, string $name): int
    {
        return (int) DB::table('banks')->insertGetId([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => $name,
            'code' => $code,
            'currency_id' => $currencyId,
            'status' => 1,
            'created_at' => Carbon::parse('2026-01-01 00:00:00'),
            'updated_at' => Carbon::parse('2026-01-01 00:00:00'),
        ]);
    }

    /**
     * 建立全部 4 家 base(group=0) banks，回傳 code=>bank_id。
     *
     * @return array<string, int>
     */
    private function makeAllBaseBanks(int $currencyId, array $only = ['TPID05', 'TPID06', 'TPID51', 'TPID53']): array
    {
        $ids = [];
        foreach ($only as $code) {
            $ids[$code] = $this->makeBaseBank($currencyId, $code, self::NAMES[$code]);
        }

        return $ids;
    }

    private function args(array $extra = []): array
    {
        return array_merge([
            '--cash-flow-id' => (string) self::CF,
            '--currency-code' => 'TSD',
        ], $extra);
    }

    // -------------------------------------------------------------------------
    // Test 1: cash_flow_banks 原本無 4 筆 → 全數建立、status=1、三方碼正確
    // -------------------------------------------------------------------------
    public function test_creates_missing_entries(): void
    {
        $currencyId = $this->insertCurrency();
        $bankIds = $this->makeAllBaseBanks($currencyId);

        $this->artisan('cash-flow:backfill-tpower-payout-bankcodes', $this->args())
            ->expectsConfirmation('確認補寫上述 cash_flow_banks？', 'yes')
            ->assertExitCode(0);

        foreach (self::EXPECTED as $code => $providerCode) {
            $row = CashFlowBank::query()
                ->where('cash_flow_id', self::CF)
                ->where('currency_id', $currencyId)
                ->where('bank_id', $bankIds[$code])
                ->first();

            $this->assertNotNull($row, "缺少 {$code} 的 cash_flow_banks");
            $this->assertSame($code, $row->bank_code);
            $this->assertSame($providerCode, $row->provider_bank_code);
            $this->assertSame(1, (int) $row->status);
        }
    }

    // -------------------------------------------------------------------------
    // Test 2: 已存在但被停用/三方碼錯 → 重啟 status=1 並更正三方碼
    // -------------------------------------------------------------------------
    public function test_reactivates_and_corrects_existing(): void
    {
        $currencyId = $this->insertCurrency();
        $bankIds = $this->makeAllBaseBanks($currencyId);

        // TPID53 既有一筆 disabled + 錯碼
        CashFlowBank::create([
            'cash_flow_id' => self::CF,
            'currency_id' => $currencyId,
            'bank_id' => $bankIds['TPID53'],
            'bank_code' => 'TPID53',
            'provider_bank_code' => 'WRONG',
            'status' => 0,
        ]);

        $this->artisan('cash-flow:backfill-tpower-payout-bankcodes', $this->args())
            ->expectsConfirmation('確認補寫上述 cash_flow_banks？', 'yes')
            ->assertExitCode(0);

        $row = CashFlowBank::query()
            ->where('cash_flow_id', self::CF)
            ->where('currency_id', $currencyId)
            ->where('bank_id', $bankIds['TPID53'])
            ->first();

        $this->assertSame('dana', $row->provider_bank_code);
        $this->assertSame(1, (int) $row->status);
        // 不應重複建列（同鍵仍只有一筆）
        $this->assertSame(1, CashFlowBank::query()
            ->where('cash_flow_id', self::CF)
            ->where('currency_id', $currencyId)
            ->where('bank_id', $bankIds['TPID53'])
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 3: dry-run 不寫入
    // -------------------------------------------------------------------------
    public function test_dry_run_writes_nothing(): void
    {
        $currencyId = $this->insertCurrency();
        $this->makeAllBaseBanks($currencyId);

        $this->artisan('cash-flow:backfill-tpower-payout-bankcodes', $this->args(['--dry-run' => true]))
            ->assertExitCode(0);

        $this->assertSame(0, CashFlowBank::query()
            ->where('cash_flow_id', self::CF)
            ->where('currency_id', $currencyId)
            ->count());
    }

    // -------------------------------------------------------------------------
    // Test 4: 缺 base(group=0) bank → 該筆跳過、不建（避免關聯錯 bank_id）
    // -------------------------------------------------------------------------
    public function test_skips_when_base_bank_missing(): void
    {
        $currencyId = $this->insertCurrency();
        // 只建 3 家，缺 TPID53
        $bankIds = $this->makeAllBaseBanks($currencyId, ['TPID05', 'TPID06', 'TPID51']);

        $this->artisan('cash-flow:backfill-tpower-payout-bankcodes', $this->args())
            ->expectsConfirmation('確認補寫上述 cash_flow_banks？', 'yes')
            ->assertExitCode(0);

        // 3 家建立，TPID53 因缺 base bank 未建
        $this->assertSame(3, CashFlowBank::query()
            ->where('cash_flow_id', self::CF)
            ->where('currency_id', $currencyId)
            ->count());
        $this->assertContains(
            $bankIds['TPID05'],
            CashFlowBank::query()->where('cash_flow_id', self::CF)->pluck('bank_id')->all()
        );
    }
}
