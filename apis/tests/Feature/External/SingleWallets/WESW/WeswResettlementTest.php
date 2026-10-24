<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WESW;

use App\Helpers\Common;
use App\Vendors\WESW\WeswBetRecordStatusEnum;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * resettlement 與 credit 共用同一個 protected settle()，行為相同，
 * 差別僅在寫入的 WeswBetRecordStatusEnum 是 RESETTLEMENT 而非 CREDIT。
 * 此處只挑關鍵情境驗證，不重複整套 credit 測試。
 */
class WeswResettlementTest extends WeswTestCase
{
    #[TestDox('resettlement：重新結算成功，餘額反映輸贏並將注單標記為 RESETTLEMENT')]
    public function test_resettlement_ok_settles_bet_and_applies_win_lose(): void
    {
        // Arrange
        $initialBalance = '1000.0000';
        $this->wallet->update(['balance' => $initialBalance]);

        $betAmount = 100;
        $betId = $this->uniqueId();
        $this->placeBet($betId, $betAmount);

        // Act：重新結算為輸 30（派彩 70，投注 100）
        $win = 70;
        $row = $this->dataRow([
            'betID' => $betId,
            'amount' => $win,
            'validBetAmount' => $betAmount,
        ]);

        $response = $this->callSettleEndpoint('resettlement', [$row]);

        // Assert
        $winLose = Common::numSub((string) $win, (string) $betAmount, 4);
        $expectedBalance = Common::numAdd($initialBalance, Common::numDiv($winLose, $this->vendorInfo->rate, 4), 4);

        $response->assertStatus(200)
            ->assertJson([
                'balance' => (int) Common::numMul($expectedBalance, $this->vendorInfo->rate, 0),
                'currency' => $this->currency,
            ]);

        $this->assertSame($expectedBalance, $this->refreshBalance());

        $betRecord = $this->findBetRecord($betId);
        $this->assertSame(WeswBetRecordStatusEnum::RESETTLEMENT->value, $betRecord->status);
        $this->assertSame('70.0000', Common::numAdd('0', (string) $betRecord->payout, 4));
        $this->assertSame('-30.0000', Common::numAdd('0', (string) $betRecord->win_lose, 4));
    }

    #[TestDox('resettlement：查無對應注單時回 410 BET_RECORD_NOT_FOUND')]
    public function test_resettlement_bet_record_not_found(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $row = $this->dataRow(['betID' => $this->uniqueId(), 'amount' => 100]);

        // Act
        $response = $this->callSettleEndpoint('resettlement', [$row]);

        // Assert
        $response->assertStatus(410)
            ->assertJson(['error' => 'BET_RECORD_NOT_FOUND']);

        $this->assertSame('1000.0000', $this->refreshBalance());
    }

    #[TestDox('resettlement：注單狀態已是 RESETTLEMENT 時重送回 409 REPEAT_TRANSACTION')]
    public function test_resettlement_resend_returns_repeat_transaction(): void
    {
        // Arrange
        $this->wallet->update(['balance' => '1000.0000']);
        $betId = $this->uniqueId();
        $this->placeBet($betId, 100);

        $row = $this->dataRow(['betID' => $betId, 'amount' => 70, 'validBetAmount' => 100]);
        $this->callSettleEndpoint('resettlement', [$row])->assertStatus(200);
        $balanceAfterFirst = $this->refreshBalance();

        // Act：原樣重送同一筆 resettlement
        $response = $this->callSettleEndpoint('resettlement', [$row]);

        // Assert
        $response->assertStatus(409)
            ->assertJson(['error' => 'REPEAT_TRANSACTION']);

        $this->assertSame($balanceAfterFirst, $this->refreshBalance());
    }
}
