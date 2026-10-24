<?php

declare(strict_types=1);

namespace Tests\Unit\Services\CashFlow\Bank;

use App\Helpers\StandardBankNameHelper;
use App\Services\CashFlow\Bank\BankMismatchAuditor;
use Database\Seeders\SyncCurrenciesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Unit/Services/CashFlow/Bank/BankMismatchAuditorTest.php
 */
class BankMismatchAuditorTest extends TestCase
{
    private BankMismatchAuditor $auditor;

    private int $currencyId = 1; // MYR（由種子提供，與 StandardBankNameHelperTest 共用慣例）

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(SyncCurrenciesSeeder::class);
        StandardBankNameHelper::clearCache();
        $this->auditor = new BankMismatchAuditor;
    }

    protected function tearDown(): void
    {
        StandardBankNameHelper::clearCache();
        parent::tearDown();
    }

    public function test_audit_currency_returns_four_categories(): void
    {
        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);

        $this->assertArrayHasKey('lost', $result);
        $this->assertArrayHasKey('low_confidence_feed', $result);
        $this->assertArrayHasKey('mismatch', $result);
        $this->assertArrayHasKey('duplicate', $result);
    }

    public function test_supported_currencies_auto_discovers_from_data_dir(): void
    {
        $supported = $this->auditor->supportedCurrencies();

        // 至少要包含 testing DB 已 seed 的幣別（MYR/SGD/VND），
        // 且每個 key 都對應到 Data/banks/{CODE}.php，證明來源是檔案系統而非硬編碼清單
        foreach (['MYR', 'SGD', 'VND'] as $code) {
            $this->assertArrayHasKey($code, $supported, "Missing $code");
            $this->assertIsInt($supported[$code]);
            $this->assertFileExists(base_path("app/Console/Commands/CashFlow/Data/banks/{$code}.php"));
        }

        // 不應出現非檔案系統來源的代碼，例如 StandardBankCodes 或 README
        $this->assertArrayNotHasKey('StandardBankCodes', $supported);
        $this->assertArrayNotHasKey('README', $supported);
    }

    public function test_detect_lost_flags_unmatched_db_banks(): void
    {
        $bank = \App\Models\Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => 'PHPUnit Lost Bank ZZ9X3',
            'code' => 'LOST',
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);

        $lostBankIds = array_column($result['lost'], 'bank_id');
        $this->assertContains($bank->id, $lostBankIds, 'Lost bank should be flagged');
    }

    public function test_detect_low_confidence_feed_returns_array_with_correct_shape(): void
    {
        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);

        $this->assertIsArray($result['low_confidence_feed']);
        foreach ($result['low_confidence_feed'] as $entry) {
            $this->assertArrayHasKey('provider', $entry);
            $this->assertArrayHasKey('feed_name', $entry);
            $this->assertArrayHasKey('feed_code', $entry);
            $this->assertArrayHasKey('confidence', $entry);
            $this->assertLessThan(85, $entry['confidence']);
        }
    }

    public function test_detect_lost_does_not_flag_known_standard_bank(): void
    {
        // Maybank 在 MYR.php 是標準銀行
        $bank = \App\Models\Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => '(MYR) - Maybank',
            'code' => 'MAYB',
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);
        $lostBankIds = array_column($result['lost'], 'bank_id');

        $this->assertNotContains($bank->id, $lostBankIds, 'Known standard bank should not be flagged lost');
    }

    public function test_detect_lost_does_not_flag_db_name_matched_by_alias_without_prefix(): void
    {
        // Simulate: DB row has "(MYR) - MBB" (with prefix),
        // MYR.php has alias "MBB" (without prefix) under (MYR) - Maybank → should resolve, NOT be lost
        $bank = \App\Models\Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => '(MYR) - MBB',
            'code' => 'TEST_MBB_PFX',
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);
        $lostBankIds = array_column($result['lost'], 'bank_id');

        $this->assertNotContains(
            $bank->id,
            $lostBankIds,
            '(MYR) - MBB should match Maybank alias even though alias lacks prefix'
        );
    }

    public function test_detect_mismatch_flags_alias_db_name(): void
    {
        // Use an alias as DB name (with currency prefix to match DB convention)
        // 'Maybank2u' is an alias of '(MYR) - Maybank' in MYR.php
        $bank = \App\Models\Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => '(MYR) - Maybank2u',
            'code' => 'TEST_MISMATCH_2',
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);
        $mismatchIds = array_column($result['mismatch'], 'bank_id');

        $this->assertContains(
            $bank->id,
            $mismatchIds,
            '(MYR) - Maybank2u should be flagged as mismatch (resolves to (MYR) - Maybank)'
        );

        $entry = collect($result['mismatch'])->firstWhere('bank_id', $bank->id);
        $this->assertSame('(MYR) - Maybank', $entry['target_standard']);
        $this->assertGreaterThanOrEqual(80, $entry['confidence']);
    }

    public function test_detect_mismatch_does_not_flag_exact_standard_name(): void
    {
        // (MYR) - Maybank is the standard itself → should NOT be mismatch
        $bank = \App\Models\Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => '(MYR) - Maybank',
            'code' => 'TEST_MM_EXACT',
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);
        $mismatchIds = array_column($result['mismatch'], 'bank_id');

        $this->assertNotContains(
            $bank->id,
            $mismatchIds,
            'Exact standard name should not be flagged as mismatch'
        );
    }

    public function test_detect_duplicate_flags_rows_resolving_to_same_standard(): void
    {
        // Two DB rows that both resolve to '(MYR) - Maybank' via different aliases
        $bank1 = \App\Models\Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => '(MYR) - Malayan Banking',  // alias of (MYR) - Maybank
            'code' => 'TEST_DUP_1',
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);
        $bank2 = \App\Models\Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => '(MYR) - MAYBANK2U',
            'code' => 'TEST_DUP_2',
            'currency_id' => $this->currencyId,
            'rate' => 1,
            'tax_type' => 0,
            'tax' => 0,
            'min_amount' => 0,
            'max_amount' => 999999,
            'sort' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->auditor->auditCurrency('MYR', $this->currencyId);

        $foundGroup = null;
        foreach ($result['duplicate'] as $group) {
            $allIds = array_merge(
                [$group['primary_bank_id']],
                array_column($group['duplicates'], 'bank_id')
            );
            if (in_array($bank1->id, $allIds, true) && in_array($bank2->id, $allIds, true)) {
                $foundGroup = $group;
                break;
            }
        }

        $this->assertNotNull($foundGroup, 'Duplicate group should include bank1 and bank2');

        // Primary should be the smallest id in the group (may include pre-existing (MYR) - Maybank row)
        $allIds = array_merge(
            [$foundGroup['primary_bank_id']],
            array_column($foundGroup['duplicates'], 'bank_id')
        );
        $this->assertSame(min($allIds), $foundGroup['primary_bank_id'], 'Primary should be min id');
    }
}
