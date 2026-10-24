<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Helpers\Common;
use App\Vendors\Models\MotivationswAccount;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class BalanceTest extends MotivationSWTestCase
{
    /** R-9：查餘額時回會員真金加禮碼的合計再乘以匯率；尚未綁定會員的池帳號回 0。 */
    #[Group('rules/vendor-motivationsw#R-9')]
    #[TestDox('data.balance 應等於主錢包餘額乘以 vendorInfo rate')]
    public function test_get_balance(): void
    {
        $walletBalance = '1000.5000';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->withHeaders($this->wtokenHeader())
            ->getJson("{$this->baseurl}/transaction/balance/{$this->username}");

        $expected = Common::numMul($walletBalance, $this->vendorInfo->rate, 4);

        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expected, $response->json('data.balance')));
        $this->assertEquals($this->vendorInfo->currency, $response->json('data.currency'));
    }

    /** R-9：查餘額時回會員真金加禮碼的合計再乘以匯率；尚未綁定會員的池帳號回 0。 */
    #[Group('rules/vendor-motivationsw#R-9')]
    #[TestDox('未綁定 member 的帳號（member_id=-999999999）查詢餘額應回傳 balance=0')]
    public function test_get_balance_with_unbound_account(): void
    {
        $unboundUsername = 'test_motivation_unbound';

        MotivationswAccount::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'username' => $unboundUsername,
            'admin' => 1,
            'member_id' => -999999999,
            'status' => 1,
        ]);

        $response = $this->withHeaders($this->wtokenHeader())
            ->getJson("{$this->baseurl}/transaction/balance/{$unboundUsername}");

        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, $response->json('data.balance'));
    }

    /** rules/single-wallet-callback-common-checks#R-2：回調的簽章或 token 與我方用商戶設定算出的值不符時，擋下並回該遊戲商的錯誤碼，不動錢包、不寫注單。 */
    #[Group('rules/single-wallet-callback-common-checks#R-2')]
    #[TestDox('wtoken 錯誤時應回傳 status.code=1100')]
    public function test_get_balance_with_invalid_wtoken(): void
    {
        $response = $this->withHeaders(['wtoken' => 'wrong_token'])
            ->getJson("{$this->baseurl}/transaction/balance/{$this->username}");

        $response->assertOk();
        $this->assertEquals('1100', $response->json('status.code'));
    }
}
