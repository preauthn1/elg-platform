<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MotivationSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\MotivationswBetRecord;
use App\Vendors\MotivationSW\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class CreditTest extends MotivationSWTestCase
{
    private string $roundId = 'round_credit_001';

    private string $mtcode = 'mtcode_credit_001';

    /** R-23：收到事後補款（credit）回調時，依局號找到已結算或已轉回的注單，補款額加入會員錢包，注單輸贏加上補款額並改為已補款狀態，總注單同步加上補款額。 */
    #[Group('rules/vendor-motivationsw#R-23')]
    #[TestDox('credit 後主錢包增加平台金額並將注單狀態更新為 CREATE')]
    public function test_credit(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $platformBetAmount = Common::numDiv(200, $this->vendorInfo->rate);
        $originalWinLose = Common::numMul($platformBetAmount, -1);

        $betRecord = MotivationswBetRecord::create([
            'bet_id' => $this->roundId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::ENDROUND,
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => $originalWinLose,
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
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => $originalWinLose,
            'bet_time' => '2024-01-01 00:00:00',
            'payout_time' => '2024-01-01 01:00:00',
        ]);

        $creditVendorAmount = 100;
        $platformCreditAmount = Common::numDiv($creditVendorAmount, $this->vendorInfo->rate);
        $expectedBalance = Common::numAdd($walletBalance, $platformCreditAmount);
        $expectedWinLose = Common::numAdd($originalWinLose, $platformCreditAmount);

        // Act
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/credit", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 02:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => $this->roundId,
                'amount' => $creditVendorAmount,
                'mtcode' => $this->mtcode . '_credit',
            ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals('0', $response->json('status.code'));
        $this->assertEquals(0, Common::numCompare($expectedBalance, $this->wallet->balance));

        $this->assertDatabaseHas('motivationsw_bet_records', [
            'bet_id' => $this->roundId,
            'mtcode' => $this->mtcode,
            'status' => BetRecordStatusEnum::CREATE,
            'win_lose' => $expectedWinLose,
        ]);
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendorInfo->vendor_id,
            'bet_record_id' => $betRecord->id,
            'win_lose' => $expectedWinLose,
        ]);
    }

    /** R-24：收到事後扣款（debit）或補款（credit）回調，但該局找不到已結算或已轉回的注單時，回錯誤碼 1014。 */
    #[Group('rules/vendor-motivationsw#R-24')]
    #[TestDox('roundid 找不到 ENDROUND/ROLLIN 注單時應回傳 status.code=1014')]
    public function test_credit_record_not_found(): void
    {
        $response = $this->withHeaders($this->wtokenHeader())
            ->postJson("{$this->baseurl}/transaction/game/credit", [
                'account' => $this->username,
                'eventTime' => '2024-01-01 02:00:00',
                'gamehall' => 'motivation',
                'gamecode' => $this->game->game_type,
                'roundid' => 'non_existent_round',
                'amount' => 100,
                'mtcode' => $this->mtcode . '_credit',
            ]);

        $response->assertOk();
        $this->assertEquals('1014', $response->json('status.code'));
    }
}
