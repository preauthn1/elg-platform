<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\RollingChipLogTypeEnum;
use App\Enums\WalletEnum;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Services\RollingChipService;
use Tests\TestCase;

/**
 * RollingChipService 滾動籌碼服務測試
 *
 * php artisan test tests/Unit/Services/RollingChipServiceTest.php
 */
class RollingChipServiceTest extends TestCase
{
    protected MemberWallet $rollingChipWallet;

    protected Member $member;

    private int $vendor_id = 3;

    protected function setUp(): void
    {
        parent::setUp();

        $agent = $this->createAgentAndDomain();
        $this->member = Member::factory()->create([
            'parent' => $agent->id,
        ]);

        $this->rollingChipWallet = MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP,
            'balance' => 0,
        ]);
    }

    public function test_pay_with_sufficient_balance()
    {
        $initialBalance = 100.0000;
        $payAmount = 50.0000;
        $finalBalance = 50.0000;

        $this->rollingChipWallet->update([
            'balance' => $initialBalance,
        ]);

        $result = (new RollingChipService(member: $this->member, vendorId: $this->vendor_id))
            ->sub($payAmount, RollingChipLogTypeEnum::BET_SETTLE);

        $this->assertEquals($initialBalance, $result->beforeBalance);
        $this->assertEquals($finalBalance, $result->afterBalance);

        $this->assertDatabaseHas('rolling_chip_logs', [
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor_id,
            'type' => RollingChipLogTypeEnum::BET_SETTLE,
            'amount' => $payAmount,
            'before_balance' => $initialBalance,
            'balance' => $finalBalance,
            'status' => 1000,
            'admin' => 0,
        ]);
    }

    public function test_pay_with_insufficient_balance()
    {
        $initialBalance = 30.0000;
        $payAmount = 50.0000;
        $finalBalance = 0.0000;

        $this->rollingChipWallet->update([
            'balance' => $initialBalance,
        ]);

        $result = (new RollingChipService(member: $this->member, vendorId: $this->vendor_id))
            ->sub($payAmount, RollingChipLogTypeEnum::BET_SETTLE);

        $this->assertEquals($initialBalance, $result->beforeBalance);
        $this->assertEquals($finalBalance, $result->afterBalance);

        $this->assertDatabaseHas('rolling_chip_logs', [
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor_id,
            'type' => RollingChipLogTypeEnum::BET_SETTLE,
            'amount' => $initialBalance,
            'before_balance' => $initialBalance,
            'balance' => $finalBalance,
            'status' => 1000,
            'admin' => 0,
        ]);
    }

    public function test_pay_with_zero_balance()
    {
        $initialBalance = 0.0000;
        $payAmount = 50.0000;

        $this->rollingChipWallet->update([
            'balance' => $initialBalance,
        ]);

        $result = (new RollingChipService(member: $this->member, vendorId: $this->vendor_id))
            ->sub($payAmount, RollingChipLogTypeEnum::BET_SETTLE);

        $this->assertEquals(0.0000, $result->beforeBalance);
        $this->assertEquals(0.0000, $result->afterBalance);
    }

    public function test_refund_with_valid_amount()
    {
        $initialBalance = 100.0000;
        $refundAmount = 50.0000;
        $finalBalance = 150.0000;

        $this->rollingChipWallet->update([
            'balance' => $initialBalance,
        ]);

        $result = (new RollingChipService(member: $this->member, vendorId: $this->vendor_id))
            ->add($refundAmount, RollingChipLogTypeEnum::BET_CANCEL);
        $this->assertEquals($initialBalance, $result->beforeBalance);
        $this->assertEquals($finalBalance, $result->afterBalance);
        $this->assertDatabaseHas('rolling_chip_logs', [
            'agent_id' => $this->member->parent,
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor_id,
            'type' => RollingChipLogTypeEnum::BET_CANCEL->value,
            'amount' => $refundAmount,
            'before_balance' => $initialBalance,
            'balance' => $finalBalance,
            'status' => 1000,
            'admin' => 0,
        ]);
    }

    public function test_refund_with_zero_amount()
    {
        $initialBalance = 100.0000;
        $refundAmount = 0.0000;

        $this->rollingChipWallet->update([
            'balance' => $initialBalance,
        ]);

        $result = (new RollingChipService(member: $this->member, vendorId: $this->vendor_id))
            ->add($refundAmount, RollingChipLogTypeEnum::BET_CANCEL);

        $this->assertEquals($initialBalance, $result->beforeBalance);
        $this->assertEquals($initialBalance, $result->afterBalance);
    }
}
