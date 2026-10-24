<?php

declare(strict_types=1);

namespace Tests\Unit\Vendors\Models;

use App\Vendors\Models\AbstractAccount;
use App\Vendors\Models\LbbAccount;
use App\Vendors\Models\PgsswAccount;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * i1betnow 的後台顯示帳號（target/op_tickets#3264）。
 *
 * `lbb_accounts.username_modified` 存的是「ACCESS_ID@我方 username」
 * （見 App\Vendors\I1betnow\Driver::createAccount()），ACCESS_ID 每筆都相同、
 * 對後台人員沒有辨識意義，會員詳情抽屜顯示時要去掉。
 *
 * 🔴 只改顯示：登入／轉帳／注單對帳仍以完整的 username_modified 為鍵，
 * 所以這裡只驗 displayVendorUsername()，不得改動 $vendorUsernameColumn。
 *
 * php artisan test tests/Unit/Vendors/Models/LbbAccountDisplayUsernameTest.php
 */
class LbbAccountDisplayUsernameTest extends TestCase
{
    #[Test]
    public function it_strips_the_access_id_prefix(): void
    {
        $this->assertSame(
            'qa00000024',
            (new LbbAccount)->displayVendorUsername('94271446@qa00000024'),
        );
    }

    #[Test]
    public function it_only_splits_on_the_first_separator(): void
    {
        // 我方 username 本身含 @ 時，只能切掉最前面的 ACCESS_ID
        $this->assertSame(
            'user@name',
            (new LbbAccount)->displayVendorUsername('94271446@user@name'),
        );
    }

    #[Test]
    public function it_leaves_values_without_a_separator_untouched(): void
    {
        $account = new LbbAccount;

        $this->assertSame('qa00000024', $account->displayVendorUsername('qa00000024'));
        $this->assertSame('', $account->displayVendorUsername(''));
    }

    #[Test]
    public function it_does_not_change_the_column_used_for_api_and_bet_matching(): void
    {
        $this->assertSame('username_modified', (new LbbAccount)->getVendorUsernameColumn());
    }

    /**
     * 這條是本次修正的守備範圍界線：只有 LbbAccount（全專案僅 I1betnow\Driver 使用）
     * 能改顯示值。像 vplay_accounts 的值是「我方帳號@站台」這種相反形狀，若被一併
     * 套用會把每個會員都顯示成同一個後綴。
     */
    #[Test]
    public function other_vendors_keep_the_raw_value(): void
    {
        $account = new PgsswAccount;

        $this->assertInstanceOf(AbstractAccount::class, $account);
        $this->assertSame(
            'pro00029455@vworld',
            $account->displayVendorUsername('pro00029455@vworld'),
        );
    }
}
