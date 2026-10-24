<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WcasinoSW;

use App\Helpers\Common;
use App\Vendors\Wcasino\Enums\ErrorCodeEnum;

class WcasinoSWGetBalanceTest extends WcasinoSWTestCase
{
    /**
     * 測試查詢餘額是否回傳正確的金額
     * 使用 POST 請求並帶入 JSON body 驗證
     */
    public function test_get_balance_returns_correct_balance(): void
    {
        // Arrange: 準備測試資料
        $walletBalance = 1000;
        $this->member->update(['balance' => $walletBalance]);

        $params = [
            'appid' => $this->appId,
            'username' => $this->username,
            'notifyid' => 'notify_' . uniqid(),
        ];
        $params['sign'] = $this->generateSign($params);

        // Act
        $response = $this->getJson($this->baseUrl . '/balance?' . http_build_query($params));

        // Assert: 驗證回應結果
        $expectedBalance = Common::numMul($walletBalance, $this->vendorInfo->rate);

        $response->assertStatus(200)
            ->assertJson([
                'result' => ErrorCodeEnum::SUCCESS->value,
                'desc' => 'OK',
                'balance' => $expectedBalance,
            ]);
    }

    /**
     * 測試當啟用滾存點數 (Rolling Chips) 時，查詢餘額是否為兩者錢包之總和
     */
    public function test_get_balance_with_rolling_chips_sums_both_wallets(): void
    {
        // Arrange: 啟用滾存點數並設定餘額
        $this->enableRollingChip();

        $walletBalance = 1000;
        $rollingChips = 200;
        $this->member->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChips]);

        $params = [
            'appid' => $this->appId,
            'username' => $this->username,
            'notifyid' => 'notify_' . uniqid(),
        ];
        $params['sign'] = $this->generateSign($params);

        // Act
        $response = $this->getJson($this->baseUrl . '/balance?' . http_build_query($params));

        // Assert: 驗證回傳金額為主錢包與滾存錢包之和乘以匯率
        $expectedBalance = Common::numMul(
            Common::numAdd($walletBalance, $rollingChips),
            $this->vendorInfo->rate,
        );

        $response->assertStatus(200)
            ->assertJson([
                'result' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $expectedBalance,
            ]);
    }

    /**
     * 測試當簽名 (sign) 錯誤時，是否回傳對應的錯誤代碼
     */
    public function test_get_balance_with_invalid_sign_returns_error(): void
    {
        // Arrange: 準備測試資料
        $this->member->update(['balance' => 1000]);

        // Act
        $response = $this->getJson($this->baseUrl . '/balance?' . http_build_query([
            'appid' => $this->appId,
            'username' => $this->username,
            'notifyid' => 'notify_' . uniqid(),
            'sign' => 'invalid_sign_value',
        ]));

        // Assert: 驗證回傳結果為簽名錯誤
        $response->assertStatus(200)
            ->assertJson([
                'result' => ErrorCodeEnum::SIGN_ERROR->value,
            ]);
    }
}
