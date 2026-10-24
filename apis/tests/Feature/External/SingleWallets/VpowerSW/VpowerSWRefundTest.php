<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\VpowerSW;

use App\Helpers\Common;
use App\Vendors\VpowerSW\Enums\BetTypeEnum;
use App\Vendors\VpowerSW\Enums\ErrorCodeEnum;

/**
 * Vpower 單一錢包 (Seamless Wallet) 退款 (Refund) 介面測試
 */
class VpowerSWRefundTest extends VpowerSWTestCase
{
    private string $betRoundId = 'round_refund_001';

    private string $betTransactionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->betTransactionId = str_pad('refund_bet_trans_001', 32, '0');
    }

    /**
     * 測試退款功能
     * 驗證：
     * 1. 成功退回下注金額到會員錢包
     * 2. 注單記錄 (vpowersw_bet_records) 狀態更新為已結算(1)且金額歸零
     */
    public function test_refund(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = 100;
        $platformBetAmount = Common::numDiv((string) $betAmount, $this->vendorInfo->rate, 4);
        $this->bet($betAmount); // 先執行一筆下注

        $balanceAfterBet = Common::numSub((string) $walletBalance, $platformBetAmount, 4);

        $refundTransactionId = str_pad('refund_trans_001', 32, '0');
        $data = [
            'userId' => $this->member->id,
            'username' => $this->username,
            'transactionId' => $refundTransactionId,
            'gameId' => $this->gameCode,
            'gameRoundId' => $this->betRoundId,
            'completed' => 1,
            'balance' => (string) $betAmount,
            'type' => BetTypeEnum::REFUND->value, // 指定類型為退款
            'timestamp' => time() * 1000,
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/settle", $data, ['X-Sign' => $this->makeSign($data)]);
        $this->member->refresh();

        // Assert — 餘額應回復到下注前
        $response->assertStatus(200)
            ->assertJson([
                'code' => ErrorCodeEnum::SUCCESS->value,
                'balance' => Common::numMul((string) $walletBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals(Common::numAdd('0', (string) $walletBalance, 4), $this->member->balance);

        // 檢查注單是否已被更新為退款狀態 (win_lose = 0, bet = 0)
        $this->assertDatabaseHas('vpowersw_bet_records', [
            'bet_id' => $this->betRoundId,
            'status' => 1,
            'win_lose' => 0,
            'bet' => 0,
            'valid_bet' => 0,
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
