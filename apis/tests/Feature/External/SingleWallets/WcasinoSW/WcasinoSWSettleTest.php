<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WcasinoSW;

use App\Helpers\Common;
use App\Vendors\Wcasino\Enums\BetRecordStatusEnum;
use App\Vendors\Wcasino\Enums\ErrorCodeEnum;
use App\Vendors\Wcasino\Enums\NotifyTypeEnum;

class WcasinoSWSettleTest extends WcasinoSWTestCase
{
    /**
     * 測試正常結算（type=SETTLE），增加錢包餘額並建立 total_bet_record 與 notify record
     */
    public function test_settle_adds_balance_and_creates_total_bet_record(): void
    {
        // Arrange
        $walletBalance = 900;
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = 100;
        $serialNumber = 'sn_settle_' . uniqid();
        $this->createBetRecord($serialNumber, $betAmount, BetRecordStatusEnum::PENDING->value);

        $winAmount = 80;
        $params = $this->makeSettleParams($serialNumber, [
            'amount' => $winAmount,
            'type' => NotifyTypeEnum::SETTLE->value,
        ]);
        $notifyId = $params['notifyid'];

        // Act
        $response = $this->postJson($this->baseUrl . '/deposit', $params);
        $this->wallet->refresh();

        // Assert
        $platformWin = Common::numDiv($winAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($walletBalance, $platformWin, 4);
        $expectedVendorBalance = Common::numMul($expectedBalance, $this->vendorInfo->rate);

        $response->assertStatus(200)
            ->assertJson([
                'result' => ErrorCodeEnum::SUCCESS->value,
                'desc' => 'OK',
                'balance' => $expectedVendorBalance,
                'orderno' => $notifyId,
            ]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('wcasinosw_bet_records', [
            'bet_id' => $serialNumber,
            'status' => BetRecordStatusEnum::SETTLED->value,
        ]);

        $this->assertDatabaseHas('wcasinosw_notify_records', [
            'bet_id' => $serialNumber,
            'notify_id' => $notifyId,
            'type' => NotifyTypeEnum::SETTLE->value,
            'is_done' => true,
            'is_rollback' => false,
        ]);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'member_id' => $this->member->id,
        ]);
    }

    /**
     * 測試重複送出相同 notifyid 時，視為冪等請求並回傳成功
     */
    public function test_settle_duplicate_notifyid_returns_success(): void
    {
        // Arrange
        $this->member->update(['balance' => 900]);

        $serialNumber = 'sn_settle_dup_' . uniqid();
        $this->createBetRecord($serialNumber, 100, BetRecordStatusEnum::PENDING->value);

        $params = $this->makeSettleParams($serialNumber, ['type' => NotifyTypeEnum::SETTLE->value]);

        // Act - 第一次結算
        $this->postJson($this->baseUrl . '/deposit', $params);
        app()->forgetInstance(\App\Vendors\Models\WcasinoswAccount::class);

        // Act - 重複送出相同 notifyid
        $response = $this->postJson($this->baseUrl . '/deposit', $params);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);
    }

    /**
     * 測試 type=CANCEL 且 amount >= 0 時，退還投注金額（BetCancel），bet record 設為 CANCELLED
     */
    public function test_bet_cancel_via_settle_refunds_balance_when_amount_is_positive(): void
    {
        // Arrange
        $walletBalance = 900;
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = 100;
        $serialNumber = 'sn_betcancel_' . uniqid();
        $this->createBetRecord($serialNumber, $betAmount, BetRecordStatusEnum::PENDING->value);

        // type=7, amount >= 0 → BetCancel
        $refundAmount = $betAmount;
        $params = $this->makeSettleParams($serialNumber, [
            'amount' => $refundAmount,
            'type' => NotifyTypeEnum::CANCEL->value,
        ]);

        // Act
        $response = $this->postJson($this->baseUrl . '/deposit', $params);
        $this->wallet->refresh();

        // Assert
        $platformRefund = Common::numDiv($refundAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($walletBalance, $platformRefund, 4);

        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('wcasinosw_bet_records', [
            'bet_id' => $serialNumber,
            'status' => BetRecordStatusEnum::CANCELLED->value,
        ]);
    }

    /**
     * 測試 type=CANCEL 且 amount < 0 時，扣回派彩金額（SettlementCancel），bet record 設回 PENDING
     */
    public function test_settlement_cancel_deducts_balance_when_amount_is_negative(): void
    {
        // Arrange
        $winAmount = 80;
        $betAmount = 100;
        $walletBalance = Common::numAdd(900, $winAmount, 4); // 已派彩後的餘額
        $this->member->update(['balance' => $walletBalance]);

        $serialNumber = 'sn_setcancel_' . uniqid();
        // 建立已結算狀態的投注記錄
        $this->createBetRecord($serialNumber, $betAmount, BetRecordStatusEnum::SETTLED->value);

        // type=7, amount < 0 → SettlementCancel
        $params = $this->makeSettleParams($serialNumber, [
            'amount' => -$winAmount,
            'type' => NotifyTypeEnum::CANCEL->value,
        ]);

        // Act
        $response = $this->postJson($this->baseUrl . '/deposit', $params);
        $this->wallet->refresh();

        // Assert
        $platformDeduct = Common::numDiv($winAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numSub($walletBalance, $platformDeduct, 4);

        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('wcasinosw_bet_records', [
            'bet_id' => $serialNumber,
            'status' => BetRecordStatusEnum::PENDING->value,
        ]);
    }

    /**
     * 測試 type=RESETTLE 追加派彩，增加錢包餘額並累計 resettlement_count
     */
    public function test_resettle_adds_extra_win_to_balance(): void
    {
        // Arrange
        $initialWin = 80;
        $walletBalance = Common::numAdd(900, $initialWin, 4);
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = 100;
        $serialNumber = 'sn_resettle_' . uniqid();
        $this->createBetRecord($serialNumber, $betAmount, BetRecordStatusEnum::SETTLED->value);

        $resettleAmount = 20;
        $params = $this->makeSettleParams($serialNumber, [
            'amount' => $resettleAmount,
            'type' => NotifyTypeEnum::RESETTLE->value,
        ]);

        // Act
        $response = $this->postJson($this->baseUrl . '/deposit', $params);
        $this->wallet->refresh();

        // Assert
        $platformAdd = Common::numDiv($resettleAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($walletBalance, $platformAdd, 4);

        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('wcasinosw_bet_records', [
            'bet_id' => $serialNumber,
            'status' => BetRecordStatusEnum::SETTLED->value,
            'resettlement_count' => 1,
        ]);
    }
}
