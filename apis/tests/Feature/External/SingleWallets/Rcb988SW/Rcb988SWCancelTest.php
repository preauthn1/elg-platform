<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Rcb988SW;

use App\Helpers\Common;
use App\Vendors\Rcb988\Enums\BetRecordStatusEnum;
use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class Rcb988SWCancelTest extends Rcb988SWTestCase
{
    private string $platformTxId = 'TEST-CANCEL-001';

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

    private function makeSettleParams(int $winAmount = 0): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage('settle', [
                'txns' => [[
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $this->platformTxId,
                    'winAmount' => $winAmount,
                    'txTime' => '2026-05-04T11:49:47.000+08:00',
                ]],
            ]),
        ];
    }

    private function makeCancelParams(string $action, string $platformTxId = ''): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage($action, [
                'txns' => [[
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $platformTxId ?: $this->platformTxId,
                    'txTime' => '2026-05-04T11:49:47.000+08:00',
                ]],
            ]),
        ];
    }

    /**
     * 正常取消投注：餘額應還原，紀錄狀態更新為 CANCEL。
     */
    #[Group('rules/vendor-rcb988#R-22')]
    public function test_cancel_bet_ok(): void
    {
        // Arrange
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());

        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeCancelParams('cancelBet'));

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
            'status' => BetRecordStatusEnum::CANCEL->value,
        ]);
    }

    /**
     * cancelBet 找不到投注紀錄：依 issue #3329 特例，應建立金額為 0 的 CANCEL 紀錄並回傳成功，餘額不變。
     */
    #[Group('rules/vendor-rcb988#R-24')]
    public function test_cancel_bet_not_found_creates_zero_record(): void
    {
        // Arrange
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);
        $nonExistentTxId = 'NON-EXISTENT-TX';

        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeCancelParams('cancelBet', $nonExistentTxId));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $expectedBalance,
            ]);

        $this->assertDatabaseHas('rcb988_bet_records', [
            'bet_id' => $nonExistentTxId,
            'bet' => 0,
            'win_lose' => 0,
            'status' => BetRecordStatusEnum::CANCEL->value,
        ]);
    }

    /**
     * 正常 voidBet：餘額應還原，紀錄狀態更新為 VOID_BET。
     */
    #[Group('rules/vendor-rcb988#R-22')]
    public function test_void_bet_ok(): void
    {
        // Arrange
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());

        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeCancelParams('voidBet'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $expectedBalance,
            ]);

        $this->assertDatabaseHas('rcb988_bet_records', [
            'bet_id' => $this->platformTxId,
            'status' => BetRecordStatusEnum::VOID_BET->value,
        ]);
    }

    /**
     * 正常 unsettle：取消結算後餘額應完全還原（含投注部分），狀態更新為 CANCEL。
     */
    #[Group('rules/vendor-rcb988#R-23')]
    public function test_unsettle_ok(): void
    {
        // Arrange
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());
        $this->postEntrance($this->makeSettleParams(winAmount: 0));

        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeCancelParams('unsettle'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $expectedBalance,
            ]);

        $this->assertDatabaseHas('rcb988_bet_records', [
            'bet_id' => $this->platformTxId,
            'status' => BetRecordStatusEnum::CANCEL->value,
        ]);
    }

    /**
     * 正常 voidSettle：取消結算後餘額應完全還原，狀態更新為 VOID_SETTLE（可供後續 unvoidSettle 還原）。
     */
    #[Group('rules/vendor-rcb988#R-23')]
    public function test_void_settle_ok(): void
    {
        // Arrange
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());
        $this->postEntrance($this->makeSettleParams(winAmount: 0));

        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeCancelParams('voidSettle'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $expectedBalance,
            ]);

        $this->assertDatabaseHas('rcb988_bet_records', [
            'bet_id' => $this->platformTxId,
            'status' => BetRecordStatusEnum::VOID_SETTLE->value,
        ]);
    }

    /**
     * cancelBet 狀態不符：紀錄已為 SETTLE 時應回傳 INVALID_TRANSACTION_STATUS。
     */
    #[Group('rules/vendor-rcb988#R-22')]
    public function test_cancel_bet_wrong_status(): void
    {
        // Arrange
        $this->member->update(['balance' => 100]);
        $this->postEntrance($this->makeBetParams());
        $this->postEntrance($this->makeSettleParams(winAmount: 0));

        // Act
        $response = $this->postEntrance($this->makeCancelParams('cancelBet'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::INVALID_TRANSACTION_STATUS->value,
            ]);
    }

    /**
     * voidBet 找不到投注紀錄：應回傳 TX_CODE_NOT_EXISTS（與 cancelBet 不同，不會建立零金額紀錄）。
     */
    #[Group('rules/vendor-rcb988#R-25')]
    public function test_void_bet_not_found(): void
    {
        // Act
        $response = $this->postEntrance($this->makeCancelParams('voidBet', 'NON-EXISTENT-TX'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::TX_CODE_NOT_EXISTS->value,
            ]);
    }
}
