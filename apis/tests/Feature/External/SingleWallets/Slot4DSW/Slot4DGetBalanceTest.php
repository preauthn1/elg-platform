<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Slot4DSW;

use App\Helpers\Common;
use App\Vendors\Slot4DSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * php artisan test tests/Feature/External/SingleWallets/Slot4DSW/Slot4DGetBalanceTest.php --compact
 * php artisan test tests/Feature/External/SingleWallets/Slot4DSW --compact
 */
class Slot4DGetBalanceTest extends Slot4DTestCase
{
    private string $functionName = 'GetBalance';

    private function makePostData(): array
    {
        $data = $this->basePostData();
        $this->regenerateSignature($this->functionName, $data);

        return $data;
    }

    #[TestDox('會員錢包有餘額：回傳 OK 及依匯率換算後的正確餘額')]
    public function test_get_balance_returns_member_balance()
    {
        $walletBalance = '1234.5000';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'Balance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);
    }

    #[TestDox('餘額含 4 位小數：回傳完整 4 位精度，不被 floor 到 2 位（廠商規格 Balance 為 DECIMAL，例 1233.5000）')]
    public function test_get_balance_preserves_four_decimal_precision()
    {
        $walletBalance = '1234.5678';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'Balance' => (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4),
        ]);
    }

    #[TestDox('會員同時擁有主錢包與 RollingChip 錢包：回傳 OK，Balance 為兩個錢包合計後換算的金額')]
    public function test_get_balance_includes_rolling_chip_wallet()
    {
        $walletBalance = '1000.0000';
        $rollingChips = '200.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->rollingChipWallet->update(['balance' => $rollingChips]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $expectedBalance = Common::numMul(
            Common::numAdd($walletBalance, $rollingChips),
            $this->vendorInfo->rate,
            4
        );

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::OK->value,
            'Balance' => (float) $expectedBalance,
        ]);
    }

    #[TestDox('遊戲帳號狀態為停用：回傳 PLAYER_INACTIVE')]
    public function test_get_balance_returns_inactive_player_error()
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $this->makePostData());

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::PLAYER_INACTIVE->value,
        ]);
    }

    #[TestDox('PlayerId 不存在於系統：回傳 INVALID_PLAYER')]
    public function test_get_balance_returns_invalid_player_error_for_unknown_player()
    {
        $data = $this->basePostData();
        $data['PlayerId'] = 'unknown_player';
        $this->regenerateSignature($this->functionName, $data);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_PLAYER->value,
        ]);
    }

    #[TestDox('請求攜帶錯誤的 Signature：回傳 INVALID_SIGNATURE')]
    public function test_get_balance_returns_invalid_signature_error()
    {
        $data = $this->basePostData();
        $data['Signature'] = 'invalid_signature';

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_SIGNATURE->value,
        ]);
    }

    #[TestDox('OperatorId 不屬於當前帳號的 vendorInfo：回傳 INVALID_OPERATOR_ID')]
    public function test_get_balance_returns_invalid_operator_id_error()
    {
        $data = $this->basePostData();
        $data['OperatorId'] = 'unknown_operator';
        $this->regenerateSignature($this->functionName, $data);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INVALID_OPERATOR_ID->value,
        ]);
    }

    #[TestDox('請求帶入不正確的 AuthToken：回傳 INTERNAL_SERVER_ERROR')]
    public function test_get_balance_returns_error_when_auth_token_mismatch()
    {
        $this->account->update(['auth_token' => 'valid_auth_token']);

        $data = $this->basePostData();
        $data['AuthToken'] = 'wrong_auth_token';
        $this->regenerateSignature($this->functionName, $data);

        $response = $this->postJson("{$this->baseurl}/{$this->functionName}", $data);

        $response->assertStatus(200)->assertJson([
            'Status' => OperatorStatusEnum::INTERNAL_SERVER_ERROR->value,
        ]);
    }
}
