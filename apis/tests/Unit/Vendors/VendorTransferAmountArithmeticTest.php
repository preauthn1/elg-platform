<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 轉帳錢包 Driver 的金額取正／取負：Common 運算 ＋ (float) 轉型必須與原生 0 ± $amount 完全等價。
 *
 * 10 支轉帳錢包 Driver（Scr／GreatWall／Pussy888／Play8oy／Vpower／Ct／N918／Obet33／I1betnow／Ebet）
 * 原以 `0 + $amount`／`0 - $amount` 對 string 金額做原生運算，觸及金額運算紅線（金額只走
 * Common::numAdd／numSub）。改寫時 wire 值不可有任何變動——payload 走 asForm()，且**金額值參與
 * 簽名計算**，字串表示一變、簽名跟著變，三方即驗簽失敗。
 *
 * 本測試鎖住等價性：斷言 `(string)` 轉換後的 wire 字串逐字相同（不只是數值相等），
 * 日後若有人把 (float) 拿掉、或把 scale 改小造成截斷，這裡會紅。
 *
 * php artisan test tests/Unit/Vendors/VendorTransferAmountArithmeticTest.php
 */
class VendorTransferAmountArithmeticTest extends TestCase
{
    /**
     * 金額字串樣態。vendorAmount 由 Common::num* 產生，故預設帶 scale 10 的尾隨零；
     * 另涵蓋整數、零、極小值、大金額與超過 4 位小數（用以擋住「scale 傳 4 會截斷」這個坑）。
     *
     * @return array<string, array{string}>
     */
    public static function amountProvider(): array
    {
        return [
            '整數' => ['100'],
            '整數帶 scale 10 尾隨零' => ['100.0000000000'],
            '兩位小數' => ['100.50'],
            '帶尾隨零' => ['100.5000'],
            '五位小數（scale 4 會截斷）' => ['100.50005'],
            '十位小數' => ['100.1234567890'],
            '零' => ['0'],
            '零帶尾隨零' => ['0.0000'],
            '極小值' => ['0.0001'],
            '大金額' => ['99999999.9999'],
            '更大金額' => ['1234567890.1234'],
        ];
    }

    #[Test]
    #[DataProvider('amountProvider')]
    public function test_num_add_keeps_wire_value_identical_to_native_plus(string $amount): void
    {
        $native = 0 + $amount;
        $rewritten = (float) Common::numAdd('0', $amount);

        $this->assertSame(
            (string) $native,
            (string) $rewritten,
            "取正 wire 值不一致（amount: {$amount}）——三方收到的金額字串與簽名會變",
        );
    }

    #[Test]
    #[DataProvider('amountProvider')]
    public function test_num_sub_keeps_wire_value_identical_to_native_minus(string $amount): void
    {
        $native = 0 - $amount;
        $rewritten = (float) Common::numSub('0', $amount);

        $this->assertSame(
            (string) $native,
            (string) $rewritten,
            "取負 wire 值不一致（amount: {$amount}）——三方收到的金額字串與簽名會變",
        );
    }

    /**
     * Ebet／I1betnow 先過 numFloor(…, 2) 再取正負，等價性同樣需成立。
     */
    #[Test]
    #[DataProvider('amountProvider')]
    public function test_equivalence_holds_after_num_floor(string $amount): void
    {
        $floored = Common::numFloor($amount, 2);

        $this->assertSame(
            (string) (0 + $floored),
            (string) (float) Common::numAdd('0', $floored),
            "numFloor 後取正不一致（amount: {$amount}）",
        );
        $this->assertSame(
            (string) (0 - $floored),
            (string) (float) Common::numSub('0', $floored),
            "numFloor 後取負不一致（amount: {$amount}）",
        );
    }

    /**
     * 明示「scale 不可傳 4」這條約束：五位小數在 scale 4 下會被截斷，wire 值即改變。
     * 此測試不是驗證產品碼，而是把踩過的坑固定成回歸保護。
     */
    #[Test]
    public function test_scale_four_would_truncate_and_change_wire_value(): void
    {
        $amount = '100.50005';

        $this->assertNotSame(
            (string) (0 - $amount),
            (string) (float) Common::numSub('0', $amount, 4),
            'scale 4 竟未截斷——若 MathTrait 行為改變，Driver 的 scale 選擇需重新評估',
        );
        $this->assertSame(
            (string) (0 - $amount),
            (string) (float) Common::numSub('0', $amount),
            '預設 scale（10）應維持等價',
        );
    }
}
