<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\EpicWinSW;

use App\Helpers\Common;
use App\Vendors\Models\EpicSwBetRecord;
use App\Vendors\WFSW\OperatorStatusEnum;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\TestDox;

class EpicWinSWGameResultTest extends EpicWinSWTestCase
{
    private string $fn = 'GameResult';

    private string $currentToken = [REDACTED]';

    private function settleParams(string $betId, array $overrides = []): array
    {
        $post = $this->baseParams(array_merge([
            'BetId' => $betId,
            'ResultId' => $this->uniqueId(),
            'GameCode' => $this->gameCode,
            'GameType' => 'Slot',
            'GameName' => 'Test Game',
            'ResultType' => 1,
            'BetAmount' => 100,
            'ValidBetAmount' => 100,
            'Payout' => 100,
            'WinLose' => 0,
            'ExchangeRate' => 1,
            'TranDateTime' => $this->requestDateTime,
        ], $overrides));
        $this->regenerateSignature($this->fn, $post);

        return $post;
    }

    #[TestDox('GameResult：先下注再結算，淨變動等於 WinLose/匯率且注單標記為已結算')]
    #[Group('rules/vendor-epicwin#R-9')]
    public function test_settle_after_bet_applies_win_lose_and_settles_record(): void
    {
        // Arrange
        $balance = 1000;
        $this->member->update(['balance' => $balance]);

        $betAmount = 100;
        $betId = $this->uniqueId();
        $this->placeBet($betId, $betAmount);

        // Act：玩家贏 WinLose
        $winLose = 50;
        $resultId = $this->uniqueId();
        $post = $this->settleParams($betId, [
            'ResultId' => $resultId,
            'BetAmount' => $betAmount,
            'ValidBetAmount' => $betAmount,
            'Payout' => $betAmount + $winLose,
            'WinLose' => $winLose,
        ]);

        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert：bet 扣 100、settle 回 150，淨 +50 = WinLose/rate
        $expectedBalance = Common::numAdd($balance, Common::numDiv($winLose, $this->vendorInfo->rate, 4), 4);

        $response->assertStatus(200)
            ->assertJson([
                'Status' => OperatorStatusEnum::OK->value,
                'NewBalance' => (float) Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            ]);

        $this->assertEquals($expectedBalance, $this->member->balance);

        $this->assertDatabaseHas('epic_sw_bet_records', [
            'bet_id' => $betId,
            'result_id' => $resultId,
            'status' => 1,
        ]);

        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'member_id' => $this->member->id,
        ]);
    }

    #[TestDox('GameResult：查無對應注單時回 BET_TRANSACTION_NOT_FOUND')]
    #[Group('rules/vendor-epicwin#R-8')]
    public function test_settle_bet_transaction_not_found(): void
    {
        // Arrange
        $this->member->update(['balance' => 1000]);

        $post = $this->settleParams($this->uniqueId());

        // Act
        $response = $this->callEndpoint($this->fn, $post);
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value]);

        $this->assertEquals(1000, $this->member->balance);
    }

    #[TestDox('GameResult：簽名錯誤時回 INVALID_SIGNATURE')]
    #[Group('rules/vendor-epicwin#R-2')]
    public function test_settle_invalid_signature(): void
    {
        // Arrange
        $post = $this->settleParams($this->uniqueId());
        $post['Signature'] = 'invalidSignature';

        // Act
        $response = $this->callEndpoint($this->fn, $post);

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_SIGNATURE->value]);
    }

    #[TestDox('GameResult：重送相同結算為冪等（餘額不變、注單仍為同一筆、不新增 total_bet_record）')]
    #[Group('rules/vendor-epicwin#R-10')]
    public function test_resending_settle_is_idempotent(): void
    {
        // Arrange：先下注，再結算一次
        $this->member->update(['balance' => 1000]);
        $betId = $this->uniqueId();
        $this->placeBet($betId);

        $settle = $this->settleParams($betId, ['Payout' => 150, 'WinLose' => 50]);

        $this->callEndpoint($this->fn, $settle)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);
        $this->member->refresh();

        $balanceAfterFirst = $this->member->balance;
        $tbrAfterFirst = $this->totalBetRecordCount();
        // 結算後該 BetId 應只有「一筆」注單，記下它的 id
        $this->assertSame(1, EpicSwBetRecord::where('bet_id', $betId)->count(), '結算後該注單應只有一筆');
        $betRecordIdAfterFirst = EpicSwBetRecord::where('bet_id', $betId)->value('id');

        // Act：原樣重送同一筆 GameResult（相同 BetId / ResultId / 簽名）
        $response = $this->callEndpoint($this->fn, $settle);
        $this->member->refresh();

        // Assert：重送為冪等 —— 餘額不再變、不新增 total_bet_records，
        // 且注單仍是「同一筆」（clone+save 走 UPDATE，不會 INSERT 新列）
        $response->assertStatus(200);
        $this->assertEquals($balanceAfterFirst, $this->member->balance, '重送結算不應再次改變餘額');
        $this->assertSame($tbrAfterFirst, $this->totalBetRecordCount(), '重送結算不應新增 total_bet_records');
        $this->assertSame(1, EpicSwBetRecord::where('bet_id', $betId)->count(), '重送結算不應新增注單（仍只有一筆）');
        $this->assertSame(
            $betRecordIdAfterFirst,
            EpicSwBetRecord::where('bet_id', $betId)->value('id'),
            '重送結算更新的是同一筆注單（id 不變）'
        );
    }

    #[TestDox('GameResult：同一 ResultId 跨午夜補送（RoundType=1）不覆寫 payout_time，注單仍歸原結算日')]
    #[Group('rules/vendor-epicwin#R-10')]
    public function test_resent_result_after_midnight_keeps_original_payout_time(): void
    {
        // Arrange：SGT 10/7 23:02 下注並結算（UTC 15:02）
        $this->member->update(['balance' => 1000]);
        $betId = $this->uniqueId();
        $resultId = $this->uniqueId();
        $this->placeBet($betId, 1);

        $firstSettledAt = '2026-10-07 15:02:34';
        $this->callEndpoint($this->fn, $this->settleParams($betId, [
            'ResultId' => $resultId,
            'BetAmount' => 1,
            'ValidBetAmount' => 1,
            'Payout' => 0,
            'WinLose' => -1,
            'RoundType' => 0,
            'TranDateTime' => $firstSettledAt,
        ]))->assertJson(['Status' => OperatorStatusEnum::OK->value]);
        $this->member->refresh();
        $balanceAfterFirst = $this->member->balance;

        // Act：SGT 10/8 00:00 三方以同一個 ResultId 補送 RoundType=1
        $response = $this->callEndpoint($this->fn, $this->settleParams($betId, [
            'ResultId' => $resultId,
            'BetAmount' => 1,
            'ValidBetAmount' => 1,
            'Payout' => 0,
            'WinLose' => -1,
            'RoundType' => 1,
            'TranDateTime' => '2026-10-07 16:00:23',
        ]));
        $this->member->refresh();

        // Assert
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);
        $this->assertEquals($balanceAfterFirst, $this->member->balance, '補送不應改變餘額');

        $betRecord = EpicSwBetRecord::where('bet_id', $betId)->firstOrFail();
        $this->assertSame($firstSettledAt, $betRecord->getRawOriginal('payout_time'), '補送不可覆寫注單的 payout_time');
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'bet_record_id' => $betRecord->id,
            'payout_time' => $firstSettledAt,
        ]);
    }

    #[TestDox('GameResult：已結算後三方調整輸贏，金額與餘額照新結果更新，但 payout_time 仍保留首次結算時間')]
    #[Group('rules/vendor-epicwin#R-10')]
    public function test_resent_result_with_adjusted_amount_updates_amount_but_keeps_payout_time(): void
    {
        // Arrange：下注 10、首次結算輸 10
        $balance = 1000;
        $this->member->update(['balance' => $balance]);
        $betId = $this->uniqueId();
        $resultId = $this->uniqueId();
        $this->placeBet($betId, 10);

        $firstSettledAt = '2026-10-07 15:02:34';
        $this->callEndpoint($this->fn, $this->settleParams($betId, [
            'ResultId' => $resultId,
            'BetAmount' => 10,
            'ValidBetAmount' => 10,
            'Payout' => 0,
            'WinLose' => -10,
            'TranDateTime' => $firstSettledAt,
        ]))->assertJson(['Status' => OperatorStatusEnum::OK->value]);

        // Act：跨午夜補送，輸贏調整為贏 5
        $response = $this->callEndpoint($this->fn, $this->settleParams($betId, [
            'ResultId' => $resultId,
            'BetAmount' => 10,
            'ValidBetAmount' => 10,
            'Payout' => 15,
            'WinLose' => 5,
            'TranDateTime' => '2026-10-07 16:00:23',
        ]));
        $this->member->refresh();

        // Assert：餘額以最終結果計算（1000 + 5），payout_time 不動
        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::OK->value]);
        $expectedBalance = Common::numAdd($balance, Common::numDiv(5, $this->vendorInfo->rate, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance, '調整後的輸贏必須反映到餘額');

        $betRecord = EpicSwBetRecord::where('bet_id', $betId)->firstOrFail();
        $this->assertEquals(Common::numDiv(5, $this->vendorInfo->rate, 4), $betRecord->win_lose, '注單輸贏必須更新為調整後的值');
        $this->assertSame($firstSettledAt, $betRecord->getRawOriginal('payout_time'), '調整結果不可覆寫 payout_time');
        $this->assertDatabaseHas('total_bet_records', [
            'vendor_id' => $this->vendor->id,
            'bet_record_id' => $betRecord->id,
            'payout_time' => $firstSettledAt,
        ]);
    }

    // --- AuthToken（session 綁定）行為，特性化現況 ---
    // checkAuthToken 在 callAction、方法執行前驗證：未帶 token → 放行；帶 token（含空字串）
    // 但與 account.session_token 不符 → 先回 INVALID_PLAYER（不會進到 settle 的 BET_TRANSACTION_NOT_FOUND）。
    // 此為比 API_External 嚴格的分歧（API_External 僅對 GetBalance/Bet 驗 token）；
    // 若日後將 token 驗證限縮到 GetBalance/Bet，下列 stale/empty 案例會翻成放行，需更新。

    #[TestDox('GameResult：未帶 AuthToken 時通過 token gate（查無注單回 BET_TRANSACTION_NOT_FOUND）')]
    #[Group('rules/vendor-epicwin#R-3')]
    #[Group('rules/vendor-epicwin#R-8')]
    public function test_settle_without_authtoken_passes_token_gate(): void
    {
        $this->account->update(['session_token' => $this->currentToken]);
        $this->member->update(['balance' => 1000]);

        $post = $this->settleParams($this->uniqueId());
        unset($post['AuthToken']);

        $response = $this->callEndpoint($this->fn, $post);

        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::BET_TRANSACTION_NOT_FOUND->value]);
    }

    #[TestDox('GameResult：帶過期 AuthToken 時於方法前被擋回 INVALID_PLAYER')]
    #[Group('rules/vendor-epicwin#R-3')]
    public function test_settle_with_stale_authtoken_is_rejected(): void
    {
        $this->account->update(['session_token' => $this->currentToken]);
        $this->member->update(['balance' => 1000]);

        $post = $this->settleParams($this->uniqueId(), ['AuthToken' => 'STALE_TOKEN']);

        $response = $this->callEndpoint($this->fn, $post);

        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_PLAYER->value]);
    }

    #[TestDox('GameResult：帶空字串 AuthToken 但帳號 session_token 非空時回 INVALID_PLAYER')]
    #[Group('rules/vendor-epicwin#R-3')]
    public function test_settle_with_empty_authtoken_is_rejected_when_session_token_set(): void
    {
        $this->account->update(['session_token' => $this->currentToken]);
        $this->member->update(['balance' => 1000]);

        $post = $this->settleParams($this->uniqueId(), ['AuthToken' => '']);

        $response = $this->callEndpoint($this->fn, $post);

        $response->assertStatus(200)
            ->assertJson(['Status' => OperatorStatusEnum::INVALID_PLAYER->value]);
    }
}
