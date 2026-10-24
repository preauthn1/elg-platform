<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WESW;

use App\Helpers\Common;
use App\Vendors\Models\WeswBetRecord;
use App\Vendors\WESW\WeswBetRecordStatusEnum;
use PHPUnit\Framework\Attributes\TestDox;

class WeswDebitTest extends WeswTestCase
{
    private function debitParams(array $overrides = []): array
    {
        return $this->baseParams(array_merge([
            'betID' => $this->uniqueId(),
            'gameID' => $this->gameCode,
            'gameRoundID' => $this->uniqueId(),
            'type' => 'BET',
            'amount' => 100,
            'time' => time(),
        ], $overrides));
    }

    #[TestDox('debit：下注成功扣款並建立 DEBIT 狀態注單')]
    public function test_debit_ok_deducts_balance_and_creates_debit_record(): void
    {
        // Arrange
        $balance = '1000.0000';
        $this->wallet->update(['balance' => $balance]);

        $betId = $this->uniqueId();
        $betAmount = 100;
        $post = $this->debitParams(['betID' => $betId, 'amount' => $betAmount]);

        // Act
        $response = $this->callEndpoint('debit', $post);

        // Assert
        $expectedBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numSub($balance, $expectedBet, 4);

        $response->assertStatus(200)
            ->assertJson([
                'balance' => (int) Common::numMul($expectedBalance, $this->vendorInfo->rate, 0),
                'currency' => $this->currency,
            ]);

        $this->assertSame($this->refreshBalance(), $expectedBalance);

        $betRecord = $this->findBetRecord($betId);
        $this->assertNotNull($betRecord, '應建立注單');
        $this->assertSame(WeswBetRecordStatusEnum::DEBIT->value, $betRecord->status);
        $this->assertSame($expectedBet, Common::numAdd('0', (string) $betRecord->bet, 4));
        $this->assertSame($expectedBet, Common::numAdd('0', (string) $betRecord->valid_bet, 4));
        $this->assertSame('0.0000', Common::numAdd('0', (string) $betRecord->payout, 4));

        // ref_id 為兩階段寫入：先用 betID 佔位，save 後改成 YmdHis+username+補零8碼id 格式
        $this->assertNotSame($betId, $betRecord->ref_id, 'ref_id 應在 save 後被改寫，不再等於 betID');
        $this->assertNotEmpty($betRecord->ref_id);
        $this->assertStringEndsWith(str_pad((string) $betRecord->id, 8, '0', STR_PAD_LEFT), $betRecord->ref_id);
        $response->assertJson(['refID' => $betRecord->ref_id]);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'member_id' => $this->member->id,
        ]);
    }

    #[TestDox('debit：餘額不足時回 402 INSUFFICIENT_BALANCE 且不建注單')]
    public function test_debit_insufficient_balance(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '10.0000']);
        $betId = $this->uniqueId();
        $post = $this->debitParams(['betID' => $betId, 'amount' => 100]);

        // Act
        $response = $this->callEndpoint('debit', $post);

        // Assert
        $response->assertStatus(402)
            ->assertJson(['error' => 'INSUFFICIENT_BALANCE']);

        $this->assertSame('10.0000', $this->refreshBalance());
        $this->assertNull($this->findBetRecord($betId));
    }

    #[TestDox('debit：betID 重複時回 409 REPEAT_TRANSACTION 且餘額不再變動')]
    public function test_debit_duplicate_bet_id_returns_repeat_transaction(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $betId = $this->uniqueId();
        $post = $this->debitParams(['betID' => $betId, 'amount' => 100]);

        $this->callEndpoint('debit', $post)->assertStatus(200);
        $balanceAfterFirst = $this->refreshBalance();

        // Act：原樣重送同一筆 debit
        $response = $this->callEndpoint('debit', $post);

        // Assert
        $response->assertStatus(409)
            ->assertJson(['error' => 'REPEAT_TRANSACTION']);

        $this->assertSame($balanceAfterFirst, $this->refreshBalance());
        $this->assertSame(1, WeswBetRecord::where('bet_id', $betId)->count());
    }

    #[TestDox('debit：BetHelper 判定不可投注（遊戲不存在）時回 500 INTERNAL_SERVER_ERROR')]
    public function test_debit_blocked_by_bet_helper_returns_internal_server_error(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $betId = $this->uniqueId();
        $post = $this->debitParams(['betID' => $betId, 'gameID' => 'NON_EXIST_GAME_TYPE']);

        // Act
        $response = $this->callEndpoint('debit', $post);

        // Assert
        $response->assertStatus(500)
            ->assertJson(['error' => 'INTERNAL_SERVER_ERROR']);

        $this->assertSame('1000.0000', $this->refreshBalance());
        $this->assertNull($this->findBetRecord($betId));
    }
}
