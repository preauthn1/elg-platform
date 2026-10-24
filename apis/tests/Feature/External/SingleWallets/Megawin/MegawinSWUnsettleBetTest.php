<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Megawin;

use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Vendors\MegawinSW\Enums\BetStatusEnum;
use App\Vendors\MegawinSW\Enums\ErrorCodeEnum;
use App\Vendors\Models\MegawinswBetRecord;

class MegawinSWUnsettleBetTest extends MegawinSWTestCase
{
    private $wagerId = 'wager123';

    /**
     * 驗證 UnsettleBet endpoint（取消注單）：
     * 取消後，會員餘額應退回原注額，BetRecord 狀態改為 CANCEL，
     * win_lose 歸零，並產生對應的 transaction_logs。
     */
    public function test_unsettle_bet()
    {
        // Arrange
        $betAmount = 100;
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate);
        MegawinswBetRecord::create([
            'bet_id' => $this->wagerId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'status' => BetStatusEnum::NO_RESULT,
            'bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1),
            'bet_time' => '2023-10-01 04:00:00',
            'payout_time' => '2023-10-01 04:00:00',
        ]);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $oldBalance = $walletBalance;
        $newBalance = Common::numAdd($oldBalance, $platformBetAmount);

        $expectedBeginGold = Common::numMul($oldBalance, $this->vendorInfo->rate);
        $expectedEndGold = Common::numMul($newBalance, $this->vendorInfo->rate);

        // Act
        $response = $this->postJson("{$this->baseurl}/UnsettleBet", [
            'wagerData' => [
                [
                    'gameId' => $this->game->game_type,
                    'gameClass' => '0',
                    'risk' => '1',
                    'gameRoundId' => 'round123',
                    'wagerId' => $this->wagerId,
                    'gold' => (string) $betAmount,
                    'vgold' => '0',
                    'winGold' => '0',
                    'unusedGold' => (string) $betAmount,
                    'result' => 'D',
                    'orderDate' => '2023-10-01',
                    'addDate' => '2023-10-01 04:00:00',
                    'resultDate' => '2023-10-01 05:00:00',
                    'freegame' => 'N',
                    'utoken' => $this->user_token,
                    'uid' => $this->username,
                    'ip' => '0.0.0.0',
                    'device' => 'PC',
                ],
            ],
        ]);

        $this->wallet->refresh();

        // Assert
        $response->assertOk();
        $this->assertEquals(ErrorCodeEnum::SUCCESS, $response->json('wagerDataResp.0.ret'));
        $this->assertEquals(0, Common::numCompare($newBalance, $this->wallet->balance));
        $this->assertEquals(0, Common::numCompare($expectedBeginGold, $response->json('wagerDataResp.0.beginGold')));
        $this->assertEquals(0, Common::numCompare($expectedEndGold, $response->json('wagerDataResp.0.endGold')));

        $this->assertDatabaseHas('megawinsw_bet_records', [
            'bet_id' => $this->wagerId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'bet' => $platformBetAmount,
            'win_lose' => 0,
            'status' => BetStatusEnum::CANCEL,
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numSub($oldBalance, $newBalance),
            'before_balance' => $oldBalance,
            'balance' => $newBalance,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);
    }
}
