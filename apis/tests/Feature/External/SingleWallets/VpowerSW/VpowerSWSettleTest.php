<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\VpowerSW;

use App\Helpers\Common;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;

/**
 * Vpower 單一錢包 (Seamless Wallet) 結算 (Settle) 介面測試
 */
class VpowerSWSettleTest extends VpowerSWTestCase
{
    private string $betRoundId = 'round_settle_001';

    private string $betTransactionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->betTransactionId = str_pad('settle_bet_trans_001', 32, '0');
    }

    /**
     * 測試結算功能
     * 驗證：
     * 1. 會員錢包餘額正確增加 (派彩金額)
     * 2. 資料庫產生新的結算注單紀錄，並關聯至原始下注單
     * 3. 原始下注單狀態更新為已結算(1)
     */
    public function test_settle(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = 100;
        $platformBetAmount = Common::numDiv((string) $betAmount, $this->vendorInfo->rate, 4);
        $this->bet($betAmount); // 先進行一筆下注

        $balanceAfterBet = Common::numSub((string) $walletBalance, $platformBetAmount, 4);

        $winAmount = 200; // 派彩金額 (遊戲商幣別)
        $platformWinAmount = Common::numDiv((string) $winAmount, $this->vendorInfo->rate, 4); // 換算平台幣別
        $balanceAfterSettle = Common::numAdd($balanceAfterBet, $platformWinAmount, 4);

        $settleTransactionId = str_pad('settle_trans_001', 32, '0');
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => $settleTransactionId,
            'gameId' => $this->gameCode,
            'gameRoundId' => $this->betRoundId,
            'completed' => 1,
            'balance' => (string) $winAmount,
            'type' => 0,
            'timestamp' => time() * 1000,
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/settle", $data, ['X-Sign' => $this->makeSign($data)]);
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'balance' => Common::numMul($balanceAfterSettle, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($balanceAfterSettle, $this->member->balance);

        // 檢查是否產生結算注單，且 win_lose 金額正確
        $this->assertDatabaseHas('vpowersw_bet_records', [
            'bet_id' => $settleTransactionId,
            'reference_bet_id' => $this->betRoundId, // 應對應到下注時的 ID
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => 1,
            'win_lose' => $platformWinAmount,
        ]);

        // 檢查原始下注單狀態是否已更新
        $this->assertDatabaseHas('vpowersw_bet_records', [
            'bet_id' => $this->betRoundId,
            'status' => 1,
        ]);
    }

    /**
     * 輔助方法：模擬下注動作
     */
    private function bet(int $betAmount): void
    {
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => $this->betTransactionId,
            'gameId' => $this->gameCode,
            'gameRoundId' => $this->betRoundId,
            'completed' => 0,
            'balance' => (string) $betAmount,
            'type' => 0,
            'timestamp' => time() * 1000,
        ];

        $this->postJson("{$this->baseurl}/bet", $data, ['X-Sign' => $this->makeSign($data)]);
    }
}
