<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Rcb988SW;

use App\Helpers\Common;
use App\Vendors\Rcb988\Enums\BetRecordStatusEnum;
use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class Rcb988SWSettleTest extends Rcb988SWTestCase
{
    private string $platformTxId = 'TEST-SETTLE-001';

    private int $betAmount = 10;

    private function makeBetParams(string $platformTxId = ''): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage('bet', [
                'txns' => [[
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $platformTxId ?: $this->platformTxId,
                    'betAmount' => $this->betAmount,
                    'betTime' => '2026-05-04T11:49:47.000+08:00',
                ]],
            ]),
        ];
    }

    private function makeSettleParams(int $winAmount = 0, string $platformTxId = ''): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage('settle', [
                'txns' => [[
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $platformTxId ?: $this->platformTxId,
                    'winAmount' => $winAmount,
                    'txTime' => '2026-05-04T11:49:47.000+08:00',
                ]],
            ]),
        ];
    }

    /**
     * 正常結算（輸）：winAmount=0 時餘額不變，紀錄狀態更新為 SETTLE。
     */
    #[Group('rules/vendor-rcb988#R-18')]
    public function test_settle_ok_loss(): void
    {
        // Arrange — 先投注，再以 winAmount=0 結算（輸）
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());

        $balanceAfterBet = $initialBalance - $this->betAmount;
        $expectedBalance = (float) Common::numFloor(
            Common::numMul($balanceAfterBet, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeSettleParams(winAmount: 0));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'desc' => 'Success',
                'userId' => $this->username,
                'balance' => $expectedBalance,
            ]);

        $this->assertDatabaseHas('rcb988_bet_records', [
            'bet_id' => $this->platformTxId,
            'status' => BetRecordStatusEnum::SETTLE->value,
        ]);
    }

    /**
     * 正常結算（贏）：winAmount > betAmount 時餘額應增加相應的淨利。
     */
    #[Group('rules/vendor-rcb988#R-18')]
    public function test_settle_ok_win(): void
    {
        // Arrange — 先投注，再以 winAmount > betAmount 結算（贏）
        $initialBalance = 100;
        $winAmount = 20;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());

        // 投注後扣 10，結算加回 winAmount=20，淨增 10
        $expectedBalance = (float) Common::numFloor(
            Common::numMul(
                Common::numAdd($initialBalance - $this->betAmount, $winAmount / $this->vendorInfo->rate),
                $this->vendorInfo->rate,
                4
            ),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeSettleParams(winAmount: $winAmount));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $expectedBalance,
            ]);
    }

    /**
     * 投注紀錄不存在：應回傳 TX_CODE_NOT_EXISTS。
     */
    #[Group('rules/vendor-rcb988#R-19')]
    public function test_settle_bet_not_found(): void
    {
        // Act — 找不到 platformTxId 對應的投注紀錄
        $response = $this->postEntrance($this->makeSettleParams(platformTxId: 'NON-EXISTENT-TX'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::TX_CODE_NOT_EXISTS->value,
            ]);
    }

    /**
     * 重複結算：紀錄已為 SETTLE 狀態時應回傳 INVALID_TRANSACTION_STATUS。
     */
    #[Group('rules/vendor-rcb988#R-19')]
    public function test_settle_already_settled(): void
    {
        // Arrange — 先投注，再結算一次
        $this->member->update(['balance' => 100]);
        $this->postEntrance($this->makeBetParams());
        $this->postEntrance($this->makeSettleParams(winAmount: 0));

        // Act — 重複結算
        $response = $this->postEntrance($this->makeSettleParams(winAmount: 0));

        // Assert — 狀態已非 BET，回傳 INVALID_TRANSACTION_STATUS
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::INVALID_TRANSACTION_STATUS->value,
            ]);
    }
}
