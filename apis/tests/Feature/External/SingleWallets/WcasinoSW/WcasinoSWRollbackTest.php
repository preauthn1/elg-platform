<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WcasinoSW;

use App\Helpers\Common;
use App\Vendors\Wcasino\Enums\BetRecordStatusEnum;
use App\Vendors\Wcasino\Enums\ErrorCodeEnum;
use App\Vendors\Wcasino\Enums\NotifyTypeEnum;

class WcasinoSWRollbackTest extends WcasinoSWTestCase
{
    /**
     * 測試無對應 notify record 時，rollback 不執行任何操作並回傳當前餘額
     */
    public function test_rollback_without_target_notify_record_returns_current_balance(): void
    {
        // Arrange - 沒有可 rollback 的 notify record
        $walletBalance = 1500;
        $this->member->update(['balance' => $walletBalance]);

        $serialNumber = 'sn_rb_noop_' . uniqid();
        $params = $this->makeRollbackParams($serialNumber, NotifyTypeEnum::BET->value);
        $notifyId = $params['notifyid'];

        // Act
        $response = $this->postJson($this->baseUrl . '/rollback', $params);

        // Assert - 無操作，回傳當前餘額
        $expectedVendorBalance = Common::numMul($walletBalance, $this->vendorInfo->rate);

        $response->assertStatus(200)
            ->assertJson([
                'result' => ErrorCodeEnum::SUCCESS->value,
                'desc' => 'OK',
                'balance' => $expectedVendorBalance,
                'orderno' => $notifyId,
            ]);

        // 餘額不變
        $this->wallet->refresh();
        $this->assertEquals($walletBalance, $this->wallet->balance);
    }

    /**
     * 測試 type=BET 的 rollback 退還投注金額，並將 bet record 設為 CANCELLED
     */
    public function test_rollback_bet_type_refunds_bet_amount(): void
    {
        // Arrange - 模擬已完成投注後的狀態
        $betAmount = 100;
        $walletBalance = Common::numSub(1500, $betAmount, 4); // 已扣投注後的餘額
        $this->member->update(['balance' => $walletBalance]);

        $serialNumber = 'sn_rb_bet_' . uniqid();

        // 建立 PENDING 的投注記錄
        $this->createBetRecord($serialNumber, $betAmount, BetRecordStatusEnum::PENDING->value);

        // 建立對應的 notify record（is_done=true, is_rollback=false）
        $this->createNotifyRecord(
            betId: $serialNumber,
            type: NotifyTypeEnum::BET->value,
            amount: $betAmount,
            isDone: true,
            isRollback: false,
        );

        $params = $this->makeRollbackParams($serialNumber, NotifyTypeEnum::BET->value, [
            'amount' => $betAmount,
        ]);

        // Act
        $response = $this->postJson($this->baseUrl . '/rollback', $params);
        $this->wallet->refresh();

        // Assert
        $platformRefund = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($walletBalance, $platformRefund, 4);

        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('wcasinosw_bet_records', [
            'bet_id' => $serialNumber,
            'status' => BetRecordStatusEnum::CANCELLED->value,
        ]);

        // 確認 rollback notify record 已建立
        $this->assertDatabaseHas('wcasinosw_notify_records', [
            'bet_id' => $serialNumber,
            'type' => NotifyTypeEnum::BET->value,
            'is_rollback' => true,
            'is_done' => true,
        ]);
    }

    /**
     * 測試重複送出相同 rollback notifyid 時，視為冪等請求並回傳成功
     */
    public function test_rollback_duplicate_rollback_notifyid_returns_success(): void
    {
        // Arrange
        $betAmount = 100;
        $walletBalance = Common::numSub(1500, $betAmount, 4);
        $this->member->update(['balance' => $walletBalance]);

        $serialNumber = 'sn_rb_dup_' . uniqid();
        $this->createBetRecord($serialNumber, $betAmount, BetRecordStatusEnum::PENDING->value);
        $this->createNotifyRecord(
            betId: $serialNumber,
            type: NotifyTypeEnum::BET->value,
            amount: $betAmount,
        );

        $params = $this->makeRollbackParams($serialNumber, NotifyTypeEnum::BET->value, [
            'amount' => $betAmount,
        ]);

        // Act - 第一次 rollback
        $this->postJson($this->baseUrl . '/rollback', $params);
        app()->forgetInstance(\App\Vendors\Models\WcasinoswAccount::class);

        // Act - 重複送出相同 notifyid
        $response = $this->postJson($this->baseUrl . '/rollback', $params);

        // Assert - 重複 rollback 視為已處理
        $response->assertStatus(200)
            ->assertJson(['result' => ErrorCodeEnum::SUCCESS->value]);
    }

    /**
     * 測試 type=SETTLE 的 rollback 扣回派彩金額，並將 bet record 設回 PENDING
     */
    public function test_rollback_settle_type_deducts_settled_win(): void
    {
        // Arrange
        $betAmount = 100;
        $winAmount = 80;
        $walletBalance = Common::numAdd(1500, $winAmount, 4); // 已結算後的餘額
        $this->member->update(['balance' => $walletBalance]);

        $serialNumber = 'sn_rb_settle_' . uniqid();

        // 建立已結算的投注記錄
        $this->createBetRecord($serialNumber, $betAmount, BetRecordStatusEnum::SETTLED->value);

        // 建立 SETTLE type 的 notify record
        $this->createNotifyRecord(
            betId: $serialNumber,
            type: NotifyTypeEnum::SETTLE->value,
            amount: $winAmount,
        );

        $params = $this->makeRollbackParams($serialNumber, NotifyTypeEnum::SETTLE->value, [
            'amount' => -$winAmount, // rollback settle → 扣回派彩（SettlementCancel 邏輯）
        ]);

        // Act
        $response = $this->postJson($this->baseUrl . '/rollback', $params);
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
}
