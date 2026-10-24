<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\MotivationswBetRecord;
use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class DebitTest extends MotivationSWTestCase
{
    private string $roundId = 'round_debit_001';

    private string $mtcode = 'mtcode_debit_001';

    /** R-25：收到事後扣款（debit）回調時，依局號找到已結算或已轉回的注單，扣款額從會員真金錢包扣除，注單輸贏減去扣款額並改為已扣款狀態，總注單同步減去扣款額。 */
    #[Group('rules/vendor-motivationsw#R-25')]
    #[TestDox('事後扣款從真金錢包扣除，注單與總注單的輸贏同步減少，注單狀態改為 DEBIT')]
    public function test_debit(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $platformBetAmount = Common::numDiv(200, $this->vendorInfo->rate);
        $betRecord = $this->createSettledRound($platformBetAmount, $platformBetAmount);

        $debitVendorAmount = 100;
        $platformDebitAmount = Common::numDiv($debitVendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numSub($walletBalance, $platformDebitAmount);
        $expectedWinLose = Common::numSub($platformBetAmount, $platformDebitAmount);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/debit", $this->debitPayload($debitVendorAmount));

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));
        $this->assertEquals(0, Common::numCompare(
            Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            (string) $response->json('data.balance')
        ));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::DEBIT->value,
            'win_lose' => $expectedWinLose,
        ]);
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendorInfo->vendor_id,
            'bet_record_id' => $betRecord->id,
            'win_lose' => $expectedWinLose,
        ]);
    }

    /** R-26：事後扣款時會員真金不足以扣除扣款額，回錯誤碼 1005，錢包、注單與總注單都不變。 */
    #[Group('rules/vendor-motivationsw#R-26')]
    #[TestDox('真金不足以扣款時回 1005，錢包、注單與總注單都不變')]
    public function test_debit_insufficient_balance(): void
    {
        $walletBalance = 30;
        $this->member->update(['balance' => $walletBalance]);

        $platformBetAmount = Common::numDiv(200, $this->vendorInfo->rate);
        $betRecord = $this->createSettledRound($platformBetAmount, $platformBetAmount);

        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/debit", $this->debitPayload(100));

        $this->wallet->refresh();

        $response->assertOk();
        $this->assertEquals('1005', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($walletBalance, $this->wallet->balance));
        $this->assertDatabaseHas('motivationsw_bet_records', [
            'id' => $betRecord->id,
            'status' => BetRecordStatusEnum::ENDROUND->value,
            'win_lose' => $platformBetAmount,
        ]);
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendorInfo->vendor_id,
            'bet_record_id' => $betRecord->id,
            'win_lose' => $platformBetAmount,
        ]);
    }

    /** R-24：收到事後扣款（debit）或補款（credit）回調，但該局找不到已結算或已轉回的注單時，回錯誤碼 1014。 */
    #[Group('rules/vendor-motivationsw#R-24')]
    #[TestDox('roundid 找不到 ENDROUND/ROLLIN 注單時應回傳 status.code=1014')]
    public function test_debit_record_not_found(): void
    {
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/debit", $this->debitPayload(100, 'non_existent_round'));

        $response->assertOk();
        $this->assertEquals('1014', $response->json('status.code'));
    }

    /**
     * 建一局已結算的注單與它的總注單。$winLose 是結算後的輸贏（派彩減投注）。
     */
    private function createSettledRound(string $bet, string $winLose): MotivationswBetRecord
    {
        $betRecord = MotivationswBetRecord::create([
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::ENDROUND,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => $winLose,
            'rolling_chips' => 0,
            'bet_time' => '2024-01-01 00:00:00',
            'result_date' => '2024-01-01 01:00:00',
        ]);

        TotalBetRecord::create([
            'summary_time' => '2024-01-01 01:00:00',
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendorInfo->vendor_id,
            'bet_record_id' => $betRecord->id,
            'game_id' => $this->game->id,
            'reference_no' => $this->roundId,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win_lose' => $winLose,
            'bet_time' => '2024-01-01 00:00:00',
            'payout_time' => '2024-01-01 01:00:00',
        ]);

        return $betRecord;
    }

    private function debitPayload(int $vendorAmount, ?string $roundId = null): array
    {
        return [
            'account' => $this->username,
            'eventTime' => '2024-01-01 02:00:00',
            'gamehall' => 'motivation',
            'gamecode' => $this->game->game_type,
            'roundid' => $roundId ?? $this->roundId,
            'amount' => $vendorAmount,
            'mtcode' => $this->mtcode . '_debit',
        ];
    }
}
