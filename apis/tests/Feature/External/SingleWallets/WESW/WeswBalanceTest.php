<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WESW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\TestDox;

class WeswBalanceTest extends WeswTestCase
{
    #[TestDox('balance：查詢成功回傳整數分餘額與幣別')]
    public function test_balance_ok_returns_balance_in_cents(): void
    {
        // Arrange
        $balance = '500.0000';
        $this->wallet->update(['balance' => $balance]);

        $post = $this->baseParams();

        // Act
        $response = $this->callEndpoint('balance', $post);

        // Assert
        $expectedBalance = (int) Common::numMul($balance, $this->vendorInfo->rate, 0);

        $response->assertStatus(200)
            ->assertJson([
                'balance' => $expectedBalance,
                'currency' => $this->currency,
            ]);
    }

    #[TestDox('balance：帳號停用時回 404 USER_NOT_FOUND')]
    public function test_balance_disabled_account_returns_user_not_found(): void
    {
        // Arrange
        $this->account->update(['status' => 0]);
        $post = $this->baseParams();

        // Act
        $response = $this->callEndpoint('balance', $post);

        // Assert
        $response->assertStatus(404)
            ->assertJson(['error' => 'USER_NOT_FOUND']);
    }
}
