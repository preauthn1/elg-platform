<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\BeSoftSW;

use App\Helpers\Common;

class BeSoftSWGetBalanceTest extends BeSoftSWTestCase
{
    /**
     * 正常查詢餘額
     */
    public function test_get_balance(): void
    {
        $balance = '1234.5678';
        $this->member->update(['balance' => $balance]);

        $response = $this->postJson($this->baseUrl, $this->makeParams(['action' => 1]));

        $response->assertOk()->assertJson([
            'action' => 1,
            'status' => 1,
            'balance' => (float) Common::numMul($balance, $this->vendorInfo->rate, 4),
            'currency' => $this->vendorInfo->currency,
        ]);
    }

    /**
     * uid 不存在時回傳錯誤
     */
    public function test_get_balance_member_not_found(): void
    {
        $response = $this->postJson($this->baseUrl, $this->makeParams([
            'action' => 1,
            'uid' => 'non_existent_user',
            'currency' => $this->vendorInfo->currency,
        ]));

        $response->assertOk()->assertJson(['status' => 1032]);
    }
}
