<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\UUSlotSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\UUSlotswBetRecord;
use App\Vendors\UUSlotSW\Enums\BetRecordTypeEnum;
use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class UUSlotSWGameResultTest extends UUSlotSWTestCase
{
    private $functionName = 'GameResult';

    #[Group('rules/vendor-uuslotsw#R-15')]
    public function test_post_game_result_ok()
    {
        // Arrange
        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $betAmount = 10;
        $payoutAmount = 100;
        $winLoseAmount = Common::numSub($payoutAmount, $betAmount);
        $betId = $this->simpleBet($betAmount);

        $postData = [
            'RoundType' => 0,
            'ResultType' => 0,
            'ResultId' => 20000016083,
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameType' => 'Slots',
            'GameCode' => $this->game->game_type,
            'GameName' => 'Simulator',
            'PlayerId' => $this->acctId,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'BetAmount' => $betAmount,
            'Payout' => $payoutAmount,
            'WinLose' => Common::numSub($payoutAmount, $betAmount),
            'TranDateTime' => '2024-08-20 12:24:38',
            'ValidBetAmount' => Common::numSub($betAmount, 5),
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:38.910+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:38',
            'Signature' => '14d337b23db2c7c473e6449096a8f0df',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $platformValidBetAmount = Common::numDiv($postData['ValidBetAmount'], $this->vendorInfo->rate, 4);
        $platformPayoutAmount = Common::numDiv($payoutAmount, $this->vendorInfo->rate, 4);
        $platformWinLoseAmount = Common::numDiv($winLoseAmount, $this->vendorInfo->rate, 4);

        // Bet first, calc again.
        $oldTotalBalance = Common::numSub($walletBalance, $platformBetAmount, 4);

        // Should use payout instead of win_lose, since we sub the bet amount first.
        $newTotalBalance = Common::numAdd($oldTotalBalance, $platformPayoutAmount, 4);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        $totalBetRecord = TotalBetRecord::first();
        $transactionLog = TransactionLog::first();
        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_OK,
                'Description' => 'OK',
                'OldBalance' => Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                'NewBalance' => Common::numMul($newTotalBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($newTotalBalance, $this->member->balance);
        $this->assertEquals(0, $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(1, TotalBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());

        $this->assertNotEmpty($betRecord);
        $this->assertEquals($betId, $betRecord->bet_id);
        $this->assertEquals($postData['ResultId'], $betRecord->reference_bet_id);
        $this->assertEquals($platformBetAmount, $betRecord->bet);
        $this->assertEquals($platformPayoutAmount, $betRecord->payout);
        $this->assertEquals($platformWinLoseAmount, $betRecord->win_lose);
        $this->assertEquals(BetRecordTypeEnum::RESULT, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);

        // Include bet.
        $this->assertNotEmpty($transactionLog);
        // 單錢包投注 point 需為 bet: 正數, payout: 負數
        $this->assertEquals(Common::numMul($platformWinLoseAmount, -1, 4), $transactionLog->point);
        $this->assertEquals($walletBalance, $transactionLog->before_balance);
        $this->assertEquals(Common::numAdd($walletBalance, $platformWinLoseAmount, 4), $transactionLog->balance);

        $this->assertNotEmpty($totalBetRecord);
        $this->assertEquals($betRecord->reference_bet_id, $totalBetRecord->reference_no);
        $this->assertEquals($platformBetAmount, $totalBetRecord->bet);
        $this->assertEquals($platformValidBetAmount, $totalBetRecord->valid_bet);
        $this->assertEquals($platformWinLoseAmount, $totalBetRecord->win_lose);
        $this->assertEquals(0, $totalBetRecord->jackpot);
        $this->assertEquals(0, $totalBetRecord->bonus);
        $this->assertEquals($betRecord->rolling_chips, $totalBetRecord->rolling_chip);
        $this->assertEquals($betRecord->bet_time, $totalBetRecord->bet_time);
        $this->assertEquals($betRecord->payout_time, $totalBetRecord->payout_time);
    }

    #[Group('rules/vendor-uuslotsw#R-16')]
    public function test_post_game_result_failed_duplicate_bet_case()
    {
        // Arrange
        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $betAmount = 10;
        $payoutAmount = 100;
        $winLoseAmount = Common::numSub($payoutAmount, $betAmount);
        $betId = $this->simpleBet($betAmount);

        $postData = [
            'RoundType' => 0,
            'ResultType' => 0,
            'ResultId' => 20000016083,
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameType' => 'Slots',
            'GameCode' => $this->game->game_type,
            'GameName' => 'Simulator',
            'PlayerId' => $this->acctId,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'BetAmount' => $betAmount,
            'Payout' => $payoutAmount,
            'WinLose' => Common::numSub($payoutAmount, $betAmount),
            'TranDateTime' => '2024-08-20 12:24:38',
            'ValidBetAmount' => Common::numSub($betAmount, 5),
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:38.910+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:38',
            'Signature' => '14d337b23db2c7c473e6449096a8f0df',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        // Act
        $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                'Description' => 'Result transaction id 20000016083 already processed',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    #[Group('rules/vendor-uuslotsw#R-17')]
    public function test_post_game_result_failed_no_bet_transaction()
    {
        // Arrange
        $postData = [
            'RoundType' => 0,
            'ResultType' => 0,
            'ResultId' => 20000016085,
            'BetId' => 20000016084,
            'RoundId' => 20000016084,
            'GameType' => 'Slots',
            'GameCode' => $this->game->game_type,
            'GameName' => 'Simulator',
            'PlayerId' => '82_UUSSW00000007',
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'BetAmount' => 0.12,
            'Payout' => 0.12,
            'WinLose' => 0,
            'TranDateTime' => '2024-08-20 12:24:39',
            'ValidBetAmount' => 0.12,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:39.615+08:00',
            'OperatorId' => 'uustpowerMYR',
            'RequestDateTime' => '2024-08-20 12:24:39',
            'Signature' => 'b2223f8d3f5ab2ca29e5d6b06ba813c0',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_BET_TRANSACTION_NOT_FOUND,
                'Description' => 'Transaction id 20000016084 not found',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    #[Group('rules/vendor-uuslotsw#R-6')]
    public function test_post_game_result_failed_invalid_signature()
    {
        // Arrange
        $postData = [
            'RoundType' => 0,
            'ResultType' => 0,
            'ResultId' => 20000016085,
            'BetId' => 20000016084,
            'RoundId' => 20000016084,
            'GameType' => 'Slots',
            'GameCode' => $this->game->game_type,
            'GameName' => 'Simulator',
            'PlayerId' => '82_UUSSW00000007',
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'BetAmount' => 0.12,
            'Payout' => 0.12,
            'WinLose' => 0,
            'TranDateTime' => '2024-08-20 12:24:39',
            'ValidBetAmount' => 0.12,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:39.615+08:00',
            'OperatorId' => 'uustpowerMYR',
            'RequestDateTime' => '2024-08-20 12:24:39',
            'Signature' => 'b2223f8d3f5ab2ca29e5d6b06ba813c0',
        ];

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_INVALID_SIGNATURE,
                'Description' => 'Invalid Signature',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    private function simpleBet(float $amount): int
    {
        $id = 20000016077;
        $postData = [
            'BetId' => $id,
            'RoundId' => $id,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $amount,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:37',
            'AuthToken' => '',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:37.074+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:37',
            'Signature' => '738e53143a58c3044b977ed0d26cd2fb',
        ];

        $this->regenerateSignature('Bet', $postData);
        $this->postJson("{$this->baseurl}/Bet", $postData);

        return $id;
    }
}
