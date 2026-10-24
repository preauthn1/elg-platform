<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WESW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\TestDox;

class WeswValidateTest extends WeswTestCase
{
    #[TestDox('validate：驗證成功回傳玩家資訊與整數分餘額')]
    public function test_validate_ok_returns_player_info_and_balance_in_cents(): void
    {
        // Arrange
        $balance = '1000.0000';
        $this->wallet->update(['balance' => $balance]);

        $post = $this->baseParams();

        // Act
        $response = $this->callEndpoint('validate', $post);

        // Assert
        $expectedBalance = (int) Common::numMul($balance, $this->vendorInfo->rate, 0);

        $response->assertStatus(200)
            ->assertJson([
                'playerID' => $this->playerId,
                'nickname' => $this->playerId,
                'currency' => $this->currency,
                'balance' => $expectedBalance,
            ]);
    }

    #[TestDox('validate：帳號停用時回 404 USER_NOT_FOUND')]
    public function test_validate_disabled_account_returns_user_not_found(): void
    {
        // Arrange
        $this->account->update(['status' => 0]);
        $post = $this->baseParams();

        // Act
        $response = $this->callEndpoint('validate', $post);

        // Assert
        $response->assertStatus(404)
            ->assertJson(['error' => 'USER_NOT_FOUND']);
    }

    #[TestDox('validate：appSecret 錯誤時回 401 CERTIFICATE_ERROR')]
    public function test_validate_wrong_app_secret_returns_certificate_error(): void
    {
        // Arrange
        $post = $this->baseParams(['appSecret' => 'wrong_secret']);

        // Act
        $response = $this->callEndpoint('validate', $post);

        // Assert
        $response->assertStatus(401)
            ->assertJson(['error' => 'CERTIFICATE_ERROR']);
    }

    #[TestDox('validate：operatorID 對不上時回 401 CERTIFICATE_ERROR')]
    public function test_validate_wrong_operator_id_returns_certificate_error(): void
    {
        // Arrange
        $post = $this->baseParams(['operatorID' => 'NOT_EXIST_OPERATOR']);

        // Act
        $response = $this->callEndpoint('validate', $post);

        // Assert
        $response->assertStatus(401)
            ->assertJson(['error' => 'CERTIFICATE_ERROR']);
    }
}
