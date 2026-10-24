<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Megawin;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Vendors\MegawinSW\Enums\ErrorCodeEnum;

class MegawinSWPlaceBetTest extends MegawinSWTestCase
{
    private $wagerId = 'wager123';

    /**
     * 驗證 PlaceBet endpoint（無 rolling chip）：
     * 下注後，會員主錢包餘額應扣除平台注額，
     * 並產生對應的 megawinsw_bet_records 與 transaction_logs。
     */
    public function test_place_bet()
    {
        // Arrange
        $betAmount = 100;
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $oldBalance = $walletBalance;
        $newBalance = Common::numSub($oldBalance, $platformBetAmount);

        $expectedBeginGold = Common::numMul($oldBalance, $this->vendorInfo->rate);
        $expectedEndGold = Common::numMul($newBalance, $this->vendorInfo->rate);

        // Act
        $response = $this->postJson("{$this->baseurl}/PlaceBet", [
            'wagerData' => [
                [
                    'gameId' => $this->game->game_type,
                    'gameClass' => '0',
                    'risk' => '1',
                    'gameRoundId' => 'round123',
                    'wagerId' => $this->wagerId,
                    'gold' => (string) $betAmount,
                    'result' => '0',
                    'orderDate' => '2023-10-01',
                    'addDate' => '2023-10-01 04:00:00',
                    'resultDate' => '0000-00-00 00:00:00',
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
            'status' => '0',
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numSub($oldBalance, $newBalance, 4),
            'before_balance' => Common::numAdd($oldBalance, 0, 4),
            'balance' => Common::numAdd($newBalance, 0, 4),
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);
    }

    /**
     * 驗證 PlaceBet endpoint（含 rolling chip）：
     * 下注時應優先扣除 rolling chip 餘額，不足部分再扣主錢包，
     * 並分別產生 transaction_logs 與 rolling_chip_logs。
     */
    public function test_place_bet_with_rolling_chips()
    {
        // Arrange
        $betAmount = 200;
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 90;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $oldBalance = Common::numAdd($walletBalance, $rollingChips);
        $oldWalletBalance = $walletBalance;
        $oldRollingChips = $rollingChips;

        $newBalance = Common::numSub($oldBalance, $platformBetAmount);
        $newRollingChips = Common::numSub($oldRollingChips, $platformBetAmount);
        if ($newRollingChips < 0) {
            $newRollingChips = 0;
            $platformBetAmount = Common::numSub($platformBetAmount, $oldRollingChips);
        }
        $newWalletBalance = Common::numSub($oldWalletBalance, $platformBetAmount);

        $expectedBeginGold = Common::numMul($oldBalance, $this->vendorInfo->rate);
        $expectedEndGold = Common::numMul($newBalance, $this->vendorInfo->rate);

        // Act
        $response = $this->postJson("{$this->baseurl}/PlaceBet", [
            'wagerData' => [
                [
                    'gameId' => $this->game->game_type,
                    'gameClass' => '0',
                    'risk' => '1',
                    'gameRoundId' => 'round123',
                    'wagerId' => $this->wagerId,
                    'gold' => (string) $betAmount,
                    'result' => '0',
                    'orderDate' => '2023-10-01',
                    'addDate' => '2023-10-01 04:00:00',
                    'resultDate' => '0000-00-00 00:00:00',
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
        $this->assertEquals(0, Common::numCompare($newWalletBalance, $this->wallet->balance));
        $this->assertEquals(0, Common::numCompare($expectedBeginGold, $response->json('wagerDataResp.0.beginGold')));
        $this->assertEquals(0, Common::numCompare($expectedEndGold, $response->json('wagerDataResp.0.endGold')));

        $this->assertDatabaseHas('megawinsw_bet_records', [
            'bet_id' => $this->wagerId,
            'username' => $this->username,
            'game_code' => $this->game->game_type,
            'status' => '0',
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numSub($oldWalletBalance, $newWalletBalance, 4),
            'before_balance' => Common::numAdd($oldWalletBalance, 0, 4),
            'balance' => Common::numAdd($newWalletBalance, 0, 4),
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);

        $this->assertDatabaseHas('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'amount' => Common::numSub($oldRollingChips, $newRollingChips, 4),
            'before_balance' => Common::numAdd($oldRollingChips, 0, 4),
            'balance' => Common::numAdd($newRollingChips, 0, 4),
            'type' => RollingChipLogTypeEnum::BET_SETTLE,
            'status' => 1000,
        ]);
    }
}
