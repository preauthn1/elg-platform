<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\JokerBaccaratSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\JkbswBetRecord;
use App\Vendors\Models\JkbswDealRecord;

/**
 * 驗證 JokerBaccaratSW multi-bet 累加行為。
 *
 * 百家樂玩家可同時下注多碼（莊+對子），Joker 會送多筆 action=2 Bet
 * （不同 transactionNo、相同 roundID）。Controller 必須：
 *   - 每筆 deal_record INSERT（reference_no=transactionNo UNIQUE）
 *   - BR by `bet_id=roundID` lockForUpdate，首次建立、後續累加 bet/valid_bet
 *   - 同 roundID 多筆 Bet 後，jkbsw_bet_records 應只有 1 筆 row、bet 為兩次之和
 *
 * 對應規範：game-integration skill §3.3.1（multi-bet 累加，不可覆寫）。
 *
 * php artisan test tests/Feature/External/SingleWallets/JokerBaccaratSW/JokerBaccaratSwMultiBetTest.php --compact
 */
class JokerBaccaratSwMultiBetTest extends JokerBaccaratSwTestCase
{
    /**
     * 情境：同一 roundID 連兩次 action=2 Bet（不同 transactionNo、不同 amount，10 + 5）
     * 預期：
     *   - jkbsw_bet_records 只有 1 筆
     *   - BR.bet = 15、BR.valid_bet = 15、BR.win = 0、BR.win_lose = -15
     *   - jkbsw_deal_records 有 2 筆（兩個 transactionNo 各一筆）
     *   - 會員錢包扣了 15
     */
    public function test_multi_bet_same_round_accumulates_into_single_bet_record(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $roundID = 'R_round_001';
        $bet1Amount = '10.0';
        $bet2Amount = '5.0';

        // ── 第一次 Bet ──
        $response1 = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'T_round_001_bet_1', $roundID, $bet1Amount),
        );
        $response1->assertStatus(200)->assertJson(['status' => true]);

        $this->assertEquals(1, JkbswBetRecord::count(), '第一次 Bet 後應有 1 筆 BR');

        // ── 第二次 Bet：同 roundID、不同 transactionNo、不同金額 ──
        $response2 = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'T_round_001_bet_2', $roundID, $bet2Amount),
        );
        $response2->assertStatus(200)->assertJson(['status' => true]);

        // ── 斷言：BR 累加而非覆寫 ──
        $this->assertEquals(
            1,
            JkbswBetRecord::count(),
            '同一 roundID 多次 Bet 只應產生一筆 jkbsw_bet_records row',
        );

        $expectedBet = Common::numAdd($bet1Amount, $bet2Amount, 4); // 15.0000
        $expectedWinLose = Common::numMul($expectedBet, '-1', 4);   // -15.0000

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals($roundID, $betRecord->bet_id);
        $this->assertEquals($this->gameCode, $betRecord->game_code);
        $this->assertEquals($expectedBet, $betRecord->bet, 'BR.bet 必須累加（10+5=15），不可覆寫');
        $this->assertEquals($expectedBet, $betRecord->valid_bet, 'BR.valid_bet 必須累加');
        $this->assertEquals('0.0000', $betRecord->win);
        $this->assertEquals($expectedWinLose, $betRecord->win_lose);
        $this->assertEquals(0, $betRecord->status, '未派彩前 status 應為 0');

        // ── 斷言：deal_records 各別建立 ──
        $this->assertEquals(2, JkbswDealRecord::count(), '每筆 Bet 各對應一筆 deal_record');
        $this->assertNotNull(
            JkbswDealRecord::where('reference_no', 'T_round_001_bet_1')->first(),
        );
        $this->assertNotNull(
            JkbswDealRecord::where('reference_no', 'T_round_001_bet_2')->first(),
        );

        // ── 斷言：錢包扣了 15 ──
        $this->member->refresh();
        $this->assertEquals(
            Common::numSub($initialBalance, $expectedBet, 4),
            $this->member->balance,
        );
    }

    /**
     * 情境：兩次 Bet 累加（10 + 5 = 15）後，一次 action=3 Win amount=20
     * 預期：
     *   - BR.bet=15, BR.win=20, BR.win_lose=5（20-15）, BR.payout_time != null, BR.status=1
     *   - 會員錢包淨增 5（-15+20）
     *   - jkbsw_deal_records 有 3 筆（2 Bet + 1 Win）
     *   - total_bet_records 有 1 筆且 valid_bet 對齊
     */
    public function test_multi_bet_then_single_win_settles_correctly(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $roundID = 'R_round_002';
        $bet1Amount = '10.0';
        $bet2Amount = '5.0';
        $winAmount = '20.0';

        // ── 兩次 Bet ──
        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'T_round_002_bet_1', $roundID, $bet1Amount),
        )->assertStatus(200)->assertJson(['status' => true]);

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'T_round_002_bet_2', $roundID, $bet2Amount),
        )->assertStatus(200)->assertJson(['status' => true]);

        // Bet 階段已建立 TBR（win_lose 為負值，待 Win 更新）
        $this->assertEquals(1, TotalBetRecord::count(), 'Bet 階段應建立 TBR');

        // ── 一次 Win ──
        $winResponse = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(3, 'T_round_002_win_1', $roundID, $winAmount),
        );
        $winResponse->assertStatus(200)->assertJson(['status' => true]);

        // ── 斷言：BR 結算正確 ──
        $this->assertEquals(1, JkbswBetRecord::count(), '整個 round 仍只有 1 筆 BR');

        $expectedBet = Common::numAdd($bet1Amount, $bet2Amount, 4); // 15.0000
        $expectedWin = Common::numAdd('0', $winAmount, 4);          // 20.0000
        $expectedWinLose = Common::numSub($expectedWin, $expectedBet, 4); // 5.0000

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals($expectedBet, $betRecord->bet);
        $this->assertEquals($expectedBet, $betRecord->valid_bet);
        $this->assertEquals($expectedWin, $betRecord->win);
        $this->assertEquals($expectedWinLose, $betRecord->win_lose);
        $this->assertNotNull($betRecord->payout_time, 'Win 後 payout_time 應有值');
        $this->assertEquals(1, $betRecord->status, 'Win 後 status 應為 1');

        // ── 斷言：deal_records 3 筆 ──
        $this->assertEquals(3, JkbswDealRecord::count(), '2 Bet + 1 Win 共 3 筆 deal_records');

        // ── 斷言：TBR mirror BR 最終累加狀態 ──
        $this->assertEquals(1, TotalBetRecord::count(), 'Win 後應建立 1 筆 TBR');
        $tbr = TotalBetRecord::first();
        $this->assertEquals($betRecord->id, $tbr->bet_record_id);
        $this->assertEquals($this->vendor->id, $tbr->vendor_id);
        $this->assertEquals($expectedBet, $tbr->bet);
        $this->assertEquals(
            $expectedBet,
            $tbr->valid_bet,
            'TBR.valid_bet 必須取最終累加值（15），而非單筆 Bet 金額',
        );
        $this->assertEquals($expectedWinLose, $tbr->win_lose, 'TBR.win_lose 應 mirror BR after 狀態（5）');

        // ── 斷言：錢包淨增 5 ──
        $this->member->refresh();
        $expectedFinalBalance = Common::numAdd($initialBalance, $expectedWinLose, 4);
        $this->assertEquals($expectedFinalBalance, $this->member->balance);
    }

    /**
     * 情境：兩次 Bet（10+5=15）後，兩次 Win 累加（每次 8、共 16）
     * 預期：
     *   - BR.win=16, BR.win_lose=1（16-15）
     *   - jkbsw_deal_records 有 4 筆
     */
    public function test_multi_bet_then_multi_win_accumulates_payout(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $roundID = 'R_round_003';
        $bet1Amount = '10.0';
        $bet2Amount = '5.0';
        $win1Amount = '8.0';
        $win2Amount = '8.0';

        // ── 兩次 Bet ──
        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'T_round_003_bet_1', $roundID, $bet1Amount),
        )->assertStatus(200)->assertJson(['status' => true]);

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'T_round_003_bet_2', $roundID, $bet2Amount),
        )->assertStatus(200)->assertJson(['status' => true]);

        // ── 兩次 Win ──
        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(3, 'T_round_003_win_1', $roundID, $win1Amount),
        )->assertStatus(200)->assertJson(['status' => true]);

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(3, 'T_round_003_win_2', $roundID, $win2Amount),
        )->assertStatus(200)->assertJson(['status' => true]);

        $expectedBet = Common::numAdd($bet1Amount, $bet2Amount, 4);  // 15.0000
        $expectedWin = Common::numAdd($win1Amount, $win2Amount, 4);  // 16.0000
        $expectedWinLose = Common::numSub($expectedWin, $expectedBet, 4); // 1.0000

        $this->assertEquals(1, JkbswBetRecord::count(), '整個 round 仍只有 1 筆 BR');

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals($expectedBet, $betRecord->bet);
        $this->assertEquals($expectedWin, $betRecord->win, 'BR.win 必須累加（8+8=16），不可覆寫');
        $this->assertEquals($expectedWinLose, $betRecord->win_lose);
        $this->assertEquals(1, $betRecord->status);

        $this->assertEquals(4, JkbswDealRecord::count(), '2 Bet + 2 Win 共 4 筆 deal_records');

        // TBR mirror BR 最終累加狀態：2 次 Win 後 TBR 應 update 到 win=16, win_lose=1
        $this->assertEquals(1, TotalBetRecord::count(), '同 round 內 TBR 應只有 1 筆');
        $tbr = TotalBetRecord::first();
        $this->assertEquals($expectedBet, $tbr->valid_bet);
        $this->assertEquals(
            $expectedWinLose,
            $tbr->win_lose,
            'TBR.win_lose 第二次 Win 後必須 update 至累加值 1，不可只剩第一次 Win 的 -7',
        );

        // 錢包淨增 1
        $this->member->refresh();
        $expectedFinalBalance = Common::numAdd($initialBalance, $expectedWinLose, 4);
        $this->assertEquals($expectedFinalBalance, $this->member->balance);
    }

    /**
     * 情境：相同 transactionNo 連送兩次（reference_no UNIQUE 約束擋住第二筆）
     * 預期：
     *   - BR.bet 等於單次金額（沒被重複累加）
     *   - jkbsw_deal_records 只有 1 筆（unique 擋住第二筆）
     *   - 會員錢包只被扣一次
     */
    public function test_duplicate_bet_transaction_does_not_double_accumulate(): void
    {
        $initialBalance = '1000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $roundID = 'R_round_004';
        $transactionNo = 'T_round_004_bet_dup';
        $betAmount = '10.0';

        // ── 第一次 Bet ──
        $response1 = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, $transactionNo, $roundID, $betAmount),
        );
        $response1->assertStatus(200)->assertJson(['status' => true]);

        // ── 第二次相同 transactionNo（被 unique 擋住，回冪等 balance response）──
        $response2 = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, $transactionNo, $roundID, $betAmount),
        );
        $response2->assertStatus(200)->assertJson(['status' => true]);

        $expectedBet = Common::numAdd('0', $betAmount, 4); // 10.0000

        // BR 不應重複累加
        $this->assertEquals(1, JkbswBetRecord::count());
        $betRecord = JkbswBetRecord::first();
        $this->assertEquals(
            $expectedBet,
            $betRecord->bet,
            '相同 transactionNo 重送不可重複累加 BR.bet',
        );
        $this->assertEquals($expectedBet, $betRecord->valid_bet);

        // deal_records 只有 1 筆（unique 擋住第二筆）
        $this->assertEquals(
            1,
            JkbswDealRecord::count(),
            'reference_no UNIQUE 約束應擋住重複 transactionNo',
        );

        // 錢包只扣一次
        $this->member->refresh();
        $this->assertEquals(
            Common::numSub($initialBalance, $expectedBet, 4),
            $this->member->balance,
        );
    }
}
