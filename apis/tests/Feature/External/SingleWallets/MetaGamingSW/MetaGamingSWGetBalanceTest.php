<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MetaGamingSW;

use App\Helpers\Common;
use App\Vendors\MetaGamingSW\OperatorStatusEnum;

/**
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW/MetaGamingSWGetBalanceTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MetaGamingSW --compact
 */
class MetaGamingSWGetBalanceTest extends MetaGamingSWTestCase
{
    private string $functionName = 'GetBalance';

    private function makePostData(): array
    {
        $data = $this->basePostData();
        $this->regenerateSignature($this->functionName, $data);

        return $data;
    }

    /**
     * 情境：會員錢包有餘額
     * 預期：回傳 OK 及依匯率換算後的正確餘額
     */
    public function test_get_balance_returns_member_balance()
    {
        $walletBalance = '1234.5000';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'Balance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);
    }

    /**
     * 情境：實際線上請求會攜帶 AuthToken（現有測試皆未涵蓋此欄位存在時的成功路徑）
     * 預期：AuthToken 與帳號 session_token 相符時，回傳 OK 及正確餘額
     */
    public function test_get_balance_succeeds_with_auth_token_present()
    {
        $walletBalance = '1234.5000';
        $this->member->update(['balance' => $walletBalance]);
        $this->account->update(['session_token' => '21010e6fc2af4c8a980b3246e695acda']);

        $data = $this->basePostData();
        $data['AuthToken'] = '21010e6fc2af4c8a980b3246e695acda';
        $this->regenerateSignature($this->functionName, $data);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'Balance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);
    }

    /**
     * 情境：遊戲帳號狀態為停用
     * 預期：回傳 PLAYER_INACTIVE
     */
    public function test_get_balance_returns_inactive_player_error()
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::PLAYER_INACTIVE->value,
        ]);
    }

    /**
     * 情境：PlayerId 不存在於系統
     * 預期：回傳 INVALID_PLAYER
     */
    public function test_get_balance_returns_invalid_player_error_for_unknown_player()
    {
        $data = $this->basePostData();
        $data['PlayerId'] = 'unknown_player';
        $this->regenerateSignature($this->functionName, $data);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_PLAYER->value,
        ]);
    }

    /**
     * 情境：請求攜帶錯誤的 Signature
     * 預期：回傳 INVALID_SIGNATURE
     */
    public function test_get_balance_returns_invalid_signature_error()
    {
        $data = $this->basePostData();
        $data['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
    }

    /**
     * 情境：OperatorId 不存在或不屬於當前帳號
     * 預期：回傳 INVALID_OPERATOR_ID
     */
    public function test_get_balance_returns_invalid_operator_id_error()
    {
        $data = $this->basePostData();
        $data['OperatorId'] = 'unknown_operator';
        $this->regenerateSignature($this->functionName, $data);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_OPERATOR_ID->value,
        ]);
    }

    /**
     * 情境：請求帶入不正確的 AuthToken
     * 預期：回傳 INTERNAL_SERVER_ERROR
     */
    public function test_get_balance_returns_internal_server_error_when_auth_token_mismatch()
    {
        $this->account->update(['session_token' => 'valid_auth_token']);

        $data = $this->basePostData();
        $data['AuthToken'] = 'wrong_auth_token';
        $this->regenerateSignature($this->functionName, $data);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INTERNAL_SERVER_ERROR->value,
        ]);
    }
}
