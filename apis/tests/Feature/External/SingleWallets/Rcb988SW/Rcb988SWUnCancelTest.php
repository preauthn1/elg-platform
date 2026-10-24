<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Rcb988SW;

use App\Helpers\Common;
use App\Vendors\Rcb988\Enums\BetRecordStatusEnum;
use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class Rcb988SWUnCancelTest extends Rcb988SWTestCase
{
    private string $platformTxId = 'TEST-UNCANCEL-001';

    private int $betAmount = 10;

    private function makeBetParams(): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage('bet', [
                'txns' => [[
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $this->platformTxId,
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

    private function makeCancelParams(string $action): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage($action, [
                'txns' => [[
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $this->platformTxId,
                    'txTime' => '2026-05-04T11:49:47.000+08:00',
                ]],
            ]),
        ];
    }

    private function makeUnCancelParams(string $action, string $platformTxId = ''): array
    {
        return [
            'key' => $this->clientSecret,
            'message' => $this->buildMessage($action, [
                'txns' => [[
                    'gameCode' => $this->gameCode,
                    'userId' => $this->username,
                    'platformTxId' => $platformTxId ?: $this->platformTxId,
                ]],
            ]),
        ];
    }

    /**
     * 正常 unvoidBet：還原 VOID_BET 紀錄，重新套用原始投注，餘額應再次被扣除，狀態更新為 BET。
     */
    #[Group('rules/vendor-rcb988#R-27')]
    public function test_unvoid_bet_ok(): void
    {
        // Arrange — 投注 → voidBet → unvoidBet
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());
        $this->postEntrance($this->makeCancelParams('voidBet'));

        // unvoidBet 重新套用原始投注，餘額應再次減少
        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance - $this->betAmount, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeUnCancelParams('unvoidBet'));

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
            'status' => BetRecordStatusEnum::BET->value,
        ]);
    }

    /**
     * 正常 unvoidSettle：還原 VOID_SETTLE 紀錄，重新套用原始結算，
     * winAmount=0 時餘額應回到投注後狀態（扣除 betAmount），狀態更新為 SETTLE。
     */
    #[Group('rules/vendor-rcb988#R-28')]
    public function test_unvoid_settle_ok(): void
    {
        // Arrange — 投注 → 結算(winAmount=0) → voidSettle → unvoidSettle
        $initialBalance = 100;
        $winAmount = 0;
        $this->member->update(['balance' => $initialBalance]);
        $this->postEntrance($this->makeBetParams());
        $this->postEntrance($this->makeSettleParams(winAmount: $winAmount));
        $this->postEntrance($this->makeCancelParams('voidSettle'));

        // unvoidSettle 重新套用原始結算（winAmount=0），餘額應回到投注後狀態（即扣除 betAmount）
        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance - $this->betAmount, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeUnCancelParams('unvoidSettle'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::SUCCESS->value,
                'balance' => $expectedBalance,
            ]);

        $this->assertDatabaseHas('rcb988_bet_records', [
            'bet_id' => $this->platformTxId,
            'status' => BetRecordStatusEnum::SETTLE->value,
        ]);
    }

    /**
     * unvoidBet 狀態不符：紀錄為 BET（非 VOID_BET）時應回傳 INVALID_TRANSACTION_STATUS。
     */
    #[Group('rules/vendor-rcb988#R-27')]
    public function test_unvoid_bet_wrong_status(): void
    {
        // Arrange — 投注後直接送 unvoidBet（狀態為 BET 而非 VOID_BET）
        $this->member->update(['balance' => 100]);
        $this->postEntrance($this->makeBetParams());

        // Act
        $response = $this->postEntrance($this->makeUnCancelParams('unvoidBet'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::INVALID_TRANSACTION_STATUS->value,
            ]);
    }

    /**
     * unvoidBet 找不到投注紀錄：應回傳 TX_CODE_NOT_EXISTS。
     */
    #[Group('rules/vendor-rcb988#R-29')]
    public function test_unvoid_bet_not_found(): void
    {
        // Act
        $response = $this->postEntrance($this->makeUnCancelParams('unvoidBet', 'NON-EXISTENT-TX'));

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::TX_CODE_NOT_EXISTS->value,
            ]);
    }
}
