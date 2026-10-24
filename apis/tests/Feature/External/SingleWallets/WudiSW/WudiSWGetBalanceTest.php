<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\WudiSW;

use App\Helpers\Common;
use App\Vendors\WudiSW\WudiSWService;
use PHPUnit\Framework\Attributes\Group;

class WudiSWGetBalanceTest extends WudiSWTestCase
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

        $result = (new WudiSWService)->getBalance($this->username);

        $this->assertNotEmpty($result);
        $this->assertEquals(Common::numMul($balance, $this->vendorInfo->rate, 4), $result);
    }

    #[Group('rules/vendor-wudisw#R-6')]
    public function test_get_balance_with_rolling_chips()
    {
        $balance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $balance,
        ]);

        $rollingChips = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->rollingChipWallet->update([
            'balance' => $rollingChips,
        ]);
        $this->enableRollingChip();

        $expectedBalance = Common::numAdd($balance, $rollingChips);

        $balance = (new WudiSWService)->getBalance($this->username);

        $this->assertNotEmpty($balance);
        $this->assertEquals(Common::numMul($expectedBalance, $this->vendorInfo->rate, 4), $balance);
    }
}
