<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TFGamingSW;

use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;
use App\Vendors\TFGamingSW\Enums\BetRecordStatusEnum;
use App\Vendors\TFGamingSW\Enums\ErrorCodeEnum;
use App\Vendors\TFGamingSW\TFGamingSWService;
use Exception;

class TFGamingSWSettleTest extends TFGamingSWTestCase
{
    private $bet_betId = 'bet_round123';

    private $settle_betId = 'bet_round123_20250101';

    private $bet_datetime;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bet_datetime = date('Y-m-d H:i:s');
    }

    public function test_settle()
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);
        $oldBalance = $walletBalance;
        $oldWalletBalance = $walletBalance;

        $betAmount = 100;
        $this->bet($betAmount);
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        $winAmount = 200;
        $platformWinAmount = Common::numDiv($winAmount, $this->vendorInfo->rate, 4);

        $newBalance = Common::numAdd(Common::numSub($oldBalance, $platformBetAmount), $platformWinAmount, 4);
        $newWalletBalance = Common::numAdd(Common::numSub($oldWalletBalance, $platformBetAmount), $platformWinAmount, 4);

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => $winAmount,
            'placeBet' => false,
            'ticketNum' => $this->settle_betId,
            'description' => 'test',
            'ticketDetail' => [
                'id' => $this->bet_betId,
                'date_created' => $this->bet_datetime,
                'settlement_datetime' => date('Y-m-d H:i:s'),
            ],
        ]);

        // Act
        $result = TFGamingSWService::settle($dto);
        $this->wallet->refresh();

        // Assert
        $this->assertEquals(Common::numMul($newWalletBalance, $this->vendorInfo->rate, 4), $result);
        $this->assertEquals($newWalletBalance, $this->wallet->balance);

        $this->assertDatabaseHas('tfgamingsw_bet_records', [
            'bet_id' => $this->settle_betId,
            'reference_bet_id' => $this->bet_betId,
            'username' => $this->username,
            'game_code' => 'TP01',
            'status' => BetRecordStatusEnum::IS_SETTLEMENT,
            'win_lose' => $platformWinAmount,
            'bet_time' => $dto->datetime,
            'payout_time' => $dto->settleTime,
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => Common::numMul(Common::numSub($newBalance, $oldBalance), -1, 4),
            'before_balance' => $oldBalance,
            'balance' => $newBalance,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);
    }

    public function test_settle_failed_duplicate()
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $betAmount = 100;
        $this->bet($betAmount);

        $winAmount = 200;

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => $winAmount,
            'placeBet' => false,
            'ticketNum' => $this->settle_betId,
            'description' => 'test',
            'ticketDetail' => [
                'id' => $this->bet_betId,
                'date_created' => $this->bet_datetime,
                'settlement_datetime' => date('Y-m-d H:i:s'),
            ],
        ]);

        // Act
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Bet record duplicated');
        $this->expectExceptionCode(ErrorCodeEnum::UNDEFINED->value);

        TFGamingSWService::settle($dto);
        TFGamingSWService::settle($dto);
        // Assert
    }

    public function test_settle_failed_invalid_amount()
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $betAmount = 100;
        $this->bet($betAmount);

        $winAmount = -200;

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => $winAmount,
            'placeBet' => false,
            'ticketNum' => $this->settle_betId,
            'description' => 'test',
            'ticketDetail' => [
                'id' => $this->bet_betId,
                'date_created' => $this->bet_datetime,
                'settlement_datetime' => date('Y-m-d H:i:s'),
            ],
        ]);

        // Act
        $this->expectException(Exception::class);
        $this->expectExceptionCode(ErrorCodeEnum::INVALID_AMOUNT->value);

        TFGamingSWService::settle($dto);
        // Assert
    }

    private function bet(float $betAmount)
    {
        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => -$betAmount,
            'placeBet' => true,
            'ticketNum' => $this->bet_betId,
            'description' => 'test',
            'ticketDetail' => [
                'id' => $this->bet_betId,
                'date_created' => $this->bet_datetime,
                'settlement_datetime' => null,
            ],
        ]);

        TFGamingSWService::bet($dto);
    }
}
