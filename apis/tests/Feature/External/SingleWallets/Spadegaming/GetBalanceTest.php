<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Helpers\Common;
use App\Vendors\SpadegamingSW\Enums\ReturnStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * 查詢餘額 API 測試（api header = getBalance）
 *
 * 驗證 getBalance 在正常與異常情境下的回應格式與錯誤碼：
 * - 成功：回傳帳號資訊與餘額（含禮碼錢包合計）
 * - 帳號不存在（account 被刪除）→ ACCT_NOT_FOUND
 * - 會員不存在（member 被刪除）→ ACCT_INACTIVE
 * - 遊戲帳號停用（account.status=0）→ ACCT_INACTIVE
 * - VendorInfo 不存在（vendorInfo 被刪除）→ MERCHANT_NOT_FOUND
 * - VendorInfo 停用（status=0）→ MERCHANT_SUSPEND
 *
 * 後五種錯誤由 BaseController::callAction() 的前置檢查攔截，不進入 getBalance 邏輯。
 */
class GetBalanceTest extends SpadegamingSWTestCase
{
    /** R-7：查餘額時回會員真金加禮碼的合計再乘以匯率。 */
    #[Group('rules/vendor-spadegamingsw#R-7')]
    #[TestDox('查餘額回真金餘額乘以匯率')]
    public function test_get_balance_success()
    {
        // Arrange
        $serialNo = time() . rand(10, 99);

        $balance = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->member->update([
            'balance' => $balance,
        ]);

        // Act
        $response = $this->sendRequest($serialNo);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'msg' => 'success',
                'merchantCode' => $this->merchantCode,
                'serialNo' => $serialNo,
                'acctInfo' => [
                    'acctId' => $this->acctId,
                    'userName' => $this->acctId,
                    'currency' => $this->vendorInfo->currency,
                    'balance' => Common::numMul($balance, $this->vendorInfo->rate, 4),
                ],
            ]);
    }

    /** R-7：查餘額時回會員真金加禮碼的合計再乘以匯率。 */
    #[Group('rules/vendor-spadegamingsw#R-7')]
    #[TestDox('查餘額把禮碼錢包餘額合計進去')]
    public function test_get_balance_success_with_rolling_chips()
    {
        // Arrange
        $serialNo = time() . rand(10, 99);

        // Add rolling chip wallet
        $rollingChip = rand(1000, 5000) . '.' . rand(1000, 5000);
        $this->rollingChipWallet->update([
            'balance' => $rollingChip,
        ]);
        $this->vendorInfo->update([
            'rolling_chip_status' => true,
        ]);

        // Act
        $response = $this->sendRequest($serialNo);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'msg' => 'success',
                'merchantCode' => $this->merchantCode,
                'serialNo' => $serialNo,
                'acctInfo' => [
                    'acctId' => $this->acctId,
                    'userName' => $this->acctId,
                    'currency' => $this->vendorInfo->currency,
                    'balance' => Common::numMul($rollingChip, $this->vendorInfo->rate, 4),
                ],
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('帳號不存在時回 ACCT_NOT_FOUND')]
    public function test_get_balance_failed_account_not_found()
    {
        // Arrange
        $serialNo = time() . rand(10, 99);
        $this->account->delete();

        // Act
        $response = $this->sendRequest($serialNo);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::ACCT_NOT_FOUND->value,
                'msg' => 'Account not found',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-4：帳號存在但尚未綁定會員時，回調預設被擋下並回該遊戲商的錯誤碼；遊戲商宣告允許池帳號的回調（MotivationSW 的查玩家、查餘額）放行。 */
    #[Group('rules/single-wallet-callback-common-checks#R-4')]
    #[TestDox('帳號尚未綁定會員時回 ACCT_INACTIVE')]
    public function test_get_balance_failed_account_inactive()
    {
        // Arrange
        $serialNo = time() . rand(10, 99);
        $this->member->delete();

        // Act
        $response = $this->sendRequest($serialNo);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::ACCT_INACTIVE->value,
                'msg' => 'Account inactive',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-5：遊戲帳號停用或商戶停用時，查餘額照回餘額，不回錯誤；停用只在下注時擋。 */
    #[Group('rules/single-wallet-callback-common-checks#R-5')]
    #[TestDox('遊戲帳號 status=0 不擋查餘額：停用只擋下注，餘額照回')]
    public function test_get_balance_with_disabled_account_still_returns_balance()
    {
        $this->account->update(['status' => 0]);
        $this->member->update(['balance' => '1234.5678']);

        $response = $this->sendRequest(time() . rand(10, 99));

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'acctInfo' => ['balance' => Common::numMul('1234.5678', $this->vendorInfo->rate, 4)],
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('商戶不存在時回 MERCHANT_NOT_FOUND')]
    public function test_get_balance_failed_merchant_not_found()
    {
        // Arrange
        $serialNo = time() . rand(10, 99);
        $this->vendorInfo->delete();

        // Act
        $response = $this->sendRequest($serialNo);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::MERCHANT_NOT_FOUND->value,
                'msg' => 'Merchant Not Found',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-5：遊戲帳號停用或商戶停用時，查餘額照回餘額，不回錯誤；停用只在下注時擋。 */
    #[Group('rules/single-wallet-callback-common-checks#R-5')]
    #[TestDox('商戶 status=0 不擋查餘額：停用只擋下注，餘額照回')]
    public function test_get_balance_with_suspended_merchant_still_returns_balance()
    {
        // Arrange
        $serialNo = time() . rand(10, 99);
        $this->vendorInfo->update([
            'status' => 0,
        ]);

        // Act
        $response = $this->sendRequest($serialNo);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'serialNo' => $serialNo,
            ]);
    }

    private function sendRequest(string $serialNo)
    {
        $postData = [
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => $serialNo,
        ];
        $headers = [
            'DataType' => 'JSON',
            'API' => 'getBalance',
        ];

        return $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $headers);
    }
}
