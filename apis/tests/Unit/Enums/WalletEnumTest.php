<?php

declare(strict_types=1);

namespace Tests\Unit\Enums;

use App\Enums\WalletEnum;
use Tests\TestCase;

/**
 * WalletEnum 幣別維度語意測試
 *
 * 釘住 08-18 會議決議⑬（08-19 定案）的三分法：保留的三種類型全部拆幣別，
 * 已廢除與點數類固定幣別 0。清單有變動（例如 1003 排行榜 PM 裁決後廢除）
 * 時，這裡要跟著決議一起改，不是跟著實作改。
 *
 * php artisan test tests/Unit/Enums/WalletEnumTest.php
 */
class WalletEnumTest extends TestCase
{
    public function test_currency_aware_types_are_the_three_kept_types()
    {
        $this->assertSame(
            [
                WalletEnum::CODE_PC,
                WalletEnum::CODE_EVENT_WALLET,
                WalletEnum::CODE_ROLLING_CHIP,
            ],
            WalletEnum::currencyAwareTypes()
        );
    }

    public function test_kept_types_are_currency_aware()
    {
        $this->assertTrue(WalletEnum::CODE_PC->isCurrencyAware());
        $this->assertTrue(WalletEnum::CODE_EVENT_WALLET->isCurrencyAware());
        $this->assertTrue(WalletEnum::CODE_ROLLING_CHIP->isCurrencyAware());
    }

    public function test_defunct_and_point_types_are_not_currency_aware()
    {
        // type=2 遊戲錢包、1004 商城幣已廢除；1003 排行榜點數非貨幣
        $this->assertFalse(WalletEnum::CODE_GAME->isCurrencyAware());
        $this->assertFalse(WalletEnum::CODE_SHOP->isCurrencyAware());
        $this->assertFalse(WalletEnum::CODE_EVENT_RANKING->isCurrencyAware());
    }

    public function test_deprecated_types_are_flagged()
    {
        // 已廢除＝不得再新建（決議⑬）；1003 趨近停用但未廢除，不在此列
        $this->assertTrue(WalletEnum::CODE_GAME->isDeprecated());
        $this->assertTrue(WalletEnum::CODE_SHOP->isDeprecated());
        $this->assertFalse(WalletEnum::CODE_PC->isDeprecated());
        $this->assertFalse(WalletEnum::CODE_EVENT_WALLET->isDeprecated());
        $this->assertFalse(WalletEnum::CODE_ROLLING_CHIP->isDeprecated());
        $this->assertFalse(WalletEnum::CODE_EVENT_RANKING->isDeprecated());
    }
}
