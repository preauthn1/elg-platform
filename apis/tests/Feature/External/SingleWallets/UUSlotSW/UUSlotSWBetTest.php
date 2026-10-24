<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\UUSlotSW;

use App\Enums\RollingChipLogTypeEnum;
use App\Helpers\Common;
use App\Models\RollingChipLog;
use App\Models\TotalBetRecord;
use App\Models\TransactionLog;
use App\Vendors\Models\UUSlotswBetRecord;
use App\Vendors\UUSlotSW\Enums\BetRecordTypeEnum;
use App\Vendors\UUSlotSW\Enums\StatusCodeEnum;
use PHPUnit\Framework\Attributes\Group;

/**
 * UUSlotSW 下注測試
 */
class UUSlotSWBetTest extends UUSlotSWTestCase
{
    private $functionName = 'Bet';

    /**
     * 測試下注成功 (僅錢包)
     *
     * @return void
     */
    #[Group('rules/vendor-uuslotsw#R-10')]
    public function test_post_bet_ok_wallet_only()
    {
        // Arrange
        $betAmount = 0.12;
        $betId = 20000016077;
        $postData = [
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
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
        $this->regenerateSignature($this->functionName, $postData);

        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        $transactionLog = TransactionLog::first();
        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_OK,
                'Description' => 'OK',
                'OldBalance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
                'NewBalance' => Common::numMul(Common::numSub($walletBalance, $platformBetAmount), $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals(Common::numSub($walletBalance, $platformBetAmount, 4), $this->member->balance);
        $this->assertEquals(0, $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());

        $this->assertNotEmpty($betRecord);
        $this->assertEquals($postData['BetId'], $betRecord->bet_id);
        $this->assertNull($betRecord->reference_bet_id);
        $this->assertEquals($platformBetAmount, $betRecord->bet);
        $this->assertEquals(0, $betRecord->payout);
        $this->assertEquals(Common::numMul($platformBetAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals(0, $betRecord->rolling_chips);
        $this->assertEquals(BetRecordTypeEnum::BET, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->bet_time);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);

        $this->assertNotEmpty($transactionLog);
        // 單錢包投注 point 需為 bet: 正數, payout: 負數
        $this->assertEquals($platformBetAmount, $transactionLog->point);
        $this->assertEquals($walletBalance, $transactionLog->before_balance);
        $this->assertEquals(Common::numSub($walletBalance, $platformBetAmount, 4), $transactionLog->balance);
    }

    /**
     * 測試下注成功 (僅錢包, 無洗碼量錢包)
     *
     * @return void
     */
    #[Group('rules/vendor-uuslotsw#R-10')]
    public function test_post_bet_ok_wallet_only_no_rolling_chip_wallet()
    {
        // Arrange
        $betAmount = 0.12;
        $betId = 20000016077;
        $postData = [
            'BetId' => $betId,
            'RoundId' => $betId,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
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
        $this->regenerateSignature($this->functionName, $postData);
        $this->rollingChipWallet->delete();

        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        $transactionLog = TransactionLog::first();
        $this->member->refresh();
        // $this->rollingChipWallet->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_OK,
                'Description' => 'OK',
                'OldBalance' => Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
                'NewBalance' => Common::numMul(Common::numSub($walletBalance, $platformBetAmount, 4), $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals(Common::numSub($walletBalance, $platformBetAmount, 4), $this->member->balance);
        // $this->assertEquals(0, $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());

        $this->assertNotEmpty($betRecord);
        $this->assertEquals($postData['BetId'], $betRecord->bet_id);
        $this->assertNull($betRecord->reference_bet_id);
        $this->assertEquals($platformBetAmount, $betRecord->bet);
        $this->assertEquals(0, $betRecord->payout);
        $this->assertEquals(Common::numMul($platformBetAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals(0, $betRecord->rolling_chips);
        $this->assertEquals(BetRecordTypeEnum::BET, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->bet_time);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);

        $this->assertNotEmpty($transactionLog);
        // 單錢包投注 point 需為 bet: 正數, payout: 負數
        $this->assertEquals($platformBetAmount, $transactionLog->point);
        $this->assertEquals($walletBalance, $transactionLog->before_balance);
        $this->assertEquals(Common::numSub($walletBalance, $platformBetAmount, 4), $transactionLog->balance);
    }

    /**
     * 測試下注成功 (僅洗碼量)
     *
     * @return void
     */
    #[Group('rules/vendor-uuslotsw#R-10')]
    public function test_post_bet_ok_rolling_chips_only()
    {
        // Arrange
        $betAmount = 100;
        $postData = [
            'BetId' => 20000016077,
            'RoundId' => 20000016077,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
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
        $this->regenerateSignature($this->functionName, $postData);

        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 1000;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $oldTotalBalance = Common::numAdd($walletBalance, $rollingChips);

        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $rollingChipsCost = $platformBetAmount;

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        $rollingChipLog = RollingChipLog::first();
        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_OK,
                'Description' => 'OK',
                'OldBalance' => Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                'NewBalance' => Common::numMul(Common::numSub($oldTotalBalance, $platformBetAmount, 4), $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($walletBalance, $this->member->balance);
        $this->assertEquals(Common::numSub($rollingChips, $rollingChipsCost), $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
        $this->assertEquals(0, TransactionLog::count());
        $this->assertEquals(1, RollingChipLog::count());

        $this->assertNotEmpty($betRecord);
        $this->assertEquals($postData['BetId'], $betRecord->bet_id);
        $this->assertNull($betRecord->reference_bet_id);
        $this->assertEquals($platformBetAmount, $betRecord->bet);
        $this->assertEquals(0, $betRecord->payout);
        $this->assertEquals(Common::numMul($platformBetAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals($rollingChipsCost, $betRecord->rolling_chips);
        $this->assertEquals(BetRecordTypeEnum::BET, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->bet_time);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);

        $this->assertNotEmpty($rollingChipLog);
        $this->assertEquals(RollingChipLogTypeEnum::BET_SETTLE, $rollingChipLog->type);
        $this->assertEquals($rollingChipsCost, $rollingChipLog->amount);
        $this->assertEquals($rollingChips, $rollingChipLog->before_balance);
        $this->assertEquals(Common::numSub($rollingChips, $rollingChipsCost, 4), $rollingChipLog->balance);
    }

    /**
     * 測試下注成功 (錢包與洗碼量)
     *
     * @return void
     */
    public function test_post_bet_ok_wallet_and_rolling_chips()
    {
        // Arrange
        $betAmount = 100;
        $postData = [
            'BetId' => 20000016077,
            'RoundId' => 20000016077,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
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
        $this->regenerateSignature($this->functionName, $postData);

        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = 10;
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $oldTotalBalance = Common::numAdd($walletBalance, $rollingChips);

        $platformBetAmount = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $rollingChipsCost = $rollingChips;
        $walletCost = Common::numSub($platformBetAmount, $rollingChipsCost, 4);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $betRecord = UUSlotswBetRecord::first();
        $transactionLog = TransactionLog::first();
        $rollingChipLog = RollingChipLog::first();
        $this->member->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_OK,
                'Description' => 'OK',
                'OldBalance' => Common::numMul($oldTotalBalance, $this->vendorInfo->rate, 4),
                'NewBalance' => Common::numMul(Common::numSub($oldTotalBalance, $platformBetAmount, 4), $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals(Common::numSub($walletBalance, $walletCost, 4), $this->member->balance);
        $this->assertEquals(0, $this->rollingChipWallet->balance);

        $this->assertEquals(1, UUSlotswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
        $this->assertEquals(1, TransactionLog::count());
        $this->assertEquals(1, RollingChipLog::count());

        $this->assertEquals($postData['BetId'], $betRecord->bet_id);
        $this->assertNull($betRecord->reference_bet_id);
        $this->assertEquals($platformBetAmount, $betRecord->bet);
        $this->assertEquals(0, $betRecord->payout);
        $this->assertEquals(Common::numMul($platformBetAmount, -1, 4), $betRecord->win_lose);
        $this->assertEquals($rollingChipsCost, $betRecord->rolling_chips);
        $this->assertEquals(BetRecordTypeEnum::BET, $betRecord->status);
        $this->assertEquals($postData['TranDateTime'], $betRecord->bet_time);
        $this->assertEquals($postData['TranDateTime'], $betRecord->payout_time);

        $this->assertNotEmpty($transactionLog);
        $this->assertEquals($walletCost, $transactionLog->point); // 單錢包投注 point 需為 bet: 正數, payout: 負數
        $this->assertEquals($walletBalance, $transactionLog->before_balance);
        $this->assertEquals(Common::numSub($walletBalance, $walletCost, 4), $transactionLog->balance);

        $this->assertNotEmpty($rollingChipLog);
        $this->assertEquals(RollingChipLogTypeEnum::BET_SETTLE, $rollingChipLog->type);
        $this->assertEquals($rollingChipsCost, $rollingChipLog->amount);
        $this->assertEquals($rollingChips, $rollingChipLog->before_balance);
        $this->assertEquals(Common::numSub($rollingChips, $rollingChipsCost, 4), $rollingChipLog->balance);
    }

    /**
     * 測試下注失敗 (重複下注)
     *
     * @return void
     */
    public function test_post_bet_failed_duplicate_bet_case()
    {
        // Arrange
        $betAmount = 0.12;
        $postData = [
            'BetId' => 20000016077,
            'RoundId' => 20000016077,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
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
        $this->regenerateSignature($this->functionName, $postData);

        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        // Act
        $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_DUPLICATE_TRANSACTION,
                'Description' => 'Place bet bet_id duplicate, bet_id: 20000016077',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    /**
     * 測試下注失敗 (餘額不足)
     *
     * @return void
     */
    public function test_post_bet_failed_insufficent_balance_case()
    {
        // Arrange
        $betAmount = 0.12;
        $postData = [
            'BetId' => 20000016077,
            'RoundId' => 20000016077,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => $betAmount,
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
        $this->regenerateSignature($this->functionName, $postData);

        $walletBalance = 0;
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_INSUFFICIENT_BALANCE,
                'Description' => 'Wallet balance not enough',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    /**
     * 測試下注失敗 (無效玩家)
     *
     * @return void
     */
    public function test_post_bet_failed_wrong_player()
    {
        // Arrange
        $postData = [
            'BetId' => 20000016077,
            'RoundId' => 20000016077,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => 'wrongPlayer',
            'BetAmount' => 0.12,
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
        $this->regenerateSignature($this->functionName, $postData);

        // Act
        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $postData);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'Status' => StatusCodeEnum::OPERATOR_INVALID_PLAYER,
                'Description' => 'Account not found',
                'OldBalance' => 0,
                'NewBalance' => 0,
            ]);
    }

    /**
     * 測試下注失敗 (無效簽章)
     *
     * @return void
     */
    public function test_post_bet_failed_invalid_signature()
    {
        // Arrange
        $postData = [
            'BetId' => 20000016077,
            'RoundId' => 20000016077,
            'GameCode' => $this->game->game_type,
            'GameType' => 'Slots',
            'PlayerId' => $this->acctId,
            'BetAmount' => 0.12,
            'Currency' => 'MYR',
            'ExchangeRate' => 0.5,
            'TranDateTime' => '2024-08-20 12:24:37',
            'AuthToken' => '',
            'ProviderTimeZone' => 'Singapore Standard Time',
            'ProviderTranDt' => '2024-08-20T20:24:37.074+08:00',
            'OperatorId' => $this->merchantCode,
            'RequestDateTime' => '2024-08-20 12:24:37',
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
}
