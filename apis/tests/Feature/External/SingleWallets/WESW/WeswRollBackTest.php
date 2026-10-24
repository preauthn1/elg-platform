<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WESW;

use App\Vendors\WESW\WeswBetRecordStatusEnum;
use PHPUnit\Framework\Attributes\TestDox;

class WeswRollBackTest extends WeswTestCase
{
    private function rollbackParams(string $betId, array $overrides = []): array
    {
        return $this->baseParams(array_merge([
            'betID' => $betId,
            'type' => 'CANCEL',
            'time' => time(),
        ], $overrides));
    }

    #[TestDox('rollback：取消投注成功歸零 bet/payout/win_lose 並將注單轉為 ROLLBACK 狀態')]
    public function test_rollback_ok_refunds_bet_and_zeroes_out_record(): void
    {
        // Arrange
        $initialBalance = '1000.0000';
        $this->wallet->update(['balance' => $initialBalance]);

        $betAmount = 100;
        $betId = $this->uniqueId();
        $this->placeBet($betId, $betAmount);

        // Act
        $post = $this->rollbackParams($betId);
        $response = $this->callEndpoint('rollback', $post);

        // Assert：退回投注，餘額回到初始值
        $response->assertStatus(200)
            ->assertJson([
                'balance' => (int) $initialBalance,
                'currency' => $this->currency,
            ]);

        $this->assertSame($initialBalance, $this->refreshBalance());

        $betRecord = $this->findBetRecord($betId);
        $this->assertSame(WeswBetRecordStatusEnum::ROLLBACK->value, $betRecord->status);
        $this->assertSame('0.0000', (string) $betRecord->bet);
        $this->assertSame('0.0000', (string) $betRecord->valid_bet);
        $this->assertSame('0.0000', (string) $betRecord->payout);
        $this->assertSame('0.0000', (string) $betRecord->win_lose);

        $response->assertJson(['refID' => $betRecord->ref_id]);
    }

    #[TestDox('rollback：查無對應注單時回 410 BET_RECORD_NOT_FOUND')]
    public function test_rollback_bet_record_not_found(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $post = $this->rollbackParams($this->uniqueId());

        // Act
        $response = $this->callEndpoint('rollback', $post);

        // Assert
        $response->assertStatus(410)
            ->assertJson(['error' => 'BET_RECORD_NOT_FOUND']);

        $this->assertSame('1000.0000', $this->refreshBalance());
    }

    #[TestDox('rollback：注單狀態已是 ROLLBACK 時重送回 409 REPEAT_TRANSACTION 且餘額不再變動')]
    public function test_rollback_resend_returns_repeat_transaction(): void
    {
        // Arrange
        $initialBalance = '1000.0000';
        $this->wallet->update(['balance' => $initialBalance]);
        $betId = $this->uniqueId();
        $this->placeBet($betId, 100);

        $post = $this->rollbackParams($betId);
        $this->callEndpoint('rollback', $post)->assertStatus(200);
        $this->assertSame($initialBalance, $this->refreshBalance());

        // Act：原樣重送同一筆 rollback
        $response = $this->callEndpoint('rollback', $post);

        // Assert
        $response->assertStatus(409)
            ->assertJson(['error' => 'REPEAT_TRANSACTION']);

        $this->assertSame($initialBalance, $this->refreshBalance(), '重送取消不應再次改變餘額');
    }
}
