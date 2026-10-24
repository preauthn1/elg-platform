<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WudiSW;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Vendors\WudiSW\Datas\TransferInfoData;
use App\Vendors\WudiSW\WudiSWService;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;

class WudiSWStartBetTest extends WudiSWTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    #[Group('rules/vendor-wudisw#R-8')]
    public function test_bet()
    {
        // Arrange
        $betAmount = 100;
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $oldBalance = $walletBalance;
        $oldWalletBalance = $walletBalance;
        $newBalance = Common::numSub($oldBalance, $platformBetAmount, 4);
        $newWalletBalance = Common::numSub($oldWalletBalance, $platformBetAmount, 4);

        $dto = TransferInfoData::from([
            'amount' => Common::numMul($betAmount, -1, 4),
            'auth_code' => '',
            'betform_details' => '{}',
            'currency' => 'MYR',
            'game_code' => $this->gameCode,
            'game_type' => 'slot',
            'tran_id' => Str::uuid()->toString(),
            'trans_details' => json_encode([
                'round_id' => 'round123',
                'order_id' => 'order123',
                'trans_amount' => Common::numMul($betAmount, -1, 4),
                'request_time' => time(),
            ]),
            'transfer_type' => 'start',
            'username' => $this->username,
        ]);

        // Act
        $result = (new WudiSWService)->transferStart($dto);
        $this->wallet->refresh();

        // Assert
        $this->assertEquals(Common::numMul($newWalletBalance, $this->vendorInfo->rate, 4), Common::numFloor($result, 4));
        $this->assertEquals(Common::numFloor($newWalletBalance, 4), $this->wallet->balance);

        $this->assertDatabaseHas('wudisw_bet_records', [
            'bet_id' => 'order123',
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
            'point' => Common::numSub($oldBalance, $newBalance, 4),
            'before_balance' => $oldBalance,
            'balance' => $newBalance,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => TransactionStatusEnum::STATUS_GAMING,
        ]);
    }

    #[Group('rules/vendor-wudisw#R-8')]
    public function test_bet_with_rolling_chips()
    {
        // Arrange
        $this->enableRollingChip();

        $betAmount = 200;
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

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

        $newBalance = Common::numSub($oldBalance, $platformBetAmount, 4);
        $newWalletBalance = $oldWalletBalance;
        $newRollingChips = Common::numSub($oldRollingChips, $platformBetAmount, 4);
        if ($newRollingChips < 0) {
            $newRollingChips = 0;
            $remainAmount = Common::numSub($platformBetAmount, $oldRollingChips, 4);
            $newWalletBalance = Common::numSub($newWalletBalance, $remainAmount, 4);
        }

        $dto = TransferInfoData::from([
            'amount' => Common::numMul($betAmount, -1, 4),
            'auth_code' => '',
            'betform_details' => '{}',
            'currency' => 'MYR',
            'game_code' => $this->gameCode,
            'game_type' => 'slot',
            'tran_id' => Str::uuid()->toString(),
            'trans_details' => json_encode([
                'round_id' => 'round123',
                'order_id' => 'order123',
                'trans_amount' => Common::numMul($betAmount, -1, 4),
                'request_time' => time(),
            ]),
            'transfer_type' => 'start',
            'username' => $this->username,
        ]);

        // Act
        $result = (new WudiSWService)->transferStart($dto);
        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $this->assertEquals(Common::numMul($newBalance, $this->vendorInfo->rate, 4), Common::numFloor($result, 4));
        $this->assertEquals(Common::numFloor($newWalletBalance, 4), $this->wallet->balance);
        $this->assertEquals(Common::numFloor($newRollingChips, 4), Common::numFloor($this->rollingChipWallet->balance, 4));

        $this->assertDatabaseHas('wudisw_bet_records', [
            'bet_id' => 'order123',
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => '0',
            'bet' => $platformBetAmount,
            'valid_bet' => $platformBetAmount,
            'win_lose' => Common::numMul($platformBetAmount, -1, 4),
            'rolling_chips' => Common::numSub($oldRollingChips, $newRollingChips, 4),
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numSub($oldWalletBalance, $newWalletBalance, 4),
            'before_balance' => Common::numAdd($oldWalletBalance, 0, 4),
            'balance' => Common::numAdd($newWalletBalance, 0, 4),
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => TransactionStatusEnum::STATUS_GAMING,
        ]);

        $this->assertDatabaseHas('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'amount' => Common::numSub($oldRollingChips, $newRollingChips, 4),
            'before_balance' => $oldRollingChips,
            'balance' => $newRollingChips,
            'type' => RollingChipLogTypeEnum::BET_SETTLE,
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
        ]);
    }
}
