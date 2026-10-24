<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Megawin;

use App\Helpers\Common;

class MegawinSWGetBalanceTest extends MegawinSWTestCase
{
    /**
     * 驗證 GetBalance endpoint：
     * 回傳的 userGold 應等於會員餘額乘以 vendorInfo rate（幣別換算）。
     */
    public function test_get_balance()
    {
        $balance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $balance,
        ]);

        $response = $this->postJson("{$this->baseurl}/GetBalance", [
            'uid' => $this->username,
            'utoken' => $this->user_token,
            'siteId' => $this->siteId,
            'merchantId' => $this->merchantId,
            'firmId' => $this->firmId,
        ]);

        $expected = Common::numMul($balance, $this->vendorInfo->rate);

        $response->assertOk();
        $this->assertEquals(0, Common::numCompare($expected, $response->json('userGold')));
    }
}
