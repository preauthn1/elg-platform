<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\MegaH5SW;

use App\Helpers\Common;
use App\Vendors\MegaH5SW\OperatorStatusEnum;

/**
 * php artisan test tests/Feature/External/SingleWallets/MegaH5SW/MegaH5GetBalanceTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/MegaH5SW --compact
 */
class MegaH5GetBalanceTest extends MegaH5TestCase
{
    private string $functionName = 'GetBalance';

    private function makePostData(array $overrides = []): array
    {
        $data = array_merge($this->basePostData(), $overrides);
        $this->regenerateSignature($this->functionName, $data);

        return $data;
    }

    /**
     * 情境：會員餘額帶有超過 2 位的小數
     * 預期：回傳的 Balance 保留完整 4 位精度，不會被 floor 到 2 位捨去尾數
     */
    public function test_get_balance_preserves_four_decimal_precision()
    {
        $walletBalance = '100.1234';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'Balance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);
    }
}
