<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\EpicWinSW;

use App\Vendors\WFSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class EpicWinSWRollbackTest extends EpicWinSWTestCase
{
    private string $fn = 'Rollback';

    private function rollbackParams(string $betId, array $overrides = []): array
    {
        $post = $this->baseParams(array_merge([
            'BetId' => $betId,
            'RoundId' => $this->uniqueId(),
            'GameCode' => $this->gameCode,
            'GameType' => 'Slot',
            'BetAmount' => 100,
            'ExchangeRate' => 1,
            'RollbackType' => 1,
            'TranDateTime' => $this->requestDateTime,
        ], $overrides));
        $this->regenerateSignature($this->fn, $post);

        return $post;
    }

    #[TestDox('Rollback：先下注再退款，餘額回到投注前且注單狀態歸 0')]
    #[Group('rules/vendor-epicwin#R-12')]
    public function test_rollback_after_bet_refunds_balance(): void
    {
        // Arrange
        $balance = 1000;
        $this->member->update(['balance' => $balance]);

        $betAmount = 100;
        $betId = $this->uniqueId();
        $this->placeBet($betId, $betAmount);

        // Act
        $post = $this->rollbackParams($betId, ['BetAmount' => $betAmount]);

        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert：退回投注，餘額回到初始值
        $response->assertStatus(200)
            ->assertJson([
                'Status' => OperatorStatusEnum::OK->value,
                'NewBalance' => (float) ($balance * $this->vendorInfo->rate),
            ]);

        $this->assertEquals($balance, $this->member->balance);

        $this->assertDatabaseHas('epic_sw_bet_records', [
            'bet_id' => $betId,
            'status' => 0,
        ]);
    }

    #[TestDox('Rollback：查無對應注單時回 BET_TRANSACTION_NOT_FOUND')]
    #[Group('rules/vendor-epicwin#R-11')]
    public function test_rollback_bet_transaction_not_found(): void
    {
        // Arrange
        $this->member->update(['balance' => 1000]);

        $post = $this->rollbackParams($this->uniqueId());

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value]);

        $this->assertEquals(1000, $this->member->balance);
    }

    #[TestDox('Rollback：簽名錯誤時回 INVALID_SIGNATURE')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_rollback_invalid_signature(): void
    {
        // Arrange
        $post = $this->rollbackParams($this->uniqueId());
        $post['Signature'] = 'invalidSignature';

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_SIGNATURE->value]);
    }

    #[TestDox('Rollback：重送相同退款為冪等（不重複退款、不新增 total_bet_record）')]
    #[Group('rules/vendor-epicwin#R-12')]
    public function test_resending_rollback_is_idempotent(): void
    {
        // Arrange：下注 → 退款一次（餘額應回到初始）
        $initialBalance = 1000;
        $this->member->update(['balance' => $initialBalance]);
        $betId = $this->uniqueId();
        $this->placeBet($betId);

        $rollback = $this->rollbackParams($betId);

        $this->callEndpoint($this->fn, $rollback)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);
        $this->member->refresh();

        $balanceAfterFirst = $this->member->balance;
        $tbrAfterFirst = $this->totalBetRecordCount();

        // Act：原樣重送同一筆 Rollback
        $response = $this->callEndpoint($this->fn, $rollback);
        $this->member->refresh();

        // Assert：重送為冪等 —— 不重複退款、不新增 total_bet_records
        $response->assertStatus(200);
        $this->assertEquals($balanceAfterFirst, $this->member->balance, '重送退款不應再次改變餘額');
        $this->assertEquals($initialBalance, $this->member->balance, '退款後餘額應等於初始值');
        $this->assertSame($tbrAfterFirst, $this->totalBetRecordCount(), '重送退款不應新增 total_bet_records');
    }
}
