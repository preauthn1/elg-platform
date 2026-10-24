<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\StandardBankNameHelper;
use App\Models\Bank;
use Tests\TestCase;

/**
 * StandardBankNameHelper 測試
 *
 * php artisan test tests/Unit/Helpers/StandardBankNameHelperTest.php
 */
class StandardBankNameHelperTest extends TestCase
{
    /** @description 與既有金流／單元測試相同，假設種子已提供 id=1 之幣別 */
    private int $currencyId = 1;

    protected function setUp(): void
    {
        parent::setUp();

        StandardBankNameHelper::clearCache();
    }

    protected function tearDown(): void
    {
        StandardBankNameHelper::clearCache();
        parent::tearDown();
    }

    public function test_is_match_reliable_uses_threshold(): void
    {
        $this->assertTrue(StandardBankNameHelper::isMatchReliable([
            'standard_name' => 'X',
            'confidence' => 80,
            'matched_by' => 't',
            'existing_bank_id' => null,
        ]));
        $this->assertFalse(StandardBankNameHelper::isMatchReliable([
            'standard_name' => null,
            'confidence' => 79,
            'matched_by' => 't',
            'existing_bank_id' => null,
        ]));
    }

    public function test_match_bank_name_db_exact_wins(): void
    {
        $bank = Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => 'PHPUnit Unique Bank Name ZZ9A1',
            'code' => 'PHPU',
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
        $this->assertNotNull($bank->id);

        $result = StandardBankNameHelper::matchBankName('MYR', 'PHPUnit Unique Bank Name ZZ9A1', $this->currencyId);

        $this->assertSame('PHPUnit Unique Bank Name ZZ9A1', $result['standard_name']);
        $this->assertSame(100, $result['confidence']);
        $this->assertSame('db_exact', $result['matched_by']);
        $this->assertSame($bank->id, $result['existing_bank_id']);

        $standard = StandardBankNameHelper::getStandardName('MYR', 'PHPUnit Unique Bank Name ZZ9A1', $this->currencyId);
        $this->assertSame('PHPUnit Unique Bank Name ZZ9A1', $standard);
    }

    public function test_get_standard_name_returns_fallback_when_no_match(): void
    {
        $result = StandardBankNameHelper::matchBankName(
            'MYR',
            'PHPUnit-NoMatch-8f3c2a1b-zzzzzz',
            $this->currencyId
        );
        $this->assertSame(0, $result['confidence']);

        $fallback = StandardBankNameHelper::getStandardName(
            'MYR',
            'PHPUnit-NoMatch-8f3c2a1b-zzzzzz',
            $this->currencyId,
            fallback: 'FallbackName'
        );
        $this->assertSame('FallbackName', $fallback);
    }

    public function test_standardize_bank_list_includes_action(): void
    {
        $uniqueName = 'PHPUnit Standardize ' . uniqid('', true);

        $bank = Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => $uniqueName,
            'code' => 'PHST',
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

        $list = StandardBankNameHelper::standardizeBankList('MYR', $this->currencyId, [
            ['name' => $uniqueName, 'code' => 'Z'],
        ]);

        $this->assertCount(1, $list);
        $this->assertSame($uniqueName, $list[0]['original_name']);
        $this->assertSame('update', $list[0]['action']);
        $this->assertSame($bank->id, $list[0]['existing_bank_id']);
    }

    /**
     * best-match 回歸測試：exact 命中應勝過「較早出現的弱相似匹配」。
     *
     * 對應真實 bug：輸入 "(IDR) - CIMB Niaga" 因字串內含關鍵字 "bni" 被較早出現的
     * Bank BNI 以 83% 搶配。修正後 matchAgainstDatabase 掃全部取最高分，
     * exact(100) 一律壓過較前面的相似/關鍵字匹配。
     */
    public function test_best_match_exact_wins_over_earlier_weak_similarity(): void
    {
        // 干擾列：先建立（id 較小、迭代在前），與輸入僅一字之差 → 相似度 ≥90
        $decoy = Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => 'PHPUnit BestMatch Sample BRI',
            'code' => 'PBM1',
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

        // 目標列：後建立（id 較大、迭代在後），與輸入完全相同 → exact 100
        $target = Bank::create([
            'agent_group_id' => 0,
            'type' => 1,
            'name' => 'PHPUnit BestMatch Sample BNI',
            'code' => 'PBM2',
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

        $result = StandardBankNameHelper::matchBankName('MYR', 'PHPUnit BestMatch Sample BNI', $this->currencyId);

        // 即使 decoy(BRI) 相似度 ≥90 且排在前面，exact 命中的 target(BNI) 仍應勝出
        $this->assertSame('PHPUnit BestMatch Sample BNI', $result['standard_name']);
        $this->assertSame(100, $result['confidence']);
        $this->assertSame('db_exact', $result['matched_by']);
        $this->assertSame($target->id, $result['existing_bank_id']);
        $this->assertNotSame($decoy->id, $result['existing_bank_id']);
    }

    /**
     * 只寫進 standard_banks 的條目擁有的名稱，就算平台銀行有同名或一般戶列，也要比對不到。
     * DB 比對 ≥90 會直接採用，閘門排在後面就攔不到（理由見 StandardBankRegistry::hiddenLabels）。
     */
    public function test_hidden_label_blocks_before_database_match(): void
    {
        foreach (['(IDR) - BPD Kalimantan Barat UUS', '(IDR) - Bank Kalbar'] as $i => $name) {
            Bank::create([
                'agent_group_id' => 0,
                'type' => 1,
                'name' => $name,
                'code' => 'PHU' . $i,
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
        }

        $result = StandardBankNameHelper::matchBankName('IDR', '(IDR) - BPD Kalimantan Barat UUS', $this->currencyId);

        $this->assertNull($result['standard_name']);
        $this->assertSame('no_match', $result['matched_by']);
        $this->assertNull($result['existing_bank_id']);
        $this->assertSame('(IDR) - Bank Kalbar', StandardBankNameHelper::matchBankName('IDR', '(IDR) - Bank Kalbar', $this->currencyId)['standard_name']);
    }
}
