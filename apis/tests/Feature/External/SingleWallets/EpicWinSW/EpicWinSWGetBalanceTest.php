<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\EpicWinSW;

use App\Helpers\Common;
use App\Vendors\WFSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class EpicWinSWGetBalanceTest extends EpicWinSWTestCase
{
    private string $fn = 'GetBalance';

    #[TestDox('GetBalance：簽名正確時回傳玩家餘額（依匯率換算）')]
    #[Group('rules/vendor-epicwin#R-1')]
    #[Group('rules/vendor-epicwin#R-5')]
    public function test_get_balance_ok(): void
    {
        // Arrange
        $balance = 10000;
        $this->member->update(['balance' => $balance]);

        $post = $this->baseParams();
        $this->regenerateSignature($this->fn, $post);

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => OperatorStatusEnum::OK->value,
                'Description' => 'OK',
                'Balance' => (float) Common::numMul($balance, $this->vendorInfo->rate, 4),
            ]);
    }

    #[TestDox('GetBalance：簽名錯誤時回 INVALID_SIGNATURE')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_get_balance_invalid_signature(): void
    {
        // Arrange
        $post = $this->baseParams(['Signature' => 'invalidSignature']);

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_SIGNATURE->value]);
    }

    #[TestDox('GetBalance：查無玩家時回 INVALID_PLAYER')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_get_balance_invalid_player(): void
    {
        // Arrange
        $post = $this->baseParams(['PlayerId' => 'not_exist_player']);
        $this->regenerateSignature($this->fn, $post);

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_PLAYER->value]);
    }

    #[TestDox('GetBalance：OperatorId 錯誤時回 INVALID_OPERATOR_ID')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_get_balance_invalid_operator(): void
    {
        // Arrange
        $post = $this->baseParams(['OperatorId' => 'WRONG_OPERATOR']);
        $this->regenerateSignature($this->fn, $post);

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_OPERATOR_ID->value]);
    }

    #[TestDox('GetBalance：遊戲帳號停用（status=0）時回 INVALID_PLAYER')]
    #[Group('rules/vendor-epicwin#R-5')]
    public function test_get_balance_inactive_account(): void
    {
        // Arrange
        $this->account->update(['status' => 0]);

        $post = $this->baseParams();
        $this->regenerateSignature($this->fn, $post);

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_PLAYER->value]);
    }
}
