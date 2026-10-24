<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\UUSlotSW;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Models\RollingChipLog;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\UUSlotswBetRecord;
use App\Vendors\UUSlotSW\Enums\BetRecordTypeEnum;
use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use PHPUnit\Framework\Attributes\Group;

class UUSlotSWRollbackTest extends UUSlotSWTestCase
{
    private $functionName = 'Rollback';

    #[Group('rules/vendor-uuslotsw#R-19')]
    public function test_post_rollback_ok_wallet_only()
    {
        // Arrange
        $walletBalance = 5000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $betAmount = 10;
        $betId = $this->simpleBet($betAmount);

        $postData = [
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:50',
            'RollbackType' => 1,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:40.602+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:50',
            'Signature' => '15cda783e1ee484fc11f76c677a4ad13',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $walletCost = $platformBetAmount;

        // Bet first, calc again.
        $oldTotalBalance = Common::numSub($walletBalance, $platformBetAmount, 4);

        // refund the bet amount.
        $newTotalBalance = Common::numAdd($oldTotalBalance, $platformBetAmount, 4);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        // 2026-05-19 起：rollback 走 27 SETTLE 延用，bet+rollback 為同一筆 log
        $transactionLog = TransactionLog::where('type', TransactionEnum::SEAMLESS_WALLET_BET_SETTLE)->first();
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

        $this->assertEquals($walletBalance, $this->member->balance);
        $this->assertEquals(0, $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
        // bet 與 rollback 延用同一筆 27 log
        $this->assertEquals(1, TransactionLog::count());

        $this->assertNotEmpty($betRecord);
        $this->assertEquals($betId, $betRecord->bet_id);
        $this->assertEquals($platformBetAmount, $betRecord->bet);
        $this->assertEquals(0, $betRecord->payout);
        $this->assertEquals(Common::numMul($platformBetAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals(BetRecordTypeEnum::ROLLBACK, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);
        $this->assertNull($betRecord->reference_bet_id);

        // 延用後的 log：point 為 bet (-walletCost) 與 rollback (+walletCost) 的累計 = 0
        // before_balance 留 placeBet 寫入時的初始值；balance 被 rollback 覆寫為退款後
        $this->assertNotEmpty($transactionLog);
        $this->assertEquals(0, $transactionLog->point);
        $this->assertEquals($walletBalance, $transactionLog->before_balance);
        $this->assertEquals($newTotalBalance, $transactionLog->balance);
    }

    #[Group('rules/vendor-uuslotsw#R-19')]
    public function test_post_rollback_ok_wallet_with_rolling_chip()
    {
        // Arrange
        $walletBalance = 5000;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 10;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $betAmount = 100;
        $betId = $this->simpleBet($betAmount);

        $postData = [
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:50',
            'RollbackType' => 1,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:40.602+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:50',
            'Signature' => '15cda783e1ee484fc11f76c677a4ad13',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $rollingChipsCost = $rollingChips;
        $walletCost = Common::numSub($platformBetAmount, $rollingChipsCost, 4);

        // Bet first, calc again.
        $oldTotalBalance = Common::numSub(Common::numAdd($walletBalance, $rollingChips), $platformBetAmount, 4);

        // refund the bet amount.
        $newTotalBalance = Common::numAdd($oldTotalBalance, $platformBetAmount, 4);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        // 2026-05-19 起：rollback 走 27 SETTLE 延用，bet+rollback 為同一筆 log
        $transactionLog = TransactionLog::where('type', TransactionEnum::SEAMLESS_WALLET_BET_SETTLE)->first();
        $rollingChipLog_Bet = RollingChipLog::where('type', RollingChipLogTypeEnum::BET_SETTLE)->first();
        $rollingChipLog_Cancel = RollingChipLog::where('type', RollingChipLogTypeEnum::BET_CANCEL)->first();
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

        $this->assertEquals($walletBalance, $this->member->balance);
        $this->assertEquals($rollingChips, $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
        // bet 與 rollback 延用同一筆 27 log
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(2, RollingChipLog::count());

        $this->assertNotEmpty($betRecord);
        $this->assertEquals($betId, $betRecord->bet_id);
        $this->assertEquals($platformBetAmount, $betRecord->bet);
        $this->assertEquals(0, $betRecord->payout);
        $this->assertEquals(Common::numMul($platformBetAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals(BetRecordTypeEnum::ROLLBACK, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);
        $this->assertNull($betRecord->reference_bet_id);

        // 延用後的 log：point 為 bet (-walletCost) 與 rollback (+walletCost) 的累計 = 0
        // before_balance 留 placeBet 寫入時的初始值；balance 被 rollback 覆寫為退款後
        $this->assertNotEmpty($transactionLog);
        $this->assertEquals(0, $transactionLog->point);
        $this->assertEquals($walletBalance, $transactionLog->before_balance);
        $this->assertEquals($walletBalance, $transactionLog->balance);

        $this->assertNotEmpty($rollingChipLog_Bet);
        $this->assertEquals($rollingChipsCost, $rollingChipLog_Bet->amount);
        $this->assertEquals($rollingChips, $rollingChipLog_Bet->before_balance);
        $this->assertEquals(0, $rollingChipLog_Bet->balance);

        $this->assertNotEmpty($transactionLog);
        $this->assertEquals($rollingChipsCost, $rollingChipLog_Cancel->amount);
        $this->assertEquals(0, $rollingChipLog_Cancel->before_balance);
        $this->assertEquals($rollingChips, $rollingChipLog_Cancel->balance);
    }

    #[Group('rules/vendor-uuslotsw#R-20')]
    public function test_post_rollback_failed_duplicate_bet_case()
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
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:50',
            'RollbackType' => 1,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:40.602+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:50',
            'Signature' => '15cda783e1ee484fc11f76c677a4ad13',
        ];
        $this->regenerateSignature($this->functionName, $postData);

        // Act
        $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                'Description' => "Transaction id {$betId} already rollback",
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    #[Group('rules/vendor-uuslotsw#R-20')]
    public function test_post_rollback_failed_no_bet_transaction()
    {
        // Arrange
        $postData = [
            'BetId' => 20000016084,
            'RoundId' => 20000016084,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => 0.12,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:50',
            'RollbackType' => 1,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:40.602+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:50',
            'Signature' => '15cda783e1ee484fc11f76c677a4ad13',
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
    public function test_post_rollback_failed_invalid_signature()
    {
        // Arrange
        $postData = [
            'BetId' => 20000016084,
            'RoundId' => 20000016084,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => 0.12,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:50',
            'RollbackType' => 1,
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:40.602+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:50',
            'Signature' => 'Invalid Signature',
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
