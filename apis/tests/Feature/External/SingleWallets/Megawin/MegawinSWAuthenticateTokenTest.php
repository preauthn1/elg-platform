<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Megawin;

class MegawinSWAuthenticateTokenTest extends MegawinSWTestCase
{
    /**
     * 驗證 AuthenticateToken endpoint：
     * 以合法的 uid / utoken 呼叫後，回應應包含正確的玩家資訊（uid、userName、userEnable）。
     */
    public function test_external_megawin_authenticate_token()
    {
        $response = $this->postJson("{$this->baseurl}/AuthenticateToken", [
            'uid' => $this->username,
            'utoken' => $this->user_token,
            'siteId' => $this->siteId,
            'merchantId' => $this->merchantId,
            'firmId' => $this->firmId,
        ]);

        $response->assertOk();

        $this->assertEquals($this->username, $response->json('userData.uid'));
        $this->assertEquals(config('app.name') . '_' . $this->username, $response->json('userData.userName'));
        $this->assertEquals('Y', $response->json('userData.userEnable'));
    }
}
