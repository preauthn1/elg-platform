<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\DstPlaySW;

/**
 * php artisan test tests/Feature/External/SingleWallets/DstPlaySW/GetBalanceTest.php --compact
 */
class GetBalanceTest extends DstPlaySWTestCase
{
    public function test_get_balance_returns_current_balance()
    {
        $this->member->update(['balance' => '199875.7479']);

        $response = $this->postJson("{$this->baseurl}/getBalance", $this->signedPostData([
            'user_id' => $this->username,
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => 0,
            'msg' => 'Success',
            'balance' => 199875.7479,
        ]);
    }

    /**
     * 情境：vendor_info.rate 非 1:1（例如 1:1000）
     * 預期：回傳的 balance 要乘上 rate 轉換成廠商幣值單位
     */
    public function test_get_balance_multiplies_by_rate_when_not_one_to_one()
    {
        $this->vendorInfo->update(['rate' => 1000]);
        $this->member->update(['balance' => '10.5000']);

        $response = $this->postJson("{$this->baseurl}/getBalance", $this->signedPostData([
            'user_id' => $this->username,
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => 0,
            'msg' => 'Success',
            'balance' => 10500.0,
        ]);
    }

    public function test_get_balance_returns_not_found_for_unknown_user_id()
    {
        $response = $this->postJson("{$this->baseurl}/getBalance", $this->signedPostData([
            'user_id' => 'unknown_user',
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => 101,
            'msg' => 'Not Found.',
        ]);
    }

    public function test_get_balance_returns_unauthorized_when_account_disabled()
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/getBalance", $this->signedPostData([
            'user_id' => $this->username,
        ]));

        $response->assertStatus(200)->assertJson([
            'code' => 107,
            'msg' => 'Unauthorized request.',
        ]);
    }
}
