<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Transaction\TransactionEnum;
use App\Helpers\Common;
use App\Models\Member;
use App\Models\TransactionLog;
use App\Models\VendorInfo;
use App\Services\TransactionLogCreateService;
use Mockery;
use Tests\TestCase;

/**
 * TransactionLogService 交易日誌服務測試
 *
 * php artisan test tests/Unit/Services/TransactionLogServiceTest.php
 */
class TransactionLogServiceTest extends TestCase
{
    protected $member;

    protected $vendorInfo;

    protected $transactionLogService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->member = Mockery::mock(Member::class);
        $this->vendorInfo = Mockery::mock(VendorInfo::class);

        $this->transactionLogService = new TransactionLogCreateService($this->member, $this->vendorInfo);
    }

    public function test_create_transaction_log_seamless_wallet()
    {
        // Arrange
        $amount = 100;
        $balanceBefore = 1000;
        $balanceAfter = 1100;

        $this->member->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->member->shouldReceive('getAttribute')->with('parent')->andReturn(2);
        $this->member->shouldReceive('getAttribute')->with('is_event_wallet')->andReturn(false);

        $this->vendorInfo->shouldReceive('getAttribute')->with('vendor_id')->andReturn(3);
        $this->vendorInfo->shouldReceive('getAttribute')->with('rate')->andReturn(1);

        // Act
        $transactionLog = $this->transactionLogService->create($amount, $balanceBefore, $balanceAfter);

        // Assert
        $this->assertTrue(is_object($transactionLog));
        $this->assertDatabaseHas('transaction_logs', [
            'id' => $transactionLog->id,
            'member_id' => 1,
            'agent_id' => 2,
            'vendor_id' => 3,
            'point' => Common::numSub($balanceBefore, $balanceAfter),
            'vendor_point' => Common::numMul(Common::numSub($balanceBefore, $balanceAfter), $this->vendorInfo->rate),
            'before_balance' => $balanceBefore,
            'balance' => $balanceAfter,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);
    }

    public function test_create_transaction_log_seamless_wallet_multiple_equals_to_zero()
    {
        // Arrange
        $this->member->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->member->shouldReceive('getAttribute')->with('parent')->andReturn(2);
        $this->member->shouldReceive('getAttribute')->with('is_event_wallet')->andReturn(false);

        $this->vendorInfo->shouldReceive('getAttribute')->with('vendor_id')->andReturn(3);
        $this->vendorInfo->shouldReceive('getAttribute')->with('rate')->andReturn(1);

        $amount = 100;
        $balanceBefore = 1000;
        $balanceAfter = 1100;

        $amount2 = -100;
        $balanceBefore2 = 1100;
        $balanceAfter2 = 1000;

        // Act
        $transactionLog = $this->transactionLogService->create($amount, $balanceBefore, $balanceAfter);
        $transactionLog = $this->transactionLogService->create($amount2, $balanceBefore2, $balanceAfter2);

        // Assert
        $this->assertTrue(is_object($transactionLog));
        $this->assertDatabaseHas('transaction_logs', [
            'id' => $transactionLog->id,
            'member_id' => 1,
            'agent_id' => 2,
            'vendor_id' => 3,
            'point' => 0.000,
            'vendor_point' => 0.000,
            'before_balance' => $balanceBefore,
            'balance' => $balanceAfter2,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);
    }

    public function test_create_transaction_log_seamless_wallet_multiple_double()
    {
        // Arrange
        $this->member->shouldReceive('getAttribute')->with('id')->andReturn(1);
        $this->member->shouldReceive('getAttribute')->with('parent')->andReturn(2);
        $this->member->shouldReceive('getAttribute')->with('is_event_wallet')->andReturn(false);

        $this->vendorInfo->shouldReceive('getAttribute')->with('vendor_id')->andReturn(3);
        $this->vendorInfo->shouldReceive('getAttribute')->with('rate')->andReturn(1);

        $amount = 100;
        $balanceBefore = 1000;
        $balanceAfter = 1100;

        $amount2 = 100;
        $balanceBefore2 = 1100;
        $balanceAfter2 = 1200;

        // Act
        $transactionLog = $this->transactionLogService->create($amount, $balanceBefore, $balanceAfter);
        $transactionLog = $this->transactionLogService->create($amount2, $balanceBefore2, $balanceAfter2);

        // Assert
        $this->assertTrue(is_object($transactionLog));
        $this->assertDatabaseHas('transaction_logs', [
            'id' => $transactionLog->id,
            'member_id' => 1,
            'agent_id' => 2,
            'vendor_id' => 3,
            'point' => Common::numSub($balanceBefore, $balanceAfter2),
            'vendor_point' => Common::numMul(Common::numSub($balanceBefore, $balanceAfter2), $this->vendorInfo->rate),
            'before_balance' => $balanceBefore,
            'balance' => $balanceAfter2,
            'type' => TransactionEnum::SEAMLESS_WALLET_BET_SETTLE,
            'status' => 999,
        ]);
    }

    public function test_create_transaction_log_different_games()
    {
        // Create first, vendor_id = 1
        $member1 = new Member;
        $member1->id = 1;
        $member1->parent = 2;

        $vendorInfo1 = new VendorInfo;
        $vendorInfo1->vendor_id = 1;
        $vendorInfo1->rate = 1;

        $amount = 100;
        $balanceBefore = 1000;
        $balanceAfter = 1100;

        $transactionLog_1 = (new TransactionLogCreateService($member1, $vendorInfo1))
            ->create($amount, $balanceBefore, $balanceAfter);

        // Create second, vendor_id = 2
        $member2 = new Member;
        $member2->id = 1;
        $member2->parent = 2;

        $vendorInfo2 = new VendorInfo;
        $vendorInfo2->vendor_id = 2;
        $vendorInfo2->rate = 1;

        $amount = 100;
        $balanceBefore = 1000;
        $balanceAfter = 1100;

        $amount = 100;
        $balanceBefore = 1000;
        $balanceAfter = 1100;

        $transactionLog_2 = (new TransactionLogCreateService($member2, $vendorInfo2))
            ->create($amount, $balanceBefore, $balanceAfter);

        // Assert
        $this->assertEquals(2, TransactionLog::count());
        $this->assertTrue(is_object($transactionLog_1));
        $this->assertTrue(is_object($transactionLog_2));
        $this->assertNotEquals($transactionLog_1, $transactionLog_2);
        $this->assertDatabaseHas('transaction_logs', [
            'id' => $transactionLog_1->id,
            'member_id' => 1,
            'agent_id' => 2,
            'vendor_id' => 1,
            'status' => 1000,
        ]);
        $this->assertDatabaseHas('transaction_logs', [
            'id' => $transactionLog_2->id,
            'member_id' => 1,
            'agent_id' => 2,
            'vendor_id' => 2,
            'status' => 999,
        ]);
    }
}
