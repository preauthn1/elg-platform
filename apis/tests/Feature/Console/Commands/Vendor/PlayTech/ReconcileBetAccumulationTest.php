<?php

declare(strict_types=1);

namespace Tests\Feature\Console\Commands\Vendor\PlayTech;

use App\Enums\BetRecordRecoveryTypeEnum;
use App\Enums\Vendor\VendorIdEnum;
use App\Models\BetRecordRecoveryLog;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PtswBetRecord;
use PHPUnit\Framework\Attributes\Group;

/**
 * php artisan test tests/Feature/Console/Commands/Vendor/PlayTech/ReconcileBetAccumulationTest.php --compact
 */
class ReconcileBetAccumulationTest extends ReconcileBetAccumulationTestCase
{
    /**
     * 假設：
     *   - BR.bet = 30（被覆寫的最後一手）
     *   - deal_records.bet 兩筆共 60（真實累計）
     *   - 對應 TBR.valid_bet = 30
     *
     * 預期執行後：
     *   - BR.bet = BR.valid_bet = 60
     *   - TBR.bet = TBR.valid_bet = 60
     *   - BR.win_lose 不變（淨輸贏對齊）
     *   - recovery_logs 1 筆 type=recover, balance_diff=0
     */
    public function test_execute_updates_br_tbr_and_writes_recovery_log_with_zero_balance_diff(): void
    {
        $betRecordId = $this->seedAffectedRecords(currentBet: '30', expectedBet: '60');

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
            '--execute' => true,
            '--reason' => 'unit-test fix',
        ])->assertSuccessful();

        $br = PtswBetRecord::findOrFail($betRecordId);
        $this->assertEquals('60.0000', $br->bet);
        $this->assertEquals('60.0000', $br->valid_bet);
        $this->assertEquals('-30.0000', $br->win_lose, 'win_lose 不應被本補帳碰到');

        $tbr = TotalBetRecord::where('bet_record_id', $betRecordId)->firstOrFail();
        $this->assertEquals('60.0000', $tbr->bet);
        $this->assertEquals('60.0000', $tbr->valid_bet);

        $log = BetRecordRecoveryLog::where('vendor_id', VendorIdEnum::PTSW->value)
            ->where('bet_record_id', $betRecordId)
            ->firstOrFail();
        $this->assertSame(BetRecordRecoveryTypeEnum::RECOVER, $log->type);
        $this->assertEquals('0.0000', $log->balance_diff);
        $this->assertSame('unit-test fix', $log->reason);
        $this->assertEquals('30.0000', $log->before['br']['bet']);
        $this->assertEquals('60.0000', $log->after['br']['bet']);
    }

    public function test_dry_run_does_not_write_anything(): void
    {
        $betRecordId = $this->seedAffectedRecords(currentBet: '30', expectedBet: '60');

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
        ])->assertSuccessful();

        $br = PtswBetRecord::findOrFail($betRecordId);
        $this->assertEquals('30.0000', $br->bet, 'dry-run 不應改 BR.bet');
        $this->assertEquals('30.0000', $br->valid_bet);

        $tbr = TotalBetRecord::where('bet_record_id', $betRecordId)->firstOrFail();
        $this->assertEquals('30.0000', $tbr->valid_bet, 'dry-run 不應改 TBR');

        $this->assertSame(0, BetRecordRecoveryLog::count(), 'dry-run 不應寫 recovery_logs');
    }

    public function test_idempotent_second_execute_does_not_double_recover(): void
    {
        $betRecordId = $this->seedAffectedRecords(currentBet: '30', expectedBet: '60');

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
            '--execute' => true,
            '--reason' => 'first run',
        ])->assertSuccessful();

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
            '--execute' => true,
            '--reason' => 'second run',
        ])->assertSuccessful();

        $this->assertSame(
            1,
            BetRecordRecoveryLog::count(),
            '第二次 execute 不應重複寫 recovery_logs（冪等檢查 isCurrentlyRecovered）',
        );
    }

    /**
     * Refund 場景：placeBet (type='bet', +20) + gameRoundResult (type='gameRoundResult', -20)
     * 兩筆 deal_records 加總 = 0，BR.bet 應維持 0，reconcile 不應把它列為「漏記」。
     *
     * 對應現場：PlayTechController createDealRecord('gameRoundResult') 在 REFUND 路徑
     * 會寫 bet_amount = -placeBet金額 作為沖銷事件。原本 SQL `where type='bet'`
     * 漏算這筆沖銷，會把 refund 注單的 BR.bet 補成 placeBet 金額（過頭）。
     */
    public function test_refund_round_with_offsetting_deal_record_is_not_treated_as_missing(): void
    {
        $betRecordId = $this->seedRefundRound(placeBetAmount: '20', currentBet: '0');

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
            '--execute' => true,
            '--reason' => 'refund round should be no-op',
        ])->assertSuccessful();

        $br = PtswBetRecord::findOrFail($betRecordId);
        $this->assertEquals('0.0000', $br->bet, 'refund 注單的 BR.bet 應維持 0，不應被補成 placeBet 金額');
        $this->assertSame(0, BetRecordRecoveryLog::count(), '已 net 為 0 的 refund 注單不應產生 recovery_log');
    }

    /**
     * 被 settlement 拒絕的 REFUND 留下 raw 帶 `_rejected` 的 gameRoundResult 事件（bet_amount 為負），
     * 但錢包與 BR 都沒動；expected_bet 把它算進去會被沖成 0，真正漏記的注單就漏列。
     */
    #[Group('rules/vendor-ptsw#R-25')]
    public function test_rejected_refund_event_is_excluded_from_expected_bet(): void
    {
        $betRecordId = $this->seedAffectedRecords(currentBet: '30', expectedBet: '60');
        $betId = PtswBetRecord::findOrFail($betRecordId)->bet_id;
        $this->seedRejectedRefundEvent(betId: $betId, betAmount: '-60');

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
            '--execute' => true,
            '--reason' => 'rejected refund must not offset',
        ])->assertSuccessful();

        $br = PtswBetRecord::findOrFail($betRecordId);
        $this->assertEquals('60.0000', $br->bet, '被拒的退款事件不得沖掉 expected_bet，漏記的 30 必須補回');
        $this->assertSame(1, BetRecordRecoveryLog::count());
    }

    /**
     * raw 為 NULL 的生效沖銷事件仍要算進 expected_bet：`raw NOT LIKE '%"\_rejected"%'` 對 NULL 回 NULL，
     * 沒有 `whereNull('raw')` 這一半，refund 局會被補回 placeBet 金額（overshoot）。
     */
    public function test_refund_event_with_null_raw_still_offsets_expected_bet(): void
    {
        $betRecordId = $this->seedRefundRound(placeBetAmount: '20', currentBet: '0');
        $this->nullifyRefundEventRaw(PtswBetRecord::findOrFail($betRecordId)->bet_id);

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
            '--execute' => true,
            '--reason' => 'null raw refund event must still offset',
        ])->assertSuccessful();

        $br = PtswBetRecord::findOrFail($betRecordId);
        $this->assertEquals('0.0000', $br->bet, 'raw 為 NULL 的沖銷事件仍生效，BR.bet 不得被補成 placeBet 金額');
        $this->assertSame(0, BetRecordRecoveryLog::count());
    }

    public function test_max_bet_diff_safety_skips_large_anomalies(): void
    {
        $betRecordId = $this->seedAffectedRecords(currentBet: '5', expectedBet: '2000');

        $this->artisan('playtech:reconcile-bet-accumulation', [
            '--from' => '2026-05-08',
            '--to' => '2026-05-09',
            '--execute' => true,
            '--reason' => 'with safety',
            '--max-bet-diff' => '1000',
        ])->assertSuccessful();

        $br = PtswBetRecord::findOrFail($betRecordId);
        $this->assertEquals('5.0000', $br->bet, '單筆漏記 > max-bet-diff 應跳過');
        $this->assertSame(0, BetRecordRecoveryLog::count());
    }
}
