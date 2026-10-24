<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TFGamingSW;

use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Vendors\Models\TfgamingswBetRecord;
use App\Vendors\TFGamingSW\Datas\TransferInfoData;
use App\Vendors\TFGamingSW\Enums\BetRecordStatusEnum;
use App\Vendors\TFGamingSW\Enums\ErrorCodeEnum;
use App\Vendors\TFGamingSW\TFGamingSWService;
use Exception;

class TFGamingSWRollbackTest extends TFGamingSWTestCase
{
    private $bet_betId = 'bet_round123';

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_rollback()
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
        $betRecord = TfgamingswBetRecord::where('bet_id', $this->bet_betId)->first();
        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => $betAmount,
            'placeBet' => true,
            'ticketNum' => $this->bet_betId . '_20250101',
            'description' => 'test',
            'ticketDetail' => [
                'id' => $this->bet_betId,
                'date_created' => date('Y-m-d H:i:s'),
                'settlement_datetime' => null,
            ],
        ]);

        // Act
        $result = TFGamingSWService::rollback($dto);
        $this->wallet->refresh();

        // Assert
        $this->assertEquals(Common::numMul($oldBalance, $this->vendorInfo->rate, 4), $result);
        $this->assertEquals($oldBalance, $this->wallet->balance);

        $this->assertDatabaseHas('tfgamingsw_bet_records', [
            'bet_id' => $this->bet_betId,
            'reference_bet_id' => null,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => BetRecordStatusEnum::CANCEL,
            'win_lose' => 0,
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'agent_id' => $this->member->parent,
            'vendor_id' => $this->vendor->id,
            'point' => 0,
            'before_balance' => $oldBalance,
            'balance' => $oldBalance,
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

        $dto = new TransferInfoData([
            'loginName' => $this->username,
            'amount' => $betAmount,
            'placeBet' => true,
            'ticketNum' => $this->bet_betId . '_20250101',
            'description' => 'test',
            'ticketDetail' => [
                'id' => $this->bet_betId,
                'date_created' => date('Y-m-d H:i:s'),
                'settlement_datetime' => null,
            ],
        ]);

        // Act
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("Ticket id {$this->bet_betId} already has result");
        $this->expectExceptionCode(ErrorCodeEnum::UNDEFINED->value);

        TFGamingSWService::rollback($dto);
        TFGamingSWService::rollback($dto);
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
                'date_created' => date('Y-m-d H:i:s'),
                'settlement_datetime' => null,
            ],
        ]);

        TFGamingSWService::bet($dto);
    }
}
