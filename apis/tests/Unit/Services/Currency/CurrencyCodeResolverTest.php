<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Currency;

use App\Enums\Currency\CurrencyCodeStatusEnum;
use App\Enums\CurrencyEnum;
use App\Services\Currency\CurrencyCodeResolver;
use PHPUnit\Framework\TestCase;

/**
 * CurrencyCodeResolver 的純邏輯測試：對照表與主檔都用陣列注入，不碰資料庫。
 *
 * 解析鏈固定為 normalize → unit_variant → CurrencyEnum ∩ currencies 主檔，
 * 稽核（currency:audit-vendor-info）與回填（currency:backfill-vendor-info）都走這一條，
 * 這裡守的是「同一個原始字串在兩支命令得到同一個 currency_id」的前提。
 *
 * php artisan test tests/Unit/Services/Currency/CurrencyCodeResolverTest.php
 */
class CurrencyCodeResolverTest extends TestCase
{
    public function test_standard_code_present_in_enum_and_master_is_mapped(): void
    {
        $resolution = $this->resolver()->resolve('MYR');

        $this->assertSame('MYR', $resolution->normalized);
        $this->assertSame('MYR', $resolution->resolved);
        $this->assertSame(CurrencyEnum::MYR->value, $resolution->currencyId);
        $this->assertSame(CurrencyCodeStatusEnum::MAPPED, $resolution->status);
    }

    public function test_spelling_variant_is_normalized_before_lookup(): void
    {
        $resolution = $this->resolver()->resolve('HKB');

        $this->assertSame('HKD', $resolution->normalized);
        $this->assertSame('HKD', $resolution->resolved);
        $this->assertSame(CurrencyEnum::HKD->value, $resolution->currencyId);
        $this->assertSame(CurrencyCodeStatusEnum::MAPPED, $resolution->status);
    }

    public function test_unit_variant_resolves_to_its_standard_currency_id(): void
    {
        // 決議三：KVND 的 currency_id 必須是 VND(3)，不可指到 KVND(5)。
        $resolution = $this->resolver()->resolve('KVND');

        $this->assertSame('KVND', $resolution->normalized);
        $this->assertSame('VND', $resolution->resolved);
        $this->assertSame(CurrencyEnum::VND->value, $resolution->currencyId);
    }

    public function test_normalize_then_unit_variant_are_applied_in_that_order(): void
    {
        $resolution = $this->resolver()->resolve('kIDR');

        $this->assertSame('KIDR', $resolution->normalized);
        $this->assertSame('IDR', $resolution->resolved);
        $this->assertSame(CurrencyEnum::IDR->value, $resolution->currencyId);
    }

    public function test_enum_case_without_master_row_yields_no_currency_id(): void
    {
        // 版控已補 PHP(22)、目標庫 seeder 還沒跑：不可回填出指不到主檔的孤兒 id。
        $resolution = $this->resolver(masterWithout: [CurrencyEnum::PHP->value])->resolve('PHP');

        $this->assertSame(CurrencyEnum::PHP->value, $resolution->enumId);
        $this->assertNull($resolution->masterCode);
        $this->assertNull($resolution->currencyId);
        $this->assertSame(CurrencyCodeStatusEnum::ENUM_ONLY, $resolution->status);
    }

    public function test_master_row_holding_a_different_code_at_the_enum_id_yields_no_currency_id(): void
    {
        $resolution = $this->resolver(masterOverride: [CurrencyEnum::CNY->value => 'ZZC'])->resolve('CNY');

        $this->assertSame('ZZC', $resolution->masterCode);
        $this->assertNull($resolution->currencyId);
        $this->assertSame(CurrencyCodeStatusEnum::ID_MISMATCH, $resolution->status);
    }

    public function test_unresolved_code_has_no_currency_id(): void
    {
        $resolution = $this->resolver()->resolve('MSC');

        $this->assertNull($resolution->currencyId);
        $this->assertSame(CurrencyCodeStatusEnum::UNRESOLVED, $resolution->status);
    }

    public function test_non_currency_code_is_labelled_but_keeps_its_currency_id(): void
    {
        $resolution = $this->resolver()->resolve('FON');

        $this->assertSame(CurrencyCodeStatusEnum::NON_CURRENCY, $resolution->status);
        $this->assertSame(CurrencyEnum::FON->value, $resolution->currencyId);
    }

    public function test_crypto_code_is_labelled_separately_but_keeps_its_currency_id(): void
    {
        $resolution = $this->resolver()->resolve('USDT');

        $this->assertSame(CurrencyCodeStatusEnum::CRYPTO, $resolution->status);
        $this->assertSame(CurrencyEnum::USDT->value, $resolution->currencyId);
    }

    public function test_pending_standard_code_is_pending_seed(): void
    {
        $resolution = $this->resolver()->resolve('BND');

        $this->assertNull($resolution->currencyId);
        $this->assertSame(CurrencyCodeStatusEnum::PENDING_SEED, $resolution->status);
    }

    public function test_code_absent_from_every_list_is_unknown(): void
    {
        $resolution = $this->resolver()->resolve('ZZZ');

        $this->assertNull($resolution->currencyId);
        $this->assertSame(CurrencyCodeStatusEnum::UNKNOWN, $resolution->status);
    }

    public function test_raw_value_is_kept_verbatim_on_the_resolution(): void
    {
        $resolution = $this->resolver()->resolve('kIDR');

        $this->assertSame('kIDR', $resolution->raw);
    }

    /**
     * 以縮小版對照表與主檔建解析器；主檔預設等於 CurrencyEnum 全部 case。
     *
     * @param  array<int, int>  $masterWithout  要從主檔拿掉的 id（模擬 seeder 還沒跑）
     * @param  array<int, string>  $masterOverride  要改寫的主檔 id → code（模擬 id 被別的代碼佔走）
     */
    private function resolver(array $masterWithout = [], array $masterOverride = []): CurrencyCodeResolver
    {
        $master = [];

        foreach (CurrencyEnum::cases() as $case) {
            if (!in_array($case->value, $masterWithout, true)) {
                $master[$case->value] = $case->name;
            }
        }

        $aliases = [
            'normalize' => ['kIDR' => 'KIDR', 'HKB' => 'HKD'],
            'standard' => ['MYR', 'VND', 'IDR', 'HKD', 'PHP', 'CNY'],
            'pending_standard' => ['BND'],
            'unit_variant' => ['KVND' => 'VND', 'KIDR' => 'IDR'],
            'crypto' => ['USDT'],
            'non_currency' => ['FON'],
            'unresolved' => ['MSC' => '站名誤填進幣別欄'],
        ];

        return new CurrencyCodeResolver($aliases, $masterOverride + $master);
    }
}
