<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\StandardBankNameHelper;
use Tests\TestCase;

/**
 * 標準銀行分檔的關鍵字與別名不可把別家銀行的名稱搶走。
 *
 * cash-flow:sync 以 StandardBankNameHelper::isMatchReliable（信心度 ≥80）自動採用比對結果，
 * 關鍵字命中也在 80～85 分，所以太寬的關鍵字會直接把三方銀行碼掛到錯的銀行。
 * 每種幣別各留代表案例：通用字、短字跨字命中、改名後的新名稱、條目順序。
 *
 * php artisan test tests/Unit/Helpers/StandardBankKeywordMisfireTest.php
 */
class StandardBankKeywordMisfireTest extends TestCase
{
    /** 不存在的幣別 id：跳過 DB 平台銀行比對，只驗分檔本身 */
    private const int NO_DB_CURRENCY_ID = 999999;

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

    public function test_names_resolve_to_their_own_bank_or_fall_to_review(): void
    {
        $cases = [
            // 通用字 commercial bank 不可歸 Vietcombank
            ['VND', 'Joint Stock Commercial Bank for Investment and Development of Vietnam', '(VND) - Bank for Investment and Development of Vietnam'],
            ['VND', 'Kien Long Commercial Bank', '(VND) - Kien Long Commercial Joint Stock Bank'],
            // SMBC 是三井住友銀行，不是 Saigonbank
            ['VND', 'SMBC', null],
            // 改名：Viet Capital Bank → BVBank、OceanBank → MBV
            ['VND', 'BVBank', '(VND) - Viet Capital Bank'],
            ['VND', 'MBV', '(VND) - Ocean Commercial One Member Limited Liability Bank'],
            // QuickPay 碼 2009 的名稱是 SCB，不是 Sacombank 或 SHB
            ['VND', 'Saigon Commercial Joint Stock Bank', '(VND) - Saigon Joint Stock Commercial Bank'],
            // 本行名稱不可歸伊斯蘭子行
            ['IDR', 'PT Bank Jabar Banten Tbk', '(IDR) - Bank Jawa Barat'],
            // 去空白後「Banten Tbk」含 ntb
            ['IDR', 'PT Bank Pembangunan Daerah Banten Tbk', null],
            ['IDR', 'PT Bank Digital BCA', '(IDR) - Bank Royal'],
            ['MYR', 'ShopeePay eWallet', '(MYR) - ShopeePay'],
            ['SGD', 'Revolut SG', null],
            // 關鍵字分數同為上限時先出現者勝：Central Bank of India 須排在 Bank of India 之前
            ['INR', 'Central Bank of India Ltd', 'Central Bank of India'],
        ];

        foreach ($cases as [$currency, $input, $expected]) {
            $result = StandardBankNameHelper::matchBankName($currency, $input, self::NO_DB_CURRENCY_ID);

            if ($expected === null) {
                $this->assertFalse(
                    StandardBankNameHelper::isMatchReliable($result),
                    sprintf('%s「%s」不應被自動採用，實得 %s', $currency, $input, $result['standard_name'] ?? '-'),
                );

                continue;
            }

            $this->assertSame($expected, $result['standard_name'], sprintf('%s「%s」', $currency, $input));
            $this->assertTrue(StandardBankNameHelper::isMatchReliable($result));
        }
    }

    /**
     * 已併入別家的舊條目（retired_into）與一般戶名稱，cash-flow:sync 的比對結果要跟加標記前一樣。
     * 比對改對到另一列平台銀行，sync 會把原列的 cash_flow_banks 停在停用，綁那列的會員出款查不到碼。
     * 只寫進 standard_banks 的條目（sync_visible=false）擁有的名稱一律比對不到，不可落到一般戶：
     * 落到一般戶時，後寫的 UUS 碼會蓋掉一般戶碼（理由見 StandardBankRegistry::hiddenLabels）。
     */
    public function test_transition_markers_keep_general_names_and_block_hidden_names(): void
    {
        $cases = [
            // TopPay 的一般戶名稱：照舊對一般戶
            ['IDR', '(IDR) - Bank Kalbar', '(IDR) - Bank Kalbar'],
            ['IDR', '(IDR) - Bank BPD Kaltim', '(IDR) - Bank Kaltim'],
            ['IDR', '(IDR) - BPD Sumatera Barat', '(IDR) - Bank Nagari'],
            ['IDR', '(IDR) - Bank Nagari', '(IDR) - Bank Nagari'],
            // 已併入 Bank Aceh Syariah 的舊條目：sync 照舊用它
            ['IDR', '(IDR) - Bank Aceh', '(IDR) - Bank Aceh'],
            // 碼表只列舊名稱時另加的新名稱條目：要對到新條目，不可落回舊名、一般戶或 UUS
            ['IDR', '(IDR) - Bank NTB Syariah', '(IDR) - Bank Nusa Tenggara Barat Syariah'],
            ['IDR', '(IDR) - Bank Permata Syariah', '(IDR) - Bank Permata Syariah'],
            // TopPay 的 UUS 名稱與空白、連字號不同的寫法：比對不到
            ['IDR', '(IDR) - BPD Kalimantan Barat UUS', null],
            ['IDR', 'BPD Kalimantan Barat-UUS', null],
            ['IDR', '(IDR) - BPD Kalimantan Timur UUS', null],
            ['IDR', '(IDR) - BPD Sumatera Barat UUS', null],
            ['IDR', 'Bank Kaltim UUS', null],
        ];

        foreach ($cases as [$currency, $input, $expected]) {
            $result = StandardBankNameHelper::matchBankName($currency, $input, self::NO_DB_CURRENCY_ID);

            $this->assertSame($expected, $result['standard_name'], sprintf('%s「%s」', $currency, $input));

            if ($expected === null) {
                $this->assertSame('no_match', $result['matched_by'], sprintf('%s「%s」', $currency, $input));
            }
        }
    }
}
