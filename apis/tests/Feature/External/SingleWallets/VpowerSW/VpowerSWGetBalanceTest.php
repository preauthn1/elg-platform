<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\VpowerSW;

use App\Helpers\Common;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;

/**
 * Vpower 單一錢包 (Seamless Wallet) 取得餘額介面測試
 */
class VpowerSWGetBalanceTest extends VpowerSWTestCase
{
    /**
     * 測試取得基本餘額
     */
    public function test_get_balance(): void
    {
        // Arrange
        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update(['balance' => $walletBalance]);

        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'timestamp' => time() * 1000,
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/balance", $data, ['X-Sign' => $this->makeSign($data)]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'balance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4), // 平台幣別應依匯率換算回遊戲商幣別
            ]);
    }

    /**
     * 測試包含禮碼錢包的總餘額取得
     */
    public function test_get_balance_with_rolling_chips(): void
    {
        // Arrange
        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update(['balance' => $walletBalance]);

        $rollingChips = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->rollingChipWallet->update(['balance' => $rollingChips]);

        $expectedTotal = Common::numAdd($walletBalance, $rollingChips); // 預期總餘額 = 主錢包 + 禮碼錢包

        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'timestamp' => time() * 1000,
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/balance", $data, ['X-Sign' => $this->makeSign($data)]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'balance' => Common::numMul($expectedTotal, $this->vendorInfo->rate, 4),
            ]);
    }

    /**
     * 測試簽名錯誤應失敗
     */
    public function test_get_balance_fails_with_wrong_sign(): void
    {
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'timestamp' => time() * 1000,
        ];

        $response = $this->postJson("{$this->baseurl}/balance", $data, ['X-Sign' => 'wrong_sign']);

        $response->assertStatus(200)
            ->assertJson(['code' => ErrorCodeEnum::OTHER_EXCEPTIONS->value]);
    }

    /**
     * 測試缺少簽名應失敗
     */
    public function test_get_balance_fails_with_missing_sign(): void
    {
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'timestamp' => time() * 1000,
        ];

        $response = $this->postJson("{$this->baseurl}/balance", $data);

        $response->assertStatus(200)
            ->assertJson(['code' => ErrorCodeEnum::OTHER_EXCEPTIONS->value]);
    }

    /**
     * 測試未知使用者應失敗
     */
    public function test_get_balance_fails_with_unknown_user(): void
    {
        $data = [
            'userId' => 0,
            'username' => 'nonexistent_user',
            'timestamp' => time() * 1000,
        ];

        $response = $this->postJson("{$this->baseurl}/balance", $data, ['X-Sign' => $this->makeSign($data)]);

        $response->assertStatus(200)
            ->assertJson(['code' => ErrorCodeEnum::USER_NOT_FOUND->value]);
    }
}
