<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\ClotPlaySW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\TestDox;

class ClotPlaySWGetBalanceTest extends ClotPlaySWTestCase
{
    #[TestDox('查詢餘額：正常回傳乘以匯率後的餘額')]
    public function test_returns_balance_scaled_by_rate(): void
    {
        $walletBalance = 1000;
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson($this->baseUrl . '/getBalance?' . http_build_query([
                'player_unique_id' => $this->username,
            ]));

        $expectedBalance = Common::numMul($walletBalance, $this->vendorInfo->rate, 4);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'data' => ['balance' => $expectedBalance],
            ]);
    }

    #[TestDox('查詢餘額：啟用滾存時回傳主錢包與滾存錢包的總和')]
    public function test_with_rolling_chip_enabled_sums_both_wallets(): void
    {
        $this->enableRollingChip();

        $walletBalance = 1000;
        $rollingChipBalance = 200;
        $this->member->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChipBalance]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson($this->baseUrl . '/getBalance?' . http_build_query([
                'player_unique_id' => $this->username,
            ]));

        $expectedBalance = Common::numMul(
            Common::numAdd($walletBalance, $rollingChipBalance),
            $this->vendorInfo->rate,
            4,
        );

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'data' => ['balance' => $expectedBalance],
            ]);
    }

    #[TestDox('查詢餘額：帳號停用時回傳 member not found 錯誤')]
    public function test_disabled_account_returns_error(): void
    {
        $this->account->update(['status' => 0]);
        $this->member->update(['balance' => 1000]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson($this->baseUrl . '/getBalance?' . http_build_query([
                'player_unique_id' => $this->username,
            ]));

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'member not found']);
    }

    #[TestDox('查詢餘額：Authorization header 錯誤時回傳 authorization error')]
    public function test_invalid_token_returns_authorization_error(): void
    {
        $this->member->update(['balance' => 1000]);

        $response = $this->withHeaders(['Authorization' => 'invalid_token'])
            ->getJson($this->baseUrl . '/getBalance?' . http_build_query([
                'player_unique_id' => $this->username,
            ]));

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'authorization error']);
    }
}
