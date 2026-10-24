<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\TFGamingSW;

use App\Helpers\Common;
use App\Vendors\TFGamingSW\TFGamingSWService;

class TFGamingSWGetBalanceTest extends TFGamingSWTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_get_balance()
    {
        $balance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $balance,
        ]);

        $result = TFGamingSWService::getBalance($this->username);

        $this->assertNotEmpty($result);
        $this->assertEquals(Common::numMul($balance, $this->vendorInfo->rate, 4), $result);
    }

    public function test_get_balance_with_rolling_chips()
    {
        $walletBalance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $walletBalance,
        ]);

        $rollingChips = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);

        $this->enableRollingChip();

        $expectedBalance = Common::numAdd($walletBalance, $rollingChips);

        $balance = TFGamingSWService::getBalance($this->username);

        $this->assertNotEmpty($balance);
        $this->assertEquals(Common::numMul($expectedBalance, $this->vendorInfo->rate, 4), $balance);
    }
}
