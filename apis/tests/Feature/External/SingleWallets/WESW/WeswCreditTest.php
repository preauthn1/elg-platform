<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WESW;

use App\Helpers\Common;
use App\Vendors\WESW\WeswBetRecordStatusEnum;
use PHPUnit\Framework\Attributes\TestDox;

class WeswCreditTest extends WeswTestCase
{
    #[TestDox('credit：先下注再派彩，餘額反映輸贏並將注單標記為 CREDIT')]
    public function test_credit_ok_settles_bet_and_applies_win_lose(): void
    {
        // Arrange
        $initialBalance = '1000.0000';
        $this->wallet->update(['balance' => $initialBalance]);

        $betAmount = 100;
        $betId = $this->uniqueId();
        $this->placeBet($betId, $betAmount);

        // Act：贏 50（派彩 150，投注 100）
        $win = 150;
        $validBet = 100;
        $row = $this->dataRow([
            'betID' => $betId,
            'amount' => $win,
            'validBetAmount' => $validBet,
        ]);

        $response = $this->callSettleEndpoint('credit', [$row]);

        // Assert：淨變動 = (win - bet) / rate，疊加在原始餘額上
        $winLose = Common::numSub((string) $win, (string) $betAmount, 4);
        $expectedBalance = Common::numAdd($initialBalance, Common::numDiv($winLose, $this->vendorInfo->rate, 4), 4);

        $response->assertStatus(200)
            ->assertJson([
                'balance' => (int) Common::numMul($expectedBalance, $this->vendorInfo->rate, 0),
                'currency' => $this->currency,
            ]);

        $this->assertSame($expectedBalance, $this->refreshBalance());

        $betRecord = $this->findBetRecord($betId);
        $this->assertSame(WeswBetRecordStatusEnum::CREDIT->value, $betRecord->status);
        $this->assertSame('150.0000', Common::numAdd('0', (string) $betRecord->payout, 4));
        $this->assertSame('50.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
        $this->assertSame('100.0000', Common::numAdd('0', (string) $betRecord->valid_bet, 4));

        $response->assertJson(['refID' => $betRecord->ref_id]);
    }

    #[TestDox('credit：查無對應注單時回 410 BET_RECORD_NOT_FOUND')]
    public function test_credit_bet_record_not_found(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $row = $this->dataRow(['betID' => $this->uniqueId(), 'amount' => 100]);

        // Act
        $response = $this->callSettleEndpoint('credit', [$row]);

        // Assert
        $response->assertStatus(410)
            ->assertJson(['error' => 'BET_RECORD_NOT_FOUND']);

        $this->assertSame('1000.0000', $this->refreshBalance());
    }

    #[TestDox('credit：注單狀態已是 CREDIT 時重送回 409 REPEAT_TRANSACTION 且餘額不再變動')]
    public function test_credit_resend_returns_repeat_transaction(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $betId = $this->uniqueId();
        $this->placeBet($betId, 100);

        $row = $this->dataRow(['betID' => $betId, 'amount' => 150, 'validBetAmount' => 100]);
        $this->callSettleEndpoint('credit', [$row])->assertStatus(200);
        $balanceAfterFirst = $this->refreshBalance();

        // Act：原樣重送同一筆 credit
        $response = $this->callSettleEndpoint('credit', [$row]);

        // Assert
        $response->assertStatus(409)
            ->assertJson(['error' => 'REPEAT_TRANSACTION']);

        $this->assertSame($balanceAfterFirst, $this->refreshBalance());
    }

    #[TestDox('credit：data 陣列帶多筆時逐筆處理並各自標記為 CREDIT')]
    public function test_credit_processes_multiple_rows_in_data_array(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $betId1 = $this->uniqueId();
        $betId2 = $this->uniqueId();
        $this->placeBet($betId1, 100);
        $this->placeBet($betId2, 200);

        $tbrBeforeCredit = $this->totalBetRecordCount();

        $rows = [
            $this->dataRow(['betID' => $betId1, 'amount' => 150, 'validBetAmount' => 100]),
            $this->dataRow(['betID' => $betId2, 'amount' => 300, 'validBetAmount' => 200]),
        ];

        // Act
        $response = $this->callSettleEndpoint('credit', $rows);

        // Assert：兩筆都應被逐筆處理並標記為 CREDIT
        $response->assertStatus(200);

        $betRecord1 = $this->findBetRecord($betId1);
        $betRecord2 = $this->findBetRecord($betId2);
        $this->assertSame(WeswBetRecordStatusEnum::CREDIT->value, $betRecord1->status);
        $this->assertSame(WeswBetRecordStatusEnum::CREDIT->value, $betRecord2->status);
        $this->assertSame('150.0000', Common::numAdd('0', (string) $betRecord1->payout, 4));
        $this->assertSame('300.0000', Common::numAdd('0', (string) $betRecord2->payout, 4));

        // TotalBetRecordService::create() 以 bet_record_id 做 updateOrCreate，
        // debit 時已各自建立一筆，credit 是「更新同一筆」而非新增，總筆數應不變
        $this->assertSame($tbrBeforeCredit, $this->totalBetRecordCount(), '派彩應更新既有 total_bet_record，而非新增');
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'bet_record_id' => $betRecord1->id,
        ]);
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'bet_record_id' => $betRecord2->id,
        ]);
    }

    #[TestDox('credit：data 陣列中出現不同 playerID 時回 404 USER_NOT_FOUND 且不寫入')]
    public function test_credit_mismatched_player_id_in_array_returns_user_not_found(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $betId = $this->uniqueId();
        $this->placeBet($betId, 100);

        $rows = [
            $this->dataRow(['betID' => $betId, 'amount' => 150, 'validBetAmount' => 100]),
            $this->dataRow(['betID' => $this->uniqueId(), 'playerID' => 'other_player_999', 'amount' => 50]),
        ];

        // Act
        $response = $this->callSettleEndpoint('credit', $rows);

        // Assert：整批都不應寫入
        $response->assertStatus(404)
            ->assertJson(['error' => 'USER_NOT_FOUND']);

        $this->assertSame('900.0000', $this->refreshBalance(), '下注扣款不受影響，但派彩不應執行');
        $this->assertSame(WeswBetRecordStatusEnum::DEBIT->value, $this->findBetRecord($betId)->status);
    }
}
