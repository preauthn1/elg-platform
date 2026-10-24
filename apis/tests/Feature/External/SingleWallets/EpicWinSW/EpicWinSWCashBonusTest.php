<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\EpicWinSW;

use App\Helpers\Common;
use App\Vendors\WFSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class EpicWinSWCashBonusTest extends EpicWinSWTestCase
{
    private string $fn = 'CashBonus';

    private string $currentToken = [REDACTED]';

    private function bonusParams(array $overrides = []): array
    {
        $post = $this->baseParams(array_merge([
            'TranId' => $this->uniqueId(),
            'BonusId' => 'BONUS_1',
            'BonusName' => 'Cash Bonus',
            'Payout' => 200,
            'ExchangeRate' => 1,
            'Result' => 'win',
            'TranDateTime' => $this->requestDateTime,
        ], $overrides));
        $this->regenerateSignature($this->fn, $post);

        return $post;
    }

    #[TestDox('CashBonus：派彩入帳並建立注單與 total_bet_record')]
    #[Group('rules/vendor-epicwin#R-13')]
    public function test_cash_bonus_credits_balance_and_creates_records(): void
    {
        // Arrange
        $balance = 500;
        $this->member->update(['balance' => $balance]);

        $payout = 200;
        $tranId = $this->uniqueId();
        $post = $this->bonusParams(['TranId' => $tranId, 'Payout' => $payout]);

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert：派彩 Payout/rate 進真金錢包
        $platformWin = Common::numDiv($payout, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($balance, $platformWin, 4);

        $response->assertStatus(200)
            ->assertJson([
                'Status' => OperatorStatusEnum::OK->value,
                'NewBalance' => (float) Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($expectedBalance, $this->member->balance);

        $this->assertDatabaseHas('epic_sw_bet_records', [
            'bet_id' => $tranId,
            'type' => 'B',
            'status' => 1,
        ]);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'member_id' => $this->member->id,
        ]);
    }

    #[TestDox('CashBonus：TranId 重複時回 DUPLICATE_TRANSACTION 且不派彩')]
    #[Group('rules/vendor-epicwin#R-13')]
    public function test_cash_bonus_duplicate_transaction(): void
    {
        // Arrange
        $this->member->update(['balance' => 500]);
        $tranId = $this->uniqueId();
        $this->createBetRecord($tranId, 0, status: 1, type: 'B');

        $post = $this->bonusParams(['TranId' => $tranId]);

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::DUPLICATE_TRANSACTION->value]);

        $this->assertEquals(500, $this->member->balance);
    }

    #[TestDox('CashBonus：簽名錯誤時回 INVALID_SIGNATURE')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_cash_bonus_invalid_signature(): void
    {
        // Arrange
        $post = $this->bonusParams();
        $post['Signature'] = 'invalidSignature';

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_SIGNATURE->value]);
    }

    // --- AuthToken（session 綁定）行為，特性化現況 ---
    // checkAuthToken 在 callAction、方法執行前驗證：未帶 token → 放行；帶 token（含空字串）
    // 但與 account.session_token 不符 → 先回 INVALID_PLAYER（不派彩、不建注單）。
    // 此為比 API_External 嚴格的分歧（API_External 僅對 GetBalance/Bet 驗 token）；
    // 若日後將 token 驗證限縮到 GetBalance/Bet，下列 stale 案例會翻成放行，需更新。

    #[TestDox('CashBonus：帶過期 AuthToken 時回 INVALID_PLAYER 且不派彩、不建注單')]
    #[Group('rules/vendor-epicwin#R-3')]
    public function test_cash_bonus_with_stale_authtoken_is_rejected_and_no_credit(): void
    {
        $this->account->update(['session_token' => $this->currentToken]);
        $balance = 500;
        $this->member->update(['balance' => $balance]);

        $tranId = $this->uniqueId();
        $post = $this->bonusParams(['TranId' => $tranId, 'AuthToken' => 'STALE_TOKEN']);

        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_PLAYER->value]);

        $this->assertEquals($balance, $this->member->balance);
        $this->assertDatabaseMissing('epic_sw_bet_records', ['bet_id' => $tranId]);
    }

    #[TestDox('CashBonus：帶相符 AuthToken 時通過並正常派彩')]
    #[Group('rules/vendor-epicwin#R-3')]
    public function test_cash_bonus_with_matching_authtoken_passes(): void
    {
        $this->account->update(['session_token' => $this->currentToken]);
        $balance = 500;
        $this->member->update(['balance' => $balance]);

        $payout = 200;
        $tranId = $this->uniqueId();
        $post = $this->bonusParams(['TranId' => $tranId, 'Payout' => $payout, 'AuthToken' => $this->currentToken]);

        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        $expectedBalance = Common::numAdd($balance, Common::numDiv($payout, $this->vendorInfo->rate, 4), 4);

        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals($expectedBalance, $this->member->balance);
        $this->assertDatabaseHas('epic_sw_bet_records', ['bet_id' => $tranId, 'type' => 'B', 'status' => 1]);
    }

    #[TestDox('CashBonus：未帶 AuthToken（帳號 session_token 非空）時放行並正常派彩')]
    #[Group('rules/vendor-epicwin#R-3')]
    public function test_cash_bonus_without_authtoken_passes(): void
    {
        $this->account->update(['session_token' => $this->currentToken]);
        $balance = 500;
        $this->member->update(['balance' => $balance]);

        $payout = 200;
        $tranId = $this->uniqueId();
        $post = $this->bonusParams(['TranId' => $tranId, 'Payout' => $payout]);
        unset($post['AuthToken']);

        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        $expectedBalance = Common::numAdd($balance, Common::numDiv($payout, $this->vendorInfo->rate, 4), 4);

        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        $this->assertEquals($expectedBalance, $this->member->balance);
    }
}
