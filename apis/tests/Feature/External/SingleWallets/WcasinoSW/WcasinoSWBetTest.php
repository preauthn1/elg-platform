<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WcasinoSW;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\Transaction\TransactionStatusEnum;
use App\Helpers\Common;
use App\Vendors\Wcasino\Enums\BetRecordStatusEnum;
use App\Vendors\Wcasino\Enums\ErrorCodeEnum;
use App\Vendors\Wcasino\Enums\NotifyTypeEnum;

class WcasinoSWBetTest extends WcasinoSWTestCase
{
    /**
     * 測試正常投注，扣除錢包餘額並建立 bet record 與 notify record
     */
    public function test_bet_deducts_wallet_and_creates_records(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = 100;                                                    // vendor currency
        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4); // platform currency
        $params = $this->makeBetParams(['amount' => -$betAmount]);
        $serialNumber = $params['serialnumber'];
        $notifyId = $params['notifyid'];
        $params['sign'] = $this->generateSign($params);

        // Act
        $response = $this->postJson($this->baseUrl . '/deduct', $params);
        $this->wallet->refresh();

        // Assert
        $expectedBalance = Common::numSub($walletBalance, $platformBet, 4);

        $response->assertStatus(200)
            ->assertJson([
                'result' => ErrorCodeEnum::SUCCESS->value,
                'desc' => 'OK',
                'balance' => $expectedBalance,
                'orderno' => $notifyId,
            ]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('wcasinosw_bet_records', [
            'bet_id' => $serialNumber,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'status' => BetRecordStatusEnum::PENDING->value,
            'bet' => $platformBet,
            'valid_bet' => $platformBet,
            'win_lose' => Common::numMul($platformBet, -1, 4),
            'rolling_chips' => '0.0000',
        ]);

        $this->assertDatabaseHas('wcasinosw_notify_records', [
            'bet_id' => $serialNumber,
            'notify_id' => $notifyId,
            'type' => NotifyTypeEnum::BET->value,
            'is_done' => true,
            'is_rollback' => false,
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor->id,
            'before_balance' => $walletBalance,
            'balance' => $expectedBalance,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => TransactionStatusEnum::STATUS_GAMING,
        ]);
    }

    /**
     * 測試啟用滾存點數時，優先扣除滾存點數錢包，不足部分再扣主錢包
     */
    public function test_bet_with_rolling_chips_deducts_chip_wallet_first(): void
    {
        // Arrange
        $this->enableRollingChip();

        $walletBalance = 2000;
        $rollingChips = 90;
        $this->member->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChips]);

        $betAmount = 200;
        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);

        $params = $this->makeBetParams(['amount' => -$betAmount]);
        $serialNumber = $params['serialnumber'];

        // Act
        $response = $this->postJson($this->baseUrl . '/deduct', $params);
        $this->wallet->refresh();
        $this->rollingChipWallet->refresh();

        // Assert
        $expectedRollingChips = '0.0000';
        $chipDeducted = $rollingChips;
        $walletDeducted = Common::numSub($platformBet, $chipDeducted, 4);
        $expectedWalletBalance = Common::numSub($walletBalance, $walletDeducted, 4);
        $expectedTotalBalance = Common::numAdd($expectedWalletBalance, 0);
        $expectedVendorBalance = Common::numMul($expectedTotalBalance, $this->vendorInfo->rate);

        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);

        $this->assertEquals($expectedWalletBalance, $this->wallet->balance);
        $this->assertEquals($expectedRollingChips, number_format((float) $this->rollingChipWallet->balance, 4, '.', ''));

        $this->assertDatabaseHas('wcasinosw_bet_records', [
            'bet_id' => $serialNumber,
            'rolling_chips' => number_format($chipDeducted, 4, '.', ''),
        ]);

        $this->assertDatabaseHas('rolling_chip_logs', [
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor->id,
            'before_balance' => $rollingChips,
            'balance' => '0.0000',
            'type' => RollingChipLogTypeEnum::BET_SETTLE,
            'status' => TransactionStatusEnum::STATUS_SUCCESS,
        ]);
    }

    /**
     * 測試重複送出相同 notifyid 時，視為冪等請求並回傳成功
     */
    public function test_bet_duplicate_notifyid_returns_success(): void
    {
        // Arrange
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $params = $this->makeBetParams(['amount' => -100]);

        // Act - 第一次投注
        $this->postJson($this->baseUrl . '/deduct', $params);
        app()->forgetInstance(\App\Vendors\Models\WcasinoswAccount::class);

        // Act - 重複送出相同 notifyid
        $response = $this->postJson($this->baseUrl . '/deduct', $params);

        // Assert - 重複 notifyid 視為已處理，回傳 SUCCESS
        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);
    }

    /**
     * 測試餘額不足時，回傳 INSUFFICIENT_BALANCE 錯誤代碼
     */
    public function test_bet_with_insufficient_balance_returns_error(): void
    {
        // Arrange
        $this->member->update(['balance' => 10]);

        $params = $this->makeBetParams(['amount' => -10000]);

        // Act
        $response = $this->postJson($this->baseUrl . '/deduct', $params);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::INSUFFICIENT_BALANCE->value]);
    }
}
