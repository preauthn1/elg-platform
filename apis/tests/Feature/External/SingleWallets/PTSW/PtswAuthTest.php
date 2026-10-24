<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW auth（驗證外部令牌）端點基本行為測試。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswAuthTest.php --compact
 */
class PtswAuthTest extends PtswTestCase
{
    #[TestDox('auth：token 正確時回傳 username / currencyCode / countryCode，且 VIP_LEVEL 未設定時不帶 subBrand')]
    #[Group('rules/vendor-ptsw#R-6')]
    public function test_auth_success_without_vip_level_omits_sub_brand(): void
    {
        $response = $this->postJson("{$this->baseurl}/auth", [
            'requestId' => 'req-auth-1',
            'username' => $this->username,
            'externalToken' => $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['error']);
        $response->assertJson([
            'requestId' => 'req-auth-1',
            'username' => $this->account->username,
            'currencyCode' => $this->vendorInfo->currency,
            'countryCode' => $this->vendorInfo->info['COUNTRY_CODE'],
        ]);
        $this->assertArrayNotHasKey(
            'subBrand',
            $response->json(),
            'VIP_LEVEL 未設定時，回應不應帶 subBrand',
        );
    }

    #[TestDox('auth：VIP_LEVEL 存在時回應帶上對應的 subBrand')]
    #[Group('rules/vendor-ptsw#R-6')]
    public function test_auth_success_with_vip_level_returns_sub_brand(): void
    {
        $this->vendorInfo->info = array_merge($this->vendorInfo->info, ['VIP_LEVEL' => 'vipLevel3']);
        $this->vendorInfo->save();

        $response = $this->postJson("{$this->baseurl}/auth", [
            'requestId' => 'req-auth-vip',
            'username' => $this->username,
            'externalToken' => $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['error']);
        $response->assertJson([
            'requestId' => 'req-auth-vip',
            'subBrand' => 'vipLevel3',
        ]);
    }

    #[TestDox('auth：帳號停用（status=0）時回 ERR_PLAYER_NOT_FOUND')]
    #[Group('rules/vendor-ptsw#R-6')]
    public function test_auth_inactive_account_returns_err_player_not_found(): void
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/auth", [
            'requestId' => 'req-auth-inactive',
            'username' => $this->username,
            'externalToken' => $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-auth-inactive',
            'error' => ['code' => 'ERR_PLAYER_NOT_FOUND'],
        ]);
    }

    #[TestDox('auth：externalToken 錯誤時回 ERR_AUTHENTICATION_FAILED')]
    #[Group('rules/vendor-ptsw#R-4')]
    public function test_auth_invalid_token_returns_err_authentication_failed(): void
    {
        $response = $this->postJson("{$this->baseurl}/auth", [
            'requestId' => 'req-auth-bad-token',
            'username' => $this->username,
            'externalToken' => 'wrong-token',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-auth-bad-token',
            'error' => ['code' => 'ERR_AUTHENTICATION_FAILED'],
        ]);
    }
}
