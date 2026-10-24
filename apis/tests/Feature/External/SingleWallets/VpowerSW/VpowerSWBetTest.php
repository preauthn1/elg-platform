<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\VpowerSW;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Vendors\Models\VpowerswBetRecord;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;

/**
 * Vpower 單一錢包 (Seamless Wallet) 下注介面測試
 */
class VpowerSWBetTest extends VpowerSWTestCase
{
    /**
     * 測試基本下注功能
     * 驗證：
     * 1. 扣除會員主錢包餘額
     * 2. 記錄注單資訊 (vpowersw_bet_records)
     * 3. 記錄交易流水 (transaction_logs)
     * 4. 回傳正確的平台幣別餘額
     */
    public function test_bet(): void
    {
        // Arrange (準備環境)
        $betAmount = 100; // 遊戲商傳入的下注金額
        $platformBetAmount = Common::numDiv((string) $betAmount, $this->vendorInfo->rate, 4); // 換算成平台幣別

        $walletBalance = 2000; // 會員初始餘額
        $this->member->update(['balance' => $walletBalance]);

        $expectedWalletBalance = Common::numSub((string) $walletBalance, $platformBetAmount, 4); // 預期扣款後的餘額

        $gameRoundId = 'round_bet_001';
        $transactionId = str_pad('bet_trans_001', 32, '0');

        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => $transactionId,
            'gameId' => $this->gameCode,
            'gameRoundId' => $gameRoundId,
            'completed' => 0,
            'balance' => (string) $betAmount,
            'type' => 0,
            'timestamp' => time() * 1000,
        ];

        // Act (執行動作)
        $response = $this->postJson("{$this->baseurl}/bet", $data, ['X-Sign' => $this->makeSign($data)]);
        $this->member->refresh();

        // Assert (驗證結果)
        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'balance' => Common::numMul($expectedWalletBalance, $this->vendorInfo->rate, 4), // 回傳金額應為匯率換算後數值
            ]);

        // 檢查會員錢包餘額是否正確扣除
        $this->assertEquals($expectedWalletBalance, $this->member->balance);

        // 檢查資料庫是否有注單記錄
        $this->assertDatabaseHas('vpowersw_bet_records', [
            'bet_id' => $gameRoundId,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => 0,
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, '-1', 4),
        ]);

        // 檢查交易流水
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numSub((string) $walletBalance, $expectedWalletBalance, 4),
            'before_balance' => $walletBalance,
            'balance' => $expectedWalletBalance,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => TransactionStatusEnum::STATUS_GAMING,
        ]);
    }

    /**
     * 測試包含禮碼錢包 (Rolling Chip) 的下注功能
     * 驗證：
     * 1. 優先從禮碼錢包扣款
     * 2. 禮碼不足時，差額從主錢包扣除
     * 3. 記錄禮碼變動紀錄 (rolling_chip_logs)
     */
    public function test_bet_with_rolling_chips(): void
    {
        // Arrange
        $this->enableRollingChip(); // 啟用禮碼功能

        $betAmount = 200;
        $platformBetAmount = Common::numDiv((string) $betAmount, $this->vendorInfo->rate, 4);

        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $rollingChips = 90; // 會員現有禮碼(反水金)
        $this->rollingChipWallet->update(['balance' => $rollingChips]);

        $oldTotalBalance = Common::numAdd((string) $walletBalance, (string) $rollingChips);
        $expectedTotalBalance = Common::numSub($oldTotalBalance, $platformBetAmount, 4);

        // 計算預期的禮碼錢包與主錢包剩餘額度
        $newRollingChips = Common::numSub((string) $rollingChips, $platformBetAmount, 4);
        $expectedWalletBalance = (string) $walletBalance;
        if ((float) $newRollingChips < 0) {
            $newRollingChips = '0';
            $remainAmount = Common::numSub($platformBetAmount, (string) $rollingChips, 4);
            $expectedWalletBalance = Common::numSub((string) $walletBalance, $remainAmount, 4);
        }
        $rollingChipsCost = Common::numSub((string) $rollingChips, $newRollingChips, 4); // 實際從禮碼錢包扣除的金額

        $gameRoundId = 'round_bet_rc_001';
        $transactionId = str_pad('bet_rc_trans_001', 32, '0');

        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => $transactionId,
            'gameId' => $this->gameCode,
            'gameRoundId' => $gameRoundId,
            'completed' => 0,
            'balance' => (string) $betAmount,
            'type' => 0,
            'timestamp' => time() * 1000,
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/bet", $data, ['X-Sign' => $this->makeSign($data)]);
        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'balance' => Common::numMul($expectedTotalBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($expectedWalletBalance, $this->member->balance);
        $this->assertEquals((float) $newRollingChips, (float) $this->rollingChipWallet->balance);

        // 檢查注單紀錄中是否正確記錄使用了多少禮碼
        $this->assertDatabaseHas('vpowersw_bet_records', [
            'bet_id' => $gameRoundId,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => 0,
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, '-1', 4),
            'rolling_chips' => $rollingChipsCost,
        ]);

        // 檢查禮碼錢包變動流水
        $this->assertDatabaseHas('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor->id,
            'amount' => $rollingChipsCost,
            'before_balance' => $rollingChips,
            'balance' => $newRollingChips,
            'type' => RollingChipLogTypeEnum::BET_SETTLE,
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
        ]);
    }

    /**
     * 測試餘額不足時下注應失敗
     */
    public function test_bet_fails_with_insufficient_balance(): void
    {
        // Arrange
        $this->member->update(['balance' => 0]); // 將餘額設為 0

        $transactionId = str_pad('bet_insuf_001', 32, '0');
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => $transactionId,
            'gameId' => $this->gameCode,
            'gameRoundId' => 'round_insuf_001',
            'completed' => 0,
            'balance' => '100',
            'type' => 0,
            'timestamp' => time() * 1000,
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/bet", $data, ['X-Sign' => $this->makeSign($data)]);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['code' => ErrorCodeEnum::OTHER_EXCEPTIONS->value]);

        $this->assertEquals(0, VpowerswBetRecord::count()); // 不應產生任何注單
    }

    /**
     * 測試重複單號下注應失敗
     */
    public function test_bet_fails_with_duplicate_round(): void
    {
        // Arrange
        $walletBalance = 5000;
        $this->member->update(['balance' => $walletBalance]);

        $transactionId = str_pad('bet_dup_001', 32, '0');
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => $transactionId,
            'gameId' => $this->gameCode,
            'gameRoundId' => 'round_dup_001',
            'completed' => 0,
            'balance' => '100',
            'type' => 0,
            'timestamp' => time() * 1000,
        ];

        // Act — 第一筆成功
        $this->postJson("{$this->baseurl}/bet", $data, ['X-Sign' => $this->makeSign($data)]);

        // 第二筆使用相同的 gameRoundId
        $duplicateData = array_merge($data, ['transactionId' => str_pad('bet_dup_002', 32, '0')]);
        $response = $this->postJson("{$this->baseurl}/bet", $duplicateData, ['X-Sign' => $this->makeSign($duplicateData)]);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['code' => ErrorCodeEnum::OTHER_EXCEPTIONS->value]);
    }

    /**
     * 測試簽名錯誤時下注應失敗
     */
    public function test_bet_fails_with_wrong_sign(): void
    {
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => str_pad('bet_sign_001', 32, '0'),
            'gameId' => $this->gameCode,
            'gameRoundId' => 'round_sign_001',
            'completed' => 0,
            'balance' => '100',
            'type' => 0,
            'timestamp' => time() * 1000,
        ];

        $response = $this->postJson("{$this->baseurl}/bet", $data, ['X-Sign' => 'invalid_sign']);

        $response->assertStatus(200)
            ->assertJson(['code' => ErrorCodeEnum::OTHER_EXCEPTIONS->value]);
    }
}
