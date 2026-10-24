<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\AiGamingSW;

use PHPUnit\Framework\Attributes\Group;

/**
 * php artisan test tests/Feature/External/SingleWallets/AiGamingSW/GetBalanceTest.php --compact
 */
class GetBalanceTest extends AiGamingSWTestCase
{
    private function makePostData(array $overrides = []): array
    {
        return array_merge($this->signedPostData('getBalance', [
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
        ]), $overrides);
    }

    /**
     * 情境：正常查詢餘額
     * 預期：回傳會員總餘額（乘上 rate）
     */
    #[Group('rules/vendor-aigamingsw#R-1')]
    public function test_get_balance_returns_member_balance()
    {
        $this->member->update(['balance' => '100.0000']);

        $response = $this->postJson("{$this->baseurl}/getBalance", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'errorCode' => 0,
            'errorMessage' => 'success',
            'balance' => 100.0,
        ]);
    }

    /**
     * 情境：帳號被活動系統停用（status=0）
     * 預期：回傳錯誤碼 1（Member not exist）
     */
    #[Group('rules/vendor-aigamingsw#R-7')]
    public function test_get_balance_rejects_disabled_account()
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/getBalance", $this->makePostData());

        $response->assertStatus(200)->assertJson(['errorCode' => 1]);
    }

    /**
     * 情境：簽名錯誤
     * 預期：回傳錯誤碼 4（apiKey Error）
     */
    #[Group('rules/vendor-aigamingsw#R-5')]
    public function test_get_balance_rejects_invalid_signature()
    {
        $response = $this->postJson("{$this->baseurl}/getBalance", $this->makePostData([
            'sign' => 'invalid_signature',
        ]));

        $response->assertStatus(200)->assertJson(['errorCode' => 4]);
    }

    /**
     * 情境：walletType 與商戶幣別不符
     * 預期：回傳錯誤碼 7，拒絕以錯誤幣值入帳
     */
    #[Group('rules/vendor-aigamingsw#R-6')]
    public function test_get_balance_rejects_wallet_type_mismatch()
    {
        $data = $this->signedPostData('getBalance', [
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
            'walletType' => 'USDT',
        ]);

        $response = $this->postJson("{$this->baseurl}/getBalance", $data);

        $response->assertStatus(200)->assertJson(['errorCode' => 7]);
    }

    /**
     * 情境：查無此玩家帳號
     * 預期：回傳錯誤碼 1（Member not exist）
     */
    #[Group('rules/vendor-aigamingsw#R-4')]
    public function test_get_balance_rejects_unknown_username()
    {
        $data = $this->signedPostData('getBalance', [
            'gameCode' => 'TEST',
            'gameTypeName' => 'TEST',
        ]);
        $data['username'] = 'unknown_player';

        $response = $this->postJson("{$this->baseurl}/getBalance", $data);

        $response->assertStatus(200)->assertJson(['errorCode' => 1]);
    }
}
