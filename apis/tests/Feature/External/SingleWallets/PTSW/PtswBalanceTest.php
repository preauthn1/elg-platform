<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PTSW;

use App\Facades\BotNotify;
use App\Helpers\Common;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * PTSW balance（查詢餘額）端點基本行為測試。
 *
 * php artisan test tests/Feature/External/SingleWallets/PTSW/PtswBalanceTest.php --compact
 */
class PtswBalanceTest extends PtswTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // 見 PtswSettlementTest::setUp() 註解。
        BotNotify::shouldReceive('send')->zeroOrMoreTimes();
    }

    #[TestDox('balance：token 正確時回傳依匯率換算後的餘額')]
    #[Group('rules/vendor-ptsw#R-1')]
    #[Group('rules/vendor-ptsw#R-7')]
    public function test_balance_success_returns_converted_balance(): void
    {
        $walletBalance = '888.8800';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson("{$this->baseurl}/balance", [
            'requestId' => 'req-balance-1',
            'username' => $this->username,
            'externalToken' => $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonMissing(['error']);

        $expectedReal = round((float) Common::numMul($walletBalance, $this->vendorInfo->rate, 2), 2);
        $response->assertJson([
            'requestId' => 'req-balance-1',
            'balance' => [
                'real' => $expectedReal,
            ],
        ]);
    }

    #[TestDox('balance：帳號停用（status=0）時回 ERR_PLAYER_NOT_FOUND')]
    #[Group('rules/vendor-ptsw#R-7')]
    public function test_balance_inactive_account_returns_err_player_not_found(): void
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/balance", [
            'requestId' => 'req-balance-inactive',
            'username' => $this->username,
            'externalToken' => $this->token,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-balance-inactive',
            'error' => ['code' => 'ERR_PLAYER_NOT_FOUND'],
        ]);
    }

    #[TestDox('balance：externalToken 錯誤時回 ERR_AUTHENTICATION_FAILED')]
    #[Group('rules/vendor-ptsw#R-4')]
    public function test_balance_invalid_token_returns_err_authentication_failed(): void
    {
        $response = $this->postJson("{$this->baseurl}/balance", [
            'requestId' => 'req-balance-bad-token',
            'username' => $this->username,
            'externalToken' => 'wrong-token',
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'requestId' => 'req-balance-bad-token',
            'error' => ['code' => 'ERR_AUTHENTICATION_FAILED'],
        ]);
    }
}
