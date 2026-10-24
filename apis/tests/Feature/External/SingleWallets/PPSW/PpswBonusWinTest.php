<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Helpers\Common;
use App\Vendors\Models\PpswBetRecord;

/**
 * 驗證 PPSW bonusWin / jackpotWin / promoWin 端點行為：
 *   三者邏輯相同（找 BetRecord → bonus 累加 → changeBalance → createTBR）。
 *   各自測試：happy path、冪等性、找不到 BetRecord；另驗證同一 round 多次 bonus 累加。
 *
 * php artisan test tests/Feature/External/SingleWallets/PPSW/PpswBonusWinTest.php --compact
 */
class PpswBonusWinTest extends PpswTestCase
{
    private string $roundId = 'bonus-round-001';

    private string $betAmount = '30.0';

    private string $betReference = 'bonus-bet-ref-001';

    protected function setUp(): void
    {
        parent::setUp();
        $this->member->update(['balance' => '1000.0000']);
    }

    private function doBet(string $roundId = '', string $reference = ''): void
    {
        $this->postJson("{$this->baseurl}/bet.html", $this->withHash([
            'amount' => $this->betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $reference ?: $this->betReference,
            'roundDetails' => 'spin',
            'roundId' => $roundId ?: $this->roundId,
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);
    }

    // ─────────────────────────────── bonusWin ───────────────────────────────

    /**
     * bonusWin happy path：balance 增加、BetRecord.bonus 累加、TBR 更新。
     */
    public function test_bonus_win_credits_balance_and_updates_bonus_field(): void
    {
        $this->doBet();
        $balanceAfterBet = $this->member->fresh()->balance;
        $bonusAmount = '20.0';

        $payload = $this->withHash([
            'amount' => $bonusAmount,
            'bonusCode' => 'FSB-001',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bonus-ref-001',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/bonusWin.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(Common::numAdd('0', $bonusAmount, 4), $betRecord->bonus);

        $this->member->refresh();
        $expectedBalance = Common::numAdd($balanceAfterBet, Common::numAdd('0', $bonusAmount, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance);
    }

    /**
     * bonusWin 找不到 BetRecord → error:120。
     */
    public function test_bonus_win_bet_record_not_found_returns_error(): void
    {
        $payload = $this->withHash([
            'amount' => '20.0',
            'bonusCode' => 'FSB-002',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bonus-ref-no-bet',
            'roundId' => 'non-existent-round-xyz',
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/bonusWin.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 120]);
    }

    /**
     * bonusWin 重複 reference → 冪等，balance 只加一次。
     */
    public function test_bonus_win_duplicate_reference_is_idempotent(): void
    {
        $this->doBet();
        $bonusAmount = '15.0';

        $payload = $this->withHash([
            'amount' => $bonusAmount,
            'bonusCode' => 'FSB-003',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bonus-ref-dup',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/bonusWin.html", $payload)
            ->assertJson(['error' => 0]);

        $balanceAfterFirst = $this->member->fresh()->balance;

        $this->postJson("{$this->baseurl}/bonusWin.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $this->member->refresh();
        $this->assertEquals($balanceAfterFirst, $this->member->balance, 'bonus 不應重複入帳');
        $this->assertEquals(
            Common::numAdd('0', $bonusAmount, 4),
            PpswBetRecord::first()->bonus,
            'BetRecord.bonus 不應重複累加'
        );
    }

    // ─────────────────────────────── jackpotWin ───────────────────────────────

    /**
     * jackpotWin happy path：balance 增加、BetRecord.bonus 累加。
     */
    public function test_jackpot_win_credits_balance_and_updates_bonus_field(): void
    {
        $this->doBet();
        $balanceAfterBet = $this->member->fresh()->balance;
        $jackpotAmount = '500.0';

        $payload = $this->withHash([
            'amount' => $jackpotAmount,
            'gameId' => $this->gameCode,
            'jackpotId' => 'jp-001',
            'providerId' => $this->providerId,
            'reference' => 'jackpot-ref-001',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/jackpotWin.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(Common::numAdd('0', $jackpotAmount, 4), $betRecord->bonus);

        $this->member->refresh();
        $expectedBalance = Common::numAdd($balanceAfterBet, Common::numAdd('0', $jackpotAmount, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance);
    }

    /**
     * jackpotWin 重複 reference → 冪等，balance 只加一次。
     */
    public function test_jackpot_win_duplicate_reference_is_idempotent(): void
    {
        $this->doBet();
        $jackpotAmount = '200.0';

        $payload = $this->withHash([
            'amount' => $jackpotAmount,
            'gameId' => $this->gameCode,
            'jackpotId' => 'jp-002',
            'providerId' => $this->providerId,
            'reference' => 'jackpot-ref-dup',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/jackpotWin.html", $payload)
            ->assertJson(['error' => 0]);

        $balanceAfterFirst = $this->member->fresh()->balance;

        $this->postJson("{$this->baseurl}/jackpotWin.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $this->member->refresh();
        $this->assertEquals($balanceAfterFirst, $this->member->balance, 'jackpot 不應重複入帳');
    }

    // ─────────────────────────────── promoWin ───────────────────────────────

    /**
     * promoWin happy path：balance 增加、BetRecord.bonus 累加。
     */
    public function test_promo_win_credits_balance_and_updates_bonus_field(): void
    {
        $this->doBet();
        $balanceAfterBet = $this->member->fresh()->balance;
        $promoAmount = '25.0';

        $payload = $this->withHash([
            'amount' => $promoAmount,
            'campaignId' => 'promo-campaign-001',
            'campaignType' => 'T',
            'currency' => 'CNY',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'promo-ref-001',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/promoWin.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(Common::numAdd('0', $promoAmount, 4), $betRecord->bonus);

        $this->member->refresh();
        $expectedBalance = Common::numAdd($balanceAfterBet, Common::numAdd('0', $promoAmount, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance);
    }

    /**
     * promoWin 重複 reference → 冪等，balance 只加一次。
     */
    public function test_promo_win_duplicate_reference_is_idempotent(): void
    {
        $this->doBet();
        $promoAmount = '10.0';

        $payload = $this->withHash([
            'amount' => $promoAmount,
            'campaignId' => 'promo-campaign-002',
            'campaignType' => 'M',
            'currency' => 'CNY',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'promo-ref-dup',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/promoWin.html", $payload)
            ->assertJson(['error' => 0]);

        $balanceAfterFirst = $this->member->fresh()->balance;

        $this->postJson("{$this->baseurl}/promoWin.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $this->member->refresh();
        $this->assertEquals($balanceAfterFirst, $this->member->balance, 'promo 不應重複入帳');
    }

    // ─────────────────────────────── 累加行為 ───────────────────────────────

    /**
     * 同一 round 兩次 bonusWin（不同 reference）→ bonus 欄位累加，非覆寫。
     */
    public function test_multiple_bonus_wins_on_same_round_accumulate(): void
    {
        $this->doBet();
        $balanceAfterBet = $this->member->fresh()->balance;

        $firstBonus = '10.0';
        $secondBonus = '20.0';

        $this->postJson("{$this->baseurl}/bonusWin.html", $this->withHash([
            'amount' => $firstBonus,
            'bonusCode' => 'FSB-acc-1',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bonus-acc-ref-001',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);

        $this->postJson("{$this->baseurl}/bonusWin.html", $this->withHash([
            'amount' => $secondBonus,
            'bonusCode' => 'FSB-acc-2',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'bonus-acc-ref-002',
            'roundId' => $this->roundId,
            'timestamp' => '1775602600000',
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);

        $betRecord = PpswBetRecord::first();
        $expectedBonus = Common::numAdd(
            Common::numAdd('0', $firstBonus, 4),
            Common::numAdd('0', $secondBonus, 4),
            4
        );
        $this->assertEquals($expectedBonus, $betRecord->bonus, 'bonus 應累加而非覆寫');

        $this->member->refresh();
        $expectedBalance = Common::numAdd($balanceAfterBet, $expectedBonus, 4);
        $this->assertEquals($expectedBalance, $this->member->balance);
    }
}
