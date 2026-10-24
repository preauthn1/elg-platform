<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PegasusSW;

use App\Vendors\PegasusSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW/GetBalanceTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/PegasusSW --compact
 */
class GetBalanceTest extends PgsswTestCase
{
    private function postData(): array
    {
        return ['sessionToken' => $this->sessionToken];
    }

    #[TestDox('以 sessionToken 查詢：回傳 code=0 及正確 balance')]
    #[Group('rules/vendor-target#R-7')]
    public function test_get_balance_returns_member_balance()
    {
        $balance = '1234.5000';
        $this->member->update(['balance' => $balance]);

        $response = $this->postWithIdentity('/players/wallet', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => [
                'balance' => $this->expectedResponseAmount($balance),
            ],
        ]);
    }

    #[TestDox('以 playerUniqueID 查詢（優先於 sessionToken）：回傳 code=0 及正確 balance')]
    #[Group('rules/vendor-target#R-3')]
    public function test_get_balance_returns_balance_by_player_unique_id()
    {
        $balance = '500.0000';
        $this->member->update(['balance' => $balance]);

        $response = $this->postWithIdentity('/players/wallet', [
            'playerUniqueID' => $this->playerUniqueId,
        ]);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => ['balance' => (float) $balance],
        ]);
    }

    #[TestDox('餘額帶三位以上小數：截到小數兩位（target 規格不支援小數三位以下）')]
    #[Group('rules/vendor-target#R-2')]
    public function test_get_balance_truncates_to_two_decimals()
    {
        // 平台餘額 1.8495，target 僅接受兩位；截斷後為 1.84
        $this->member->update(['balance' => '1.8495']);

        $response = $this->postWithIdentity('/players/wallet', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => ['balance' => 1.84],
        ]);
    }

    #[TestDox('餘額尾數趨近進位：仍為無條件捨去，不得四捨五入')]
    #[Group('rules/vendor-target#R-2')]
    public function test_get_balance_never_rounds_up()
    {
        // 回報餘額若大於實際餘額，玩家會以該金額投注並在 placeBet 撞到 403203，
        // target 隨即取消該注單，故此處必須捨去而非進位
        $this->member->update(['balance' => '99.9999']);

        $response = $this->postWithIdentity('/players/wallet', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => ['balance' => 99.99],
        ]);
    }

    #[TestDox('rate=1000（K 貨幣）：先以 rate 換算再截兩位')]
    #[Group('rules/vendor-target#R-2')]
    public function test_get_balance_applies_rate_before_truncating()
    {
        $this->vendorInfo->update(['rate' => 1000]);
        $this->member->update(['balance' => '1.2345']);

        $response = $this->postWithIdentity('/players/wallet', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::OK->value,
            'data' => ['balance' => 1234.5],
        ]);
    }

    #[TestDox('遊戲帳號停用：回傳 INVALID_SIGNATURE')]
    #[Group('rules/vendor-target#R-7')]
    public function test_get_balance_returns_error_when_account_disabled()
    {
        $this->account->update(['status' => 0]);

        $response = $this->postWithIdentity('/players/wallet', $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
    }

    #[TestDox('X-PROVIDER-IDENTITY 標頭錯誤：回傳 INVALID_SIGNATURE')]
    public function test_get_balance_returns_error_when_identity_header_is_wrong()
    {
        $response = $this->withHeaders(['X-PROVIDER-IDENTITY' => 'wrong-identity'])
            ->postJson("{$this->baseUrl}/players/wallet", $this->postData());

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
    }

    #[TestDox('sessionToken 查無帳號：回傳 INVALID_SIGNATURE')]
    #[Group('rules/vendor-target#R-3')]
    public function test_get_balance_returns_error_when_session_token_not_found()
    {
        $response = $this->postWithIdentity('/players/wallet', [
            'sessionToken' => 'unknown-token',
        ]);

        $response->assertStatus(200)->assertJson([
            'code' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
    }
}
