<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\PpswBetRecord;
use App\Vendors\Models\PpswDealRecord;
use App\Vendors\PPSW\BetRecordStatusEnum;

/**
 * 驗證 PPSW multi-bet round 行為：
 *   同一個 roundId 多次 bet 只產生一筆 PpswBetRecord，
 *   結算（result + endRound）後只產生一筆 TotalBetRecord。
 *
 * php artisan test tests/Feature/External/SingleWallets/PPSW/PpswMultiBetTest.php --compact
 */
class PpswMultiBetTest extends PpswTestCase
{
    /**
     * 情境：同一 roundId 連續兩次 bet（reference 不同）後，依序呼叫 result、endRound
     * 預期：
     *   - PpswBetRecord 只有一筆，bet/valid_bet 累加為 60、win=120、win_lose=60、status=SETTLE
     *   - TotalBetRecord 只有一筆，與 PpswBetRecord 對齊
     */
    public function test_multi_bet_same_round_produces_single_bet_record_and_single_total_bet_record(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $roundId = '93962792401008';
        $betAmount = '30.0';
        $resultAmount = '120.0';

        $bet1Reference = '23469d58b467ae2e934da58562e';
        $bet2Reference = '23469d58b547ae2e934da58565f';
        $resultReference = '23469d58b667ae2e934da585687';

        // ── Step 1：第一次 bet ──
        $bet1 = $this->withHash([
            'amount' => $betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $bet1Reference,
            'roundDetails' => 'spin',
            'roundId' => $roundId,
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]);
        $bet1Response = $this->postJson("{$this->baseurl}/bet.html", $bet1);
        $bet1Response->assertStatus(200)->assertJson(['error' => 0, 'description' => 'Success']);

        // 第一次 bet 後應有 1 筆 BR，無 TBR
        $this->assertEquals(1, PpswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());

        // ── Step 2：第二次 bet，相同 roundId、不同 reference ──
        $bet2 = $this->withHash([
            'amount' => $betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $bet2Reference,
            'roundDetails' => 'spin',
            'roundId' => $roundId,
            'timestamp' => '1775602516697',
            'userId' => $this->username,
        ]);
        $bet2Response = $this->postJson("{$this->baseurl}/bet.html", $bet2);
        $bet2Response->assertStatus(200)->assertJson(['error' => 0, 'description' => 'Success']);

        // 第二次 bet 後仍應只有 1 筆 BR（金額累加），無 TBR
        $this->assertEquals(1, PpswBetRecord::count(), '同一 roundId 多次 bet 只應產生一筆 PpswBetRecord');
        $this->assertEquals(0, TotalBetRecord::count(), 'endRound 之前不應產生 TotalBetRecord');

        $betRecord = PpswBetRecord::first();
        $this->assertEquals($roundId, $betRecord->bet_id);
        $this->assertEquals(Common::numAdd($betAmount, $betAmount, 4), $betRecord->bet);
        $this->assertEquals(Common::numAdd($betAmount, $betAmount, 4), $betRecord->valid_bet);
        $this->assertEquals(Common::numMul(Common::numAdd($betAmount, $betAmount, 4), -1, 4), $betRecord->win_lose);
        $this->assertEquals(BetRecordStatusEnum::BET, $betRecord->status);

        // 餘額：1000 - 30 - 30 = 940
        $this->member->refresh();
        $this->assertEquals(Common::numSub($walletBalance, Common::numAdd($betAmount, $betAmount, 4), 4), $this->member->balance);

        // ── Step 3：result 派彩（120 = 雙倍下注的勝出） ──
        $result = $this->withHash([
            'amount' => $resultAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $resultReference,
            'roundDetails' => 'spin',
            'roundId' => $roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);
        $resultResponse = $this->postJson("{$this->baseurl}/result.html", $result);
        $resultResponse->assertStatus(200)->assertJson(['error' => 0, 'description' => 'Success']);

        // result 後 BR 應仍為 1 筆，並升級為 SETTLE；TBR 此時建立 1 筆
        $this->assertEquals(1, PpswBetRecord::count());
        $this->assertEquals(1, TotalBetRecord::count());

        // ── Step 4：endRound（status 已是 SETTLE，應為冪等回應，不產生額外資料） ──
        $endRound = $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => $roundId,
            'userId' => $this->username,
        ]);
        $endRoundResponse = $this->postJson("{$this->baseurl}/endRound.html", $endRound);
        $endRoundResponse->assertStatus(200)->assertJson(['error' => 0, 'description' => 'Success']);

        // ── 最終斷言 ──
        $this->assertEquals(1, PpswBetRecord::count(), '整個 round 應只產生一筆 PpswBetRecord');
        $this->assertEquals(1, TotalBetRecord::count(), '結算後應只產生一筆 TotalBetRecord');

        $betRecord = PpswBetRecord::first();
        $totalBetRecord = TotalBetRecord::first();

        // 期望金額：bet=60、win=120、win_lose=60（統一以 numAdd('0',...) 正規化到 4 位精度）
        $expectedBet = Common::numAdd($betAmount, $betAmount, 4);          // 60.0000
        $expectedWin = Common::numAdd('0', $resultAmount, 4);              // 120.0000
        $expectedWinLose = Common::numSub($expectedWin, $expectedBet, 4);  // 60.0000

        // PpswBetRecord 最終狀態
        $this->assertEquals($expectedBet, $betRecord->bet);
        $this->assertEquals($expectedBet, $betRecord->valid_bet);
        $this->assertEquals($expectedWin, $betRecord->win);
        $this->assertEquals($expectedWinLose, $betRecord->win_lose);
        $this->assertEquals(BetRecordStatusEnum::SETTLE, $betRecord->status);

        // TotalBetRecord 與 BR 對齊
        $this->assertEquals($betRecord->id, $totalBetRecord->bet_record_id);
        $this->assertEquals($this->vendor->id, $totalBetRecord->vendor_id);
        $this->assertEquals($expectedBet, $totalBetRecord->bet);
        $this->assertEquals($expectedBet, $totalBetRecord->valid_bet);
        $this->assertEquals($expectedWinLose, $totalBetRecord->win_lose);

        // 最終餘額：1000 - 60 + 120 = 1060
        $this->member->refresh();
        $expectedFinalBalance = Common::numAdd(Common::numSub($walletBalance, $expectedBet, 4), $expectedWin, 4);
        $this->assertEquals($expectedFinalBalance, $this->member->balance);

        // ── PpswDealRecord 斷言 ──
        // 兩次 bet（不同 reference）+ 一次 result = 3 筆；endRound 不會建立 deal_record
        $this->assertEquals(3, PpswDealRecord::count(), '兩次 bet + 一次 result 共產生 3 筆 deal_records，endRound 不建立');

        $expectedDealRecords = [
            ['action' => 'bet', 'reference' => $bet1Reference],
            ['action' => 'bet', 'reference' => $bet2Reference],
            ['action' => 'result', 'reference' => $resultReference],
        ];

        foreach ($expectedDealRecords as $expected) {
            $deal = PpswDealRecord::query()
                ->where('username', $this->username)
                ->where('action', $expected['action'])
                ->where('reference_no', $expected['reference'])
                ->first();

            $this->assertNotNull(
                $deal,
                sprintf('找不到 deal_record action=%s reference=%s', $expected['action'], $expected['reference'])
            );
            $this->assertEquals(1, $deal->status, 'deal_record 應為已完成狀態 (status=1)');
            $this->assertEquals($roundId, $deal->bet_id, 'deal_record.bet_id 應對應 roundId');
        }
    }

    /**
     * 情境：bet → endRound → result（與一般 bet → result → endRound 順序相反）
     * 預期：
     *   - 全程僅 1 筆 PpswBetRecord，status: BET → END_ROUND → SETTLE
     *   - endRound 建立 1 筆 TotalBetRecord，result 透過 updateOrCreate 更新同一筆，TBR 數量仍為 1
     *   - PpswDealRecord 共 2 筆（bet + result，endRound 不建立）
     */
    public function test_bet_then_end_round_then_result_keeps_single_bet_record(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $roundId = '93962792401099';
        $betAmount = '30.0';
        $resultAmount = '120.0';

        $betReference = 'order-bet-001';
        $resultReference = 'order-result-001';

        // ── Step 1：bet ──
        $betPayload = $this->withHash([
            'amount' => $betAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $betReference,
            'roundDetails' => 'spin',
            'roundId' => $roundId,
            'timestamp' => '1775602502471',
            'userId' => $this->username,
        ]);
        $this->postJson("{$this->baseurl}/bet.html", $betPayload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        $this->assertEquals(1, PpswBetRecord::count());
        $this->assertEquals(0, TotalBetRecord::count(), 'bet 階段不建立 TBR');
        $br = PpswBetRecord::first();
        $this->assertEquals(BetRecordStatusEnum::BET, $br->status);

        // ── Step 2：endRound（先於 result） ──
        $endRoundPayload = $this->withHash([
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'roundId' => $roundId,
            'userId' => $this->username,
        ]);
        $this->postJson("{$this->baseurl}/endRound.html", $endRoundPayload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        // endRound 應將 status 翻成 END_ROUND，並建立 1 筆 TBR
        $this->assertEquals(1, PpswBetRecord::count());
        $this->assertEquals(1, TotalBetRecord::count(), 'endRound 應建立第一筆 TBR');
        $br = PpswBetRecord::first();
        $tbrAfterEndRound = TotalBetRecord::first();
        $tbrIdAfterEndRound = $tbrAfterEndRound->id;
        $this->assertEquals(BetRecordStatusEnum::END_ROUND, $br->status);
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $tbrAfterEndRound->bet);
        $this->assertEquals(Common::numMul($betAmount, -1, 4), $tbrAfterEndRound->win_lose);

        // ── Step 3：result（後到，但金額仍應正確結算） ──
        $resultPayload = $this->withHash([
            'amount' => $resultAmount,
            'gameId' => $this->gameCode,
            'providerId' => $this->providerId,
            'reference' => $resultReference,
            'roundDetails' => 'spin',
            'roundId' => $roundId,
            'timestamp' => '1775602534530',
            'userId' => $this->username,
        ]);
        $this->postJson("{$this->baseurl}/result.html", $resultPayload)
            ->assertStatus(200)
            ->assertJson(['error' => 0, 'description' => 'Success']);

        // ── 最終斷言 ──
        $this->assertEquals(1, PpswBetRecord::count(), '整個 round 應只有一筆 PpswBetRecord');
        $this->assertEquals(1, TotalBetRecord::count(), 'TBR 應由 result 透過 updateOrCreate 更新到同一筆');

        $br = PpswBetRecord::first();
        $tbr = TotalBetRecord::first();

        // result() 是把 amount 加到原本 win/win_lose 上，因此：
        //   bet=30、win=120、win_lose=-30+120=90
        $expectedBet = Common::numAdd('0', $betAmount, 4);            // 30.0000
        $expectedWin = Common::numAdd('0', $resultAmount, 4);          // 120.0000
        $expectedWinLose = Common::numSub($expectedWin, $expectedBet, 4); // 90.0000

        $this->assertEquals($expectedBet, $br->bet);
        $this->assertEquals($expectedWin, $br->win);
        $this->assertEquals($expectedWinLose, $br->win_lose);
        $this->assertEquals(BetRecordStatusEnum::SETTLE, $br->status);

        // TBR id 應與 endRound 階段建立的一致（updateOrCreate 而非新增）
        $this->assertEquals($tbrIdAfterEndRound, $tbr->id, 'result 應更新既有 TBR 而不是新增');
        $this->assertEquals($br->id, $tbr->bet_record_id);
        $this->assertEquals($expectedBet, $tbr->bet);
        $this->assertEquals($expectedWinLose, $tbr->win_lose);

        // 餘額：1000 - 30 + 120 = 1090
        $this->member->refresh();
        $expectedFinalBalance = Common::numAdd(Common::numSub($walletBalance, $expectedBet, 4), $expectedWin, 4);
        $this->assertEquals($expectedFinalBalance, $this->member->balance);

        // deal_records：bet + result = 2 筆，endRound 不建立
        $this->assertEquals(2, PpswDealRecord::count(), 'bet + result 共 2 筆 deal_records，endRound 不建立');
        $this->assertNotNull(
            PpswDealRecord::query()->where('action', 'bet')->where('reference_no', $betReference)->first()
        );
        $this->assertNotNull(
            PpswDealRecord::query()->where('action', 'result')->where('reference_no', $resultReference)->first()
        );
    }
}
