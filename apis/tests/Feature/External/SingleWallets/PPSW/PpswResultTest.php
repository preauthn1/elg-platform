<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PpswBetRecord;
use App\Vendors\Models\PpswDealRecord;
use App\Vendors\PPSW\BetRecordStatusEnum;

/**
 * 驗證 PPSW result 端點行為：
 *   正常派彩、零贏結算、找不到 BetRecord、已結算冪等、重複 reference 冪等、含 promoWinAmount。
 *
 * php artisan test tests/Feature/External/SingleWallets/PPSW/PpswResultTest.php --compact
 */
class PpswResultTest extends PpswTestCase
{
    private string $roundId = 'result-round-001';

    private string $betAmount = '30.0';

    private string $betReference = 'result-bet-ref-001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->member->update(['balance' => '1000.0000']);

        // 每個測試前先建立一筆 bet
        $betPayload = $this->withHash([
            'amount' => $this->betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $this->betReference,
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]);
        $this->postJson("{$this->baseurl}/bet.html", $betPayload)
            ->assertJson(['error' => 0]);
    }

    /**
     * 正常派彩：balance 增加、BetRecord(status=SETTLE, win>0)、TotalBetRecord 建立。
     */
    public function test_result_credits_balance_and_creates_tbr(): void
    {
        $winAmount = '90.0';
        $balanceAfterBet = $this->member->fresh()->balance;

        $payload = $this->withHash([
            'amount' => $winAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'result-ref-001',
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/result.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        $this->assertEquals(1, TotalBetRecord::count(), 'result 後應建立 TotalBetRecord');

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::SETTLE, $betRecord->status);
        $this->assertEquals(Common::numAdd('0', $winAmount, 4), $betRecord->win);
        $expectedWinLose = Common::numSub(
            Common::numAdd('0', $winAmount, 4),
            Common::numAdd('0', $this->betAmount, 4),
            4
        );
        $this->assertEquals($expectedWinLose, $betRecord->win_lose);

        $this->member->refresh();
        $expectedBalance = Common::numAdd($balanceAfterBet, Common::numAdd('0', $winAmount, 4), 4);
        $this->assertEquals($expectedBalance, $this->member->balance);
    }

    /**
     * 零贏（輸局）：balance 不增加、BetRecord(status=SETTLE, win=0)、TotalBetRecord 建立。
     */
    public function test_result_zero_win_settles_without_crediting(): void
    {
        $balanceAfterBet = $this->member->fresh()->balance;

        $payload = $this->withHash([
            'amount' => '0.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'result-ref-zero',
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/result.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::SETTLE, $betRecord->status);
        $this->assertEquals('0.0000', $betRecord->win);
        $this->assertEquals(Common::numMul($this->betAmount, -1, 4), $betRecord->win_lose);

        $this->member->refresh();
        $this->assertEquals($balanceAfterBet, $this->member->balance, '零贏不應增加餘額');
    }

    /**
     * 找不到 BetRecord：直接呼叫 result 不帶對應 bet → error:120。
     */
    public function test_result_bet_record_not_found_returns_error(): void
    {
        $payload = $this->withHash([
            'amount' => '50.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'result-ref-no-bet',
            'roundDetails' => 'spin',
            'roundId' => 'non-existent-round-xyz',
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/result.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 120]);
    }

    /**
     * BetRecord 已結算後，以不同 reference 再次 result → error:0 冪等，TBR 不重複建立。
     */
    public function test_result_already_settled_is_idempotent(): void
    {
        // 第一次 result，結算
        $firstResult = $this->withHash([
            'amount' => '60.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'result-ref-settle-a',
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);
        $this->postJson("{$this->baseurl}/result.html", $firstResult)
            ->assertJson(['error' => 0]);

        $this->assertEquals(1, TotalBetRecord::count());
        $balanceAfterFirst = $this->member->fresh()->balance;

        // 第二次 result（不同 reference，相同 roundId，已是 SETTLE）
        $secondResult = $this->withHash([
            'amount' => '60.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'result-ref-settle-b',
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602600000',
            'userId' => $this->username,
        ]);
        $this->postJson("{$this->baseurl}/result.html", $secondResult)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $this->assertEquals(1, TotalBetRecord::count(), '已結算不應重複建立 TotalBetRecord');

        $this->member->refresh();
        $this->assertEquals($balanceAfterFirst, $this->member->balance, '已結算不應重複入帳');
    }

    /**
     * 完全相同 reference 重複 result → isDealRecordFinished 早期返回，資料不重複。
     */
    public function test_result_duplicate_reference_is_idempotent(): void
    {
        $payload = $this->withHash([
            'amount' => '45.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'result-ref-dup',
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/result.html", $payload)
            ->assertJson(['error' => 0]);

        $balanceAfterFirst = $this->member->fresh()->balance;

        $this->postJson("{$this->baseurl}/result.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $this->assertEquals(1, TotalBetRecord::count(), '重複 reference 不應重複建立 TBR');
        $this->assertEquals(2, PpswDealRecord::count(), 'deal_record: 1 bet + 1 result');

        $this->member->refresh();
        $this->assertEquals($balanceAfterFirst, $this->member->balance, '重複 reference 不應重複入帳');
    }

    /**
     * 含 promoWinAmount：balance 增加 amount + promoWinAmount，BetRecord.bonus 更新。
     */
    public function test_result_with_promo_win_amount_credits_extra(): void
    {
        $winAmount = '50.0';
        $promoAmount = '10.0';
        $balanceAfterBet = $this->member->fresh()->balance;

        $payload = $this->withHash([
            'amount' => $winAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'promoWinAmount' => $promoAmount,
            'reference' => 'result-ref-promo',
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);

        $this->postJson("{$this->baseurl}/result.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 0]);

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(Common::numAdd('0', $winAmount, 4), $betRecord->win);
        $this->assertEquals(Common::numAdd('0', $promoAmount, 4), $betRecord->bonus);

        $this->member->refresh();
        $totalCredit = Common::numAdd(
            Common::numAdd('0', $winAmount, 4),
            Common::numAdd('0', $promoAmount, 4),
            4
        );
        $expectedBalance = Common::numAdd($balanceAfterBet, $totalCredit, 4);
        $this->assertEquals($expectedBalance, $this->member->balance);
    }
}
