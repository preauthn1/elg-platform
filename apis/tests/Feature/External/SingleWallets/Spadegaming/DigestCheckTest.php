<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Spadegaming;

use App\Vendors\SpadegamingSW\Enums\ReturnStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

/**
 * SpadegamingSW digest 驗證（現位於 `SpadegamingSWController::callAction()`）
 *
 * 這組案例自己算 digest 送出，不吃基底的自動簽章，所以驗簽失敗那支才測得到。
 * 它守的是一段真的消失過的邏輯：中介層時期帳號查詢被改成向容器要 SpadegamingswAccount，
 * 但沒有人綁定該類別，每次請求都拿到全新空 model，vendorInfo 必為 null，所有回調一律被擋成
 * MERCHANT_NOT_FOUND（10113）。當時其餘測試全部停用 middleware，察覺不到。
 */
class DigestCheckTest extends SpadegamingSWTestCase
{
    /** rules/single-wallet-callback-common-checks#R-2：回調的簽章或 token 與我方用商戶設定算出的值不符時，擋下並回該遊戲商的錯誤碼，不動錢包、不寫注單。 */
    #[Group('rules/single-wallet-callback-common-checks#R-2')]
    #[TestDox('digest 正確時放行請求，不再被誤判為 Merchant not found')]
    public function test_valid_digest_passes_through(): void
    {
        $response = $this->sendRequest($this->acctId, $this->securityKey);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::SUCCESS->value,
                'acctInfo' => [
                    'acctId' => $this->acctId,
                ],
            ]);
    }

    /** R-7：查餘額時回會員真金加禮碼的合計再乘以匯率。 */
    #[Group('rules/vendor-spadegamingsw#R-7')]
    #[TestDox('digest 正確時解析到的是 acctId 對應的那個帳號，回傳該會員的餘額')]
    public function test_request_resolves_the_matching_account(): void
    {
        $this->member->update(['balance' => '123.4500']);

        $this->sendRequest($this->acctId, $this->securityKey)
            ->assertJson([
                'code' => ReturnStatusEnum::SUCCESS->value,
                'acctInfo' => [
                    'acctId' => $this->acctId,
                    // 基底的 rate 是 2.0
                    'balance' => 246.9,
                ],
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('acctId 查無帳號時回 ACCT_NOT_FOUND')]
    public function test_unknown_account_is_rejected(): void
    {
        $response = $this->sendRequest('81_SGSW99999999', $this->securityKey);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::ACCT_NOT_FOUND->value,
                'msg' => 'Account not found',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-3：回調帶的玩家帳號在我方不存在、帳號所屬商戶不存在、或帳號屬於別家遊戲商的商戶時，擋下並回該遊戲商的錯誤碼，不會拿別家商戶的設定算錢。 */
    #[Group('rules/single-wallet-callback-common-checks#R-3')]
    #[TestDox('帳號查得到但 vendorInfo 不存在時回 MERCHANT_NOT_FOUND')]
    public function test_missing_vendor_info_is_rejected(): void
    {
        $this->vendorInfo->delete();

        $response = $this->sendRequest($this->acctId, $this->securityKey);

        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::MERCHANT_NOT_FOUND->value,
                'msg' => 'Merchant Not Found',
            ]);
    }

    /** rules/single-wallet-callback-common-checks#R-2：回調的簽章或 token 與我方用商戶設定算出的值不符時，擋下並回該遊戲商的錯誤碼，不動錢包、不寫注單。 */
    #[Group('rules/single-wallet-callback-common-checks#R-2')]
    #[TestDox('digest 與 Security_key 算出來的值不符時回 INVALID_REQUEST')]
    public function test_wrong_digest_is_rejected(): void
    {
        $response = $this->sendRequest($this->acctId, 'wrong-security-key');

        $response->assertStatus(200)
            ->assertJson([
                'code' => ReturnStatusEnum::INVALID_REQUEST->value,
                'msg' => 'Digest verify failed',
            ]);
    }

    /**
     * 以 $secKey 簽出 digest 後送出 getBalance 請求。
     *
     * digest 的算法與 controller 的 verifyDigest() 一致：md5(正規化後的 request body . Security_key)。
     */
    private function sendRequest(string $acctId, string $secKey)
    {
        $postData = [
            'acctId' => $acctId,
            'merchantCode' => $this->merchantCode,
            'serialNo' => time() . rand(10, 99),
        ];

        $content = json_encode($postData);

        return $this->withHeaders([
            'DataType' => 'JSON',
            'API' => 'getBalance',
            'digest' => md5(json_encode(json_decode($content)) . $secKey),
        ])->json('POST', self::API_SPADEGAMINGSW_ENDPOINT, $postData);
    }
}
