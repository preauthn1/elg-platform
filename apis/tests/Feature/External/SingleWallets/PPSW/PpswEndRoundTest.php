<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PpswBetRecord;
use App\Vendors\PPSW\BetRecordStatusEnum;

/**
 * 驗證 PPSW endRound 端點行為：
 *   狀態轉換、找不到 BetRecord、END_ROUND / SETTLE / REFUND 冪等性。
 *
 * php artisan test tests/Feature/External/SingleWallets/PPSW/PpswEndRoundTest.php --compact
 */
class PpswEndRoundTest extends PpswTestCase
{
    private string $roundId = 'end-round-001';

    private string $betAmount = '30.0';

    protected function setUp(): void
    {
        parent::setUp();
        $this->member->update(['balance' => '1000.0000']);
    }

    private function doBet(string $roundId, string $reference = 'end-bet-ref-001'): void
    {
        $this->postJson("{$this->baseurl}/bet.html", $this->withHash([
            'amount' => $this->betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $reference,
            'roundDetails' => 'spin',
            'roundId' => $roundId,
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);
    }

    private function doEndRound(string $roundId): void
    {
        $this->postJson("{$this->baseurl}/endRound.html", $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => $roundId,
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);
    }

    /**
     * 正常流程：bet → endRound → BetRecord(status=END_ROUND)，建立 TotalBetRecord。
     */
    public function test_end_round_transitions_to_end_round_and_creates_tbr(): void
    {
        $this->doBet($this->roundId);

        $this->postJson("{$this->baseurl}/endRound.html", $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => $this->roundId,
            'userId' => $this->username,
        ]))->assertStatus(200)->assertJson(['error' => 0, 'description' => 'Success']);

        $betRecord = PpswBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::END_ROUND, $betRecord->status);
        $this->assertNotNull($betRecord->payout_time);

        $this->assertEquals(1, TotalBetRecord::count(), 'endRound 應建立 TotalBetRecord');
        $tbr = TotalBetRecord::first();
        $this->assertEquals($betRecord->id, $tbr->bet_record_id);
        $this->assertEquals(Common::numAdd('0', $this->betAmount, 4), $tbr->bet);
    }

    /**
     * 找不到 BetRecord：回傳 error:120。
     */
    public function test_end_round_bet_record_not_found_returns_error(): void
    {
        $this->postJson("{$this->baseurl}/endRound.html", $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => 'non-existent-round-xyz',
            'userId' => $this->username,
        ]))->assertStatus(200)->assertJson(['error' => 120]);

        $this->assertEquals(0, TotalBetRecord::count());
    }

    /**
     * 已是 END_ROUND 狀態再次 endRound → error:0 冪等，TBR 不重複。
     */
    public function test_end_round_already_end_round_is_idempotent(): void
    {
        $this->doBet($this->roundId);
        $this->doEndRound($this->roundId);

        $this->assertEquals(1, TotalBetRecord::count());
        $this->assertEquals(BetRecordStatusEnum::END_ROUND, PpswBetRecord::first()->status);

        // 再次 endRound
        $this->postJson("{$this->baseurl}/endRound.html", $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => $this->roundId,
            'userId' => $this->username,
        ]))->assertStatus(200)->assertJson(['error' => 0]);

        $this->assertEquals(1, TotalBetRecord::count(), 'TBR 不應重複建立');
        $this->assertEquals(1, PpswBetRecord::count());
    }

    /**
     * 已是 SETTLE 狀態再次 endRound → error:0 冪等，資料不變動。
     */
    public function test_end_round_already_settled_is_idempotent(): void
    {
        $this->doBet($this->roundId);

        // 先以 result 結算
        $this->postJson("{$this->baseurl}/result.html", $this->withHash([
            'amount' => '60.0',
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => 'end-result-ref-001',
            'roundDetails' => 'spin',
            'roundId' => $this->roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);

        $this->assertEquals(BetRecordStatusEnum::SETTLE, PpswBetRecord::first()->status);
        $tbrCountAfterResult = TotalBetRecord::count();
        $balanceAfterResult = $this->member->fresh()->balance;

        // endRound on settled round
        $this->postJson("{$this->baseurl}/endRound.html", $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => $this->roundId,
            'userId' => $this->username,
        ]))->assertStatus(200)->assertJson(['error' => 0]);

        $this->assertEquals($tbrCountAfterResult, TotalBetRecord::count(), 'TBR 不應重複建立');
        $this->assertEquals(BetRecordStatusEnum::SETTLE, PpswBetRecord::first()->status, 'status 不應被改回');

        $this->member->refresh();
        $this->assertEquals($balanceAfterResult, $this->member->balance, '餘額不應再次變動');
    }

    /**
     * 已是 REFUND 狀態再次 endRound → error:0 冪等，資料不變動。
     */
    public function test_end_round_already_refunded_is_idempotent(): void
    {
        $betRef = 'end-bet-ref-refund';
        $this->doBet($this->roundId, $betRef);

        // roundId 在 refund 中為選填，但已向 PP 申請啟用，必須傳入（ppsw_deal_records.bet_id NOT NULL）
        $this->postJson("{$this->baseurl}/refund.html", $this->withHash([
            'providerId' => $this->providerId,
            'reference' => $betRef,
            'roundId' => $this->roundId,
            'userId' => $this->username,
        ]))->assertJson(['error' => 0]);

        $this->assertEquals(BetRecordStatusEnum::REFUND, PpswBetRecord::first()->status);
        $balanceAfterRefund = $this->member->fresh()->balance;

        // endRound on refunded round
        $this->postJson("{$this->baseurl}/endRound.html", $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => $this->roundId,
            'userId' => $this->username,
        ]))->assertStatus(200)->assertJson(['error' => 0]);

        $this->assertEquals(BetRecordStatusEnum::REFUND, PpswBetRecord::first()->status, 'status 不應被改回');

        $this->member->refresh();
        $this->assertEquals($balanceAfterRefund, $this->member->balance, '餘額不應再次變動');
    }
}
