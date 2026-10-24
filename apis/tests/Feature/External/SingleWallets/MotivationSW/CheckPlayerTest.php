<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Vendors\Models\MotivationswAccount;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class CheckPlayerTest extends MotivationSWTestCase
{
    /** R-8：查玩家時帳號存在就回存在（data=true），尚未綁定會員的池帳號也回存在。 */
    #[Group('rules/vendor-motivationsw#R-8')]
    #[TestDox('以合法帳號與正確 wtoken 呼叫後，應回傳 data=true、status.code=0')]
    public function test_check_player(): void
    {
        $response = $this->withHeaders($this->wtokenHeader())
            ->getJson("{$this->baseurl}/player/check/{$this->username}");

        $response->assertOk();
        $this->assertTrue($response->json('data'));
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals('Success', $response->json('status.message'));
    }

    /** R-8：查玩家時帳號存在就回存在（data=true），尚未綁定會員的池帳號也回存在。 */
    #[Group('rules/vendor-motivationsw#R-8')]
    #[TestDox('未綁定 member 的帳號（member_id=-999999999）也應能成功通過')]
    public function test_check_player_with_unbound_account(): void
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
            ->getJson("{$this->baseurl}/player/check/{$unboundUsername}");

        $response->assertOk();
        $this->assertTrue($response->json('data'));
        $this->assertEquals('0', $response->json('status.code'));
    }

    /** rules/single-wallet-callback-common-checks#R-2：回調的簽章或 token 與我方用商戶設定算出的值不符時，擋下並回該遊戲商的錯誤碼，不動錢包、不寫注單。 */
    #[Group('rules/single-wallet-callback-common-checks#R-2')]
    #[TestDox('wtoken 錯誤時應回傳 status.code=1100')]
    public function test_check_player_with_invalid_wtoken(): void
    {
        $response = $this->withHeaders(['wtoken' => 'invalid_wtoken'])
            ->getJson("{$this->baseurl}/player/check/{$this->username}");

        $response->assertOk();
        $this->assertEquals('1100', $response->json('status.code'));
        $this->assertNotNull($response->json('status.message'));
    }
}
