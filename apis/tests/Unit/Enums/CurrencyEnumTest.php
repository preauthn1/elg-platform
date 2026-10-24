<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\CurrencyEnum;
use PHPUnit\Framework\TestCase;

/**
 * 錢包幣別白名單（多幣別錢包第二期／階段 2.3）。
 *
 * php artisan test tests/Unit/Enums/CurrencyEnumTest.php
 */
class CurrencyEnumTest extends TestCase
{
    public function test_unit_variants_are_not_wallet_currencies(): void
    {
        foreach ([CurrencyEnum::VND2, CurrencyEnum::KVND, CurrencyEnum::IDR2, CurrencyEnum::KIDR] as $variant) {
            $this->assertFalse(
                CurrencyEnum::isWalletAllowed($variant->value),
                sprintf(
                    '%s(%d) 是單位變體，不可當會員錢包幣別。放行會讓同一種幣別出現兩個錢包、'
                    . '尺度差 1000 倍且不能相加。',
                    $variant->name,
                    $variant->value,
                ),
            );
        }
    }

    public function test_non_currency_codes_are_not_wallet_currencies(): void
    {
        foreach ([CurrencyEnum::FON, CurrencyEnum::GCASH, CurrencyEnum::PayMaya] as $nonCurrency) {
            $this->assertFalse(
                CurrencyEnum::isWalletAllowed($nonCurrency->value),
                sprintf('%s(%d) 不是幣別（電子錢包通道／站台點數），預設不可當錢包幣別', $nonCurrency->name, $nonCurrency->value),
            );
        }
    }

    public function test_crypto_codes_are_wallet_currencies(): void
    {
        foreach ([CurrencyEnum::USDT, CurrencyEnum::TON, CurrencyEnum::BUSD] as $crypto) {
            $this->assertTrue(
                CurrencyEnum::isWalletAllowed($crypto->value),
                sprintf(
                    '%s(%d) 是虛擬貨幣，依決議㉚（2026-09-17）可作為錢包幣別；'
                    . '要不要開放給某一群代理，由 agent_group_currencies 的勾選決定，不在這裡寫死',
                    $crypto->name,
                    $crypto->value,
                ),
            );
        }
    }

    public function test_standard_currencies_are_wallet_currencies(): void
    {
        $standards = [
            CurrencyEnum::MYR,
            CurrencyEnum::SGD,
            CurrencyEnum::VND,
            CurrencyEnum::IDR,
            CurrencyEnum::THB,
            CurrencyEnum::USD,
            CurrencyEnum::PGK,
        ];

        foreach ($standards as $standard) {
            $this->assertTrue(
                CurrencyEnum::isWalletAllowed($standard->value),
                sprintf('%s(%d) 是標準幣別，必須可當錢包幣別', $standard->name, $standard->value),
            );
        }
    }

    public function test_zero_is_not_wallet_allowed(): void
    {
        $this->assertFalse(
            CurrencyEnum::isWalletAllowed(0),
            '0 是「尚未回填／不適用」的 sentinel，不是合法的錢包幣別',
        );
    }

    public function test_wallet_allowed_returns_enum_cases_without_the_excluded_ones(): void
    {
        $allowed = CurrencyEnum::walletAllowed();

        $this->assertNotEmpty($allowed);
        $this->assertContainsOnlyInstancesOf(CurrencyEnum::class, $allowed);
        $this->assertSame(
            array_values($allowed),
            $allowed,
            'walletAllowed() 必須回傳 list（連續索引），呼叫端才能安全地 foreach 與序列化',
        );

        foreach ($allowed as $case) {
            $this->assertTrue(CurrencyEnum::isWalletAllowed($case->value));
        }
    }
}
