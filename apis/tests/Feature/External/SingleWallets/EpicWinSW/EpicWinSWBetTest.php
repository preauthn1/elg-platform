<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\EpicWinSW;

use App\Helpers\Common;
use App\Vendors\WFSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class EpicWinSWBetTest extends EpicWinSWTestCase
{
    private string $fn = 'Bet';

    private function betParams(array $overrides = []): array
    {
        $post = $this->baseParams(array_merge([
            'BetId' => $this->uniqueId(),
            'RoundId' => $this->uniqueId(),
            'GameCode' => $this->gameCode,
            'GameType' => 'Slot',
            'BetAmount' => 100,
            'ExchangeRate' => 1,
            'TranDateTime' => $this->requestDateTime,
        ], $overrides));
        $this->regenerateSignature($this->fn, $post);

        return $post;
    }

    #[TestDox('Bet：成功扣款並建立注單與 total_bet_record')]
    #[Group('rules/vendor-epicwin#R-7')]
    public function test_bet_ok_deducts_balance_and_creates_records(): void
    {
        // Arrange
        $balance = 1000;
        $this->member->update(['balance' => $balance]);

        $betAmount = 100;
        $post = $this->betParams(['BetAmount' => $betAmount]);

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert
        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numSub($balance, $platformBet, 4);

        $response->assertStatus(200)
            ->assertJson([
                'Status' => OperatorStatusEnum::OK->value,
                'OldBalance' => (float) Common::numMul($balance, $this->vendorInfo->rate, 4),
                'NewBalance' => (float) Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($expectedBalance, $this->member->balance);

        $this->assertDatabaseHas('epic_sw_bet_records', [
            'bet_id' => $post['BetId'],
            'type' => 'N',
            'status' => 0,
        ]);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'member_id' => $this->member->id,
        ]);
    }

    #[TestDox('Bet：餘額不足時回 INSUFFICIENT_BALANCE 且不建注單')]
    #[Group('rules/vendor-epicwin#R-6')]
    public function test_bet_insufficient_balance(): void
    {
        // Arrange
        $this->member->update(['balance' => 10]);
        $post = $this->betParams(['BetAmount' => 100]);

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INSUFFICIENT_BALANCE->value]);

        $this->assertEquals(10, $this->member->balance);
        $this->assertDatabaseMissing('epic_sw_bet_records', ['bet_id' => $post['BetId']]);
    }

    #[TestDox('Bet：BetId 重複時回 DUPLICATE_TRANSACTION 且餘額不變')]
    #[Group('rules/vendor-epicwin#R-6')]
    public function test_bet_duplicate_transaction(): void
    {
        // Arrange
        $this->member->update(['balance' => 1000]);
        $betId = $this->uniqueId();
        $this->createBetRecord($betId, 100, status: 0);

        $post = $this->betParams(['BetId' => $betId, 'BetAmount' => 100]);

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value]);

        // 餘額不應變動
        $this->assertEquals(1000, $this->member->balance);
    }

    #[TestDox('Bet：簽名錯誤時回 INVALID_SIGNATURE')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_bet_invalid_signature(): void
    {
        // Arrange
        $post = $this->betParams();
        $post['Signature'] = 'invalidSignature';

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_SIGNATURE->value]);
    }
}
