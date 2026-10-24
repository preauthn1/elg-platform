<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Rcb988SW;

use App\Helpers\Common;
use App\Vendors\Rcb988\Enums\BetRecordStatusEnum;
use App\Vendors\Rcb988\Enums\ErrorCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class Rcb988SWBetTest extends Rcb988SWTestCase
{
    private string $platformTxId = 'TEST-BET-001';

    private int $betAmount = 10;

    private function makeTxn(array $override = []): array
    {
        return array_merge([
            'gameCode' => $this->gameCode,
            'userId' => $this->username,
            'platformTxId' => $this->platformTxId,
            'betAmount' => $this->betAmount,
            'betTime' => '2026-05-04T11:49:47.000+08:00',
        ], $override);
    }

    private function makeParams(array $txnOverride = [], string $key = ''): array
    {
        return [
            'key' => $key ?: $this->clientSecret,
            'message' => $this->buildMessage('bet', [
                'txns' => [$this->makeTxn($txnOverride)],
            ]),
        ];
    }

    /**
     * 正常投注：應扣除餘額、建立狀態為 BET 的紀錄，並回傳 SUCCESS。
     */
    #[Group('rules/vendor-rcb988#R-11')]
    public function test_bet_ok(): void
    {
        // Arrange
        $initialBalance = 100;
        $this->member->update(['balance' => $initialBalance]);

        $expectedBalance = (float) Common::numFloor(
            Common::numMul($initialBalance - $this->betAmount, $this->vendorInfo->rate, 4),
            3
        );

        // Act
        $response = $this->postEntrance($this->makeParams());

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
            'vendor_username' => $this->username,
            'bet' => $this->betAmount,
            'status' => BetRecordStatusEnum::BET->value,
        ]);
    }

    /**
     * 重複的 platformTxId：應回傳 TX_CODE_ALREADY_OPERATED，不重複扣款。
     */
    #[Group('rules/vendor-rcb988#R-16')]
    public function test_bet_duplicate_platform_tx_id(): void
    {
        // Arrange — 先建立一筆相同 platformTxId 的投注紀錄
        $this->member->update(['balance' => 100]);
        $this->postEntrance($this->makeParams());

        // Act — 重複送出相同 platformTxId
        $response = $this->postEntrance($this->makeParams());

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::TX_CODE_ALREADY_OPERATED->value,
            ]);
    }

    /**
     * 餘額不足：betAmount 大於可用餘額時應回傳 NOT_ENOUGH_BALANCE。
     */
    #[Group('rules/vendor-rcb988#R-15')]
    public function test_bet_insufficient_balance(): void
    {
        // Arrange
        $this->member->update(['balance' => 5]);

        // Act — betAmount 10 > balance 5
        $response = $this->postEntrance($this->makeParams());

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::NOT_ENOUGH_BALANCE->value,
            ]);
    }

    /**
     * 會員鎖定：sys_lock=1 時應回傳 ACCOUNT_LOCKED。
     */
    #[Group('rules/vendor-rcb988#R-13')]
    public function test_bet_member_locked(): void
    {
        // Arrange
        $this->member->update(['balance' => 100, 'sys_lock' => 1]);

        // Act
        $response = $this->postEntrance($this->makeParams());

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment([
                'status' => ErrorCodeEnum::ACCOUNT_LOCKED->value,
            ]);
    }
}
