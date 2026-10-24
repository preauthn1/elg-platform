<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Enums\Vendor\VendorIdEnum;
use App\Vendors\Models\SpadegamingswBetRecord;
use App\Vendors\SpadegamingSW\Enums\ReturnStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * Transfer API 前置驗證測試（api header = transfer）
 *
 * 驗證在各種異常輸入或系統狀態下，API 能回傳正確的錯誤碼。
 * 測試分為兩個層次：
 *
 * 【BaseController::callAction() 前置檢查攔截】（與 API 類型無關）
 * - 帳號不存在（account 被刪除）→ ACCT_NOT_FOUND
 * - 會員不存在（member 被刪除）→ ACCT_INACTIVE
 * - 遊戲帳號停用（account.status=0）→ ACCT_INACTIVE
 * - VendorInfo 不存在（vendorInfo 被刪除）→ MERCHANT_NOT_FOUND
 * - VendorInfo 屬於別家遊戲商 → MERCHANT_NOT_FOUND
 * - VendorInfo 停用（status=0）→ MERCHANT_SUSPEND
 *
 * 【TransferService::validateData() 業務驗證層攔截】
 * - 不合法的 type（不在 1/2/4/7 之中）→ SERVICE_INACCESSIBLE
 * - 金額為負數 → AMOUNT_INVALID
 * - 遊戲不存在（game 被刪除）→ INVALID_REQUEST
 * - 會員狀態停用（member.status=0）→ ACCT_SUSPEND
 */
class TransferRequestErrorTest extends SpadegamingSWTestCase
{
    private $defaultPostData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultPostData = [
            'transferId' => '20240801142953555117852',
            'type' => 1,
            'currency' => 'MYR',
            'gameCode' => $this->game->game_type,
            'channel' => 'Mobile',
            'amount' => 300,
            'serialNo' => $this->defaultSerialNo,
            'acctId' => $this->acctId,
            'merchantCode' => $this->merchantCode,
            'transferTime' => date('Y-m-d H:i:s'),
        ];
    }

    /** R-6：轉帳回調的金額為負數時回錯誤碼 50113；遊戲代碼在我方不存在時回錯誤碼 2；轉帳類型不是下注、取消、派彩、紅利之一時回錯誤碼 3。三種情況都不動錢包。 */
    #[Group('rules/vendor-spadegamingsw#R-6')]
    #[TestDox('轉帳類型不合法時回 SERVICE_INACCESSIBLE')]
    public function test_transfer_failed_invalid_type()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $postData['type'] = 10;

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::SERVICE_INACCESSIBLE->value,
                'msg' => 'Transfer type 10 not available',
            ]);
    }

    /** R-6：轉帳回調的金額為負數時回錯誤碼 50113；遊戲代碼在我方不存在時回錯誤碼 2；轉帳類型不是下注、取消、派彩、紅利之一時回錯誤碼 3。三種情況都不動錢包。 */
    #[Group('rules/vendor-spadegamingsw#R-6')]
    #[TestDox('金額為負數時回 AMOUNT_INVALID')]
    public function test_transfer_failed_amount_invalid()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $postData['amount'] = -1;

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::AMOUNT_INVALID->value,
                'msg' => 'Amount Invalid',
            ]);
    }

    /** R-6：轉帳回調的金額為負數時回錯誤碼 50113；遊戲代碼在我方不存在時回錯誤碼 2；轉帳類型不是下注、取消、派彩、紅利之一時回錯誤碼 3。三種情況都不動錢包。 */
    #[Group('rules/vendor-spadegamingsw#R-6')]
    #[TestDox('遊戲代碼不存在時回 INVALID_REQUEST')]
    public function test_transfer_failed_game_not_exists()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $this->game->delete();

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::INVALID_REQUEST->value,
                'msg' => 'Game not exist',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('帳號不存在時回 ACCT_NOT_FOUND')]
    public function test_transfer_failed_account_not_found()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $this->account->delete();

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

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
    public function test_transfer_failed_account_inactive()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $this->member->delete();

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::ACCT_INACTIVE->value,
                'msg' => 'Account inactive',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-6：下注時遊戲帳號停用、商戶停用、或會員不具下注資格（例如會員停用、鎖分模式），擋下並回該遊戲商的錯誤碼，不建注單、不扣款。 */
    #[Group('rules/single-wallet-callback-common-checks#R-6')]
    #[TestDox('遊戲帳號 status=0 時下注被 verifyBettability 擋下回 ACCT_SUSPEND，不寫注單也不動錢包')]
    public function test_transfer_failed_account_disabled()
    {
        $this->account->update(['status' => 0]);
        $balanceBefore = $this->member->fresh()->balance;

        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->defaultPostData, $this->headers);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::ACCT_SUSPEND->value,
                'msg' => 'Account could not bet yet',
            ]);
        $this->assertDatabaseMissing(SpadegamingswBetRecord::class, ['bet_id' => $this->defaultPostData['transferId']]);
        $this->assertSame($balanceBefore, $this->member->fresh()->balance);
    }

    /** rules/single-wallet-callback-common-checks#R-6：下注時遊戲帳號停用、商戶停用、或會員不具下注資格（例如會員停用、鎖分模式），擋下並回該遊戲商的錯誤碼，不建注單、不扣款。 */
    #[Group('rules/single-wallet-callback-common-checks#R-6')]
    #[TestDox('會員停用時下注回 ACCT_SUSPEND')]
    public function test_transfer_failed_could_not_bet()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $this->member->update([
            'status' => 0,
        ]);

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::ACCT_SUSPEND->value,
                'msg' => 'Account could not bet yet',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('商戶不存在時回 MERCHANT_NOT_FOUND')]
    public function test_transfer_failed_merchant_not_found()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $this->vendorInfo->delete();

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::MERCHANT_NOT_FOUND->value,
                'msg' => 'Merchant Not Found',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('帳號的 vendor_info 屬於別家遊戲商時回 MERCHANT_NOT_FOUND，不拿別家的設定算錢')]
    public function test_transfer_failed_when_vendor_info_belongs_to_another_vendor()
    {
        // 歸屬由 BaseController 的 resolveVendorInfo() 一律驗，掛了 ShouldResolveVendorAccount 就生效。
        $this->vendorInfo->update(['vendor_id' => VendorIdEnum::UUSLOTSW->value]);

        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $this->defaultPostData, $this->headers);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::MERCHANT_NOT_FOUND->value,
                'msg' => 'Merchant Not Found',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-6：下注時遊戲帳號停用、商戶停用、或會員不具下注資格（例如會員停用、鎖分模式），擋下並回該遊戲商的錯誤碼，不建注單、不扣款。 */
    #[Group('rules/single-wallet-callback-common-checks#R-6')]
    #[TestDox('商戶 status=0 時下注被 verifyBettability 擋下回 ACCT_SUSPEND（解析層不擋商戶停用）')]
    public function test_transfer_failed_merchant_suspend()
    {
        // Arrange
        $postData = $this->defaultPostData;
        $this->vendorInfo->update([
            'status' => 0,
        ]);

        // Act
        $response = $this->postJson(self::API_SPADEGAMINGSW_ENDPOINT, $postData, $this->headers);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::ACCT_SUSPEND->value,
                'msg' => 'Account could not bet yet',
            ]);
    }
}
