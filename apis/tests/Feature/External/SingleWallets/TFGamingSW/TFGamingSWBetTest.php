<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TFGamingSW;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;
use App\Vendors\TFGamingSW\Enums\ErrorCodeEnum;
use App\Vendors\TFGamingSW\TFGamingSWService;
use Exception;

class TFGamingSWBetTest extends TFGamingSWTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_bet()
    {
        // Arrange
        $betAmount = 100;
        $betId = '123';
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $oldBalance = $walletBalance;
        $oldWalletBalance = $walletBalance;
        $newBalance = Common::numSub($oldBalance, $platformBetAmount, 4);
        $newWalletBalance = Common::numSub($oldWalletBalance, $platformBetAmount, 4);

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => -$betAmount,
            'placeBet' => true,
            'ticketNum' => $betId,
            'description' => 'test',
            'ticketDetail' => [
                'id' => $betId,
                'date_created' => date('Y-m-d H:i:s'),
                'settlement_datetime' => null,
            ],
        ]);

        // Act
        $result = TFGamingSWService::bet($dto);
        $this->wallet->refresh();

        // Assert
        $this->assertEquals(Common::numMul($newWalletBalance, $this->vendorInfo->rate, 4), $result);
        $this->assertEquals($newWalletBalance, $this->wallet->balance);

        $this->assertDatabaseHas('tfgamingsw_bet_records', [
            'bet_id' => $betId,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => '0',
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1, 4),
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

    public function test_bet_with_rolling_chips()
    {
        // Arrange
        $this->enableRollingChip();

        $betAmount = 200;
        $betId = '123';
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 90;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $oldBalance = Common::numAdd($walletBalance, $rollingChips, 4);
        $oldWalletBalance = $walletBalance;
        $oldRollingChips = $rollingChips;

        $newBalance = Common::numSub($oldBalance, $platformBetAmount, 4);
        $newWalletBalance = $oldWalletBalance;
        $newRollingChips = Common::numSub($oldRollingChips, $platformBetAmount, 4);
        if ($newRollingChips < 0) {
            $newRollingChips = 0;
            $remainAmount = Common::numSub($platformBetAmount, $oldRollingChips, 4);
            $newWalletBalance = Common::numSub($newWalletBalance, $remainAmount, 4);
        }

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => -$betAmount,
            'placeBet' => true,
            'ticketNum' => $betId,
            'description' => 'test',
            'ticketDetail' => [
                'id' => $betId,
                'date_created' => date('Y-m-d H:i:s'),
                'settlement_datetime' => null,
            ],
        ]);

        // Act
        $result = TFGamingSWService::bet($dto);
        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $this->assertEquals(Common::numMul($newBalance, $this->vendorInfo->rate, 4), $result);
        $this->assertEquals($newWalletBalance, $this->wallet->balance);
        $this->assertEquals($newRollingChips, $this->rollingChipWallet->balance);

        $this->assertDatabaseHas('tfgamingsw_bet_records', [
            'bet_id' => $betId,
            'reference_bet_id' => null,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => '0',
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1, 4),
            'rolling_chips' => Common::numSub($oldRollingChips, $newRollingChips),
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numSub($oldWalletBalance, $newWalletBalance, 4),
            'before_balance' => $oldWalletBalance,
            'balance' => $newWalletBalance,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);

        $this->assertDatabaseHas('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'amount' => Common::numSub($oldRollingChips, $newRollingChips, 4),
            'before_balance' => $oldRollingChips,
            'balance' => $newRollingChips,
            'type' => RollingChipLogTypeEnum::BET_SETTLE,
            'status' => 1000,
        ]);
    }

    public function test_bet_failed_duplicate()
    {
        // Arrange
        $betAmount = 100;
        $betId = '123';
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $oldBalance = $walletBalance;
        $oldWalletBalance = $walletBalance;
        $newBalance = Common::numSub($oldBalance, $platformBetAmount, 4);
        $newWalletBalance = Common::numSub($oldWalletBalance, $platformBetAmount, 4);

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => -$betAmount,
            'placeBet' => true,
            'ticketNum' => '123',
            'description' => 'test',
            'ticketDetail' => [
                'id' => '123',
                'date_created' => date('Y-m-d H:i:s'),
                'settlement_datetime' => null,
            ],
        ]);

        // Act
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bet record duplicated');
        $this->expectExceptionCode(ErrorCodeEnum::UNDEFINED->value);

        $result = TFGamingSWService::bet($dto);
        $this->wallet->refresh();

        TFGamingSWService::bet($dto);

        // Assert
    }
}
