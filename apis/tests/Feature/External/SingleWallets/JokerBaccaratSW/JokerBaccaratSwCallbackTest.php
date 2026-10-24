<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\JokerBaccaratSW;

use App\Helpers\Common;
use App\Models\TotalBetRecord;
use App\Vendors\Models\JkbswBetRecord;
use App\Vendors\Models\JkbswDealRecord;

/**
 * 驗證 JokerBaccaratSwController 的 balance / transfer (action 2/3/4/5) / goodRoad callback 行為。
 *
 * docker exec -it php-fpm8 bash -c "cd /var/www/APIs && \
 *   php artisan test --compact tests/Feature/External/SingleWallets/JokerBaccaratSW/JokerBaccaratSwCallbackTest.php"
 */
class JokerBaccaratSwCallbackTest extends JokerBaccaratSwTestCase
{
    // ────────────────────────────────────────────────────────────────────────
    // balance（action=1）
    // ────────────────────────────────────────────────────────────────────────

    public function test_balance_returns_wallet_balance(): void
    {
        $walletBalance = '1234.5000';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson("{$this->baseurl}/balance/detail", $this->balancePayload());

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'message' => '',
            'data' => [
                'balance' => (float) $walletBalance,
            ],
        ]);
    }

    public function test_balance_truncates_to_two_decimals(): void
    {
        // 小數第三位以後一律無條件捨去（非四捨五入）：1234.5678 → 1234.56
        $this->member->update(['balance' => '1234.5678']);

        $response = $this->postJson("{$this->baseurl}/balance/detail", $this->balancePayload());

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'message' => '',
            'data' => [
                'balance' => 1234.56,
            ],
        ]);
    }

    public function test_balance_returns_false_when_account_disabled(): void
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseurl}/balance/detail", $this->balancePayload());

        $response->assertStatus(200)->assertJson([
            'status' => false,
            'message' => 'Player account is disabled',
            'data' => null,
        ]);
    }

    public function test_balance_returns_false_when_player_not_found(): void
    {
        $response = $this->postJson(
            "{$this->baseurl}/balance/detail",
            $this->balancePayload(['playerID' => 'JKB_unknown'])
        );

        $response->assertStatus(200)->assertJson([
            'status' => false,
            'message' => 'Player not found',
            'data' => null,
        ]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // transfer action=2 Bet
    // ────────────────────────────────────────────────────────────────────────

    public function test_bet_deducts_balance_creates_br_and_deal_record(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $roundId = 'jkb-round-001';
        $transactionNo = 'jkb-tx-bet-001';

        $payload = $this->transferPayload(2, $transactionNo, $roundId, $betAmount);

        $response = $this->postJson("{$this->baseurl}/balance/transfer", $payload);

        $expectedBalance = Common::numSub($walletBalance, $betAmount, 4);
        $response->assertStatus(200)->assertJson([
            'status' => true,
            'message' => '',
            'data' => [
                'transactionNo' => $transactionNo,
                'balance' => (float) $expectedBalance,
            ],
        ]);

        $this->assertEquals(1, JkbswBetRecord::count());
        $this->assertEquals(1, JkbswDealRecord::count());
        $this->assertEquals(1, TotalBetRecord::count(), 'Bet 階段應建立 TotalBetRecord（win_lose 為負）');

        $tbrAtBet = TotalBetRecord::first();
        $this->assertEquals(Common::numMul($betAmount, -1, 4), $tbrAtBet->win_lose, 'Bet 時 TBR.win_lose = -bet');

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals($roundId, $betRecord->bet_id);
        $this->assertEquals($this->gameCode, $betRecord->game_code);
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->bet);
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->valid_bet);
        $this->assertEquals('0.0000', $betRecord->win);
        $this->assertEquals(Common::numMul($betAmount, -1, 4), $betRecord->win_lose);

        $dealRecord = JkbswDealRecord::first();
        $this->assertEquals(2, $dealRecord->action);
        $this->assertEquals($transactionNo, $dealRecord->reference_no);
        $this->assertEquals($roundId, $dealRecord->bet_id);
        $this->assertEquals($betRecord->id, $dealRecord->bet_record_id, 'bet 成功後 deal_record.bet_record_id 應回填');
        $this->assertEquals($walletBalance, $dealRecord->balance_before, 'bet 前餘額');
        $this->assertEquals($expectedBalance, $dealRecord->balance_after, 'bet 後餘額（扣款後）');

        $this->member->refresh();
        $this->assertEquals($expectedBalance, $this->member->balance);
    }

    public function test_bet_returns_insufficient_balance_when_low(): void
    {
        $this->member->update(['balance' => '10.0000']);

        $payload = $this->transferPayload(2, 'jkb-tx-bet-insuf', 'jkb-round-insuf', '50.0000');

        $response = $this->postJson("{$this->baseurl}/balance/transfer", $payload);

        $response->assertStatus(200)->assertJson([
            'status' => false,
            'message' => 'Insufficient balance',
            'data' => null,
        ]);

        // DomainException 觸發 DB::transaction rollback，BR / deal_record 都不應留存
        $this->assertEquals(0, JkbswBetRecord::count());
        $this->assertEquals(0, JkbswDealRecord::count());

        $this->member->refresh();
        $this->assertEquals('10.0000', $this->member->balance, '失敗時餘額不應變動');
    }

    public function test_bet_idempotent_on_duplicate_transaction_no(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $roundId = 'jkb-round-idem';
        $transactionNo = 'jkb-tx-bet-idem';

        $payload = $this->transferPayload(2, $transactionNo, $roundId, $betAmount);

        $this->postJson("{$this->baseurl}/balance/transfer", $payload)
            ->assertStatus(200)
            ->assertJson(['status' => true]);

        // 同 transactionNo 重送 → reference_no 唯一鍵 1062 → 回原 balance、不再扣款
        $response = $this->postJson("{$this->baseurl}/balance/transfer", $payload);

        $expectedBalance = Common::numSub($walletBalance, $betAmount, 4);
        $response->assertStatus(200)->assertJson([
            'status' => true,
            'message' => '',
            'data' => [
                'transactionNo' => $transactionNo,
                'balance' => (float) $expectedBalance,
            ],
        ]);

        $this->assertEquals(1, JkbswBetRecord::count(), '相同 transactionNo 只應有一筆 BR');
        $this->assertEquals(1, JkbswDealRecord::count(), '相同 transactionNo 只應有一筆 deal_record');

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->bet, 'bet 不應重複累加');

        $this->member->refresh();
        $this->assertEquals($expectedBalance, $this->member->balance, '餘額只應扣一次');
    }

    // ────────────────────────────────────────────────────────────────────────
    // transfer action=3 Win
    // ────────────────────────────────────────────────────────────────────────

    public function test_win_adds_payout_updates_br_and_creates_tbr(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $winAmount = '120.0000';
        $roundId = 'jkb-round-win';

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'jkb-tx-bet-win', $roundId, $betAmount)
        )->assertJson(['status' => true]);

        // act：派彩
        $response = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(3, 'jkb-tx-win-1', $roundId, $winAmount)
        );

        $balanceAfterBet = Common::numSub($walletBalance, $betAmount, 4);
        $balanceAfterWin = Common::numAdd($balanceAfterBet, $winAmount, 4);

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'message' => '',
            'data' => [
                'transactionNo' => 'jkb-tx-win-1',
                'balance' => (float) $balanceAfterWin,
            ],
        ]);

        $this->assertEquals(1, JkbswBetRecord::count());
        $this->assertEquals(2, JkbswDealRecord::count(), '一筆 bet + 一筆 win deal_record');
        $this->assertEquals(1, TotalBetRecord::count(), 'win 應建立 TBR');

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals(Common::numAdd('0', $betAmount, 4), $betRecord->bet);
        $this->assertEquals(Common::numAdd('0', $winAmount, 4), $betRecord->win);
        $this->assertEquals(Common::numSub($winAmount, $betAmount, 4), $betRecord->win_lose);
        $this->assertEquals(1, $betRecord->status);
        $this->assertNotNull($betRecord->payout_time);

        $winDeal = JkbswDealRecord::where('action', 3)->first();
        $this->assertEquals($betRecord->id, $winDeal->bet_record_id);
        $this->assertEquals($balanceAfterBet, $winDeal->balance_before, 'win 前餘額 = bet 扣完後');
        $this->assertEquals($balanceAfterWin, $winDeal->balance_after, 'win 後餘額 = bet 扣完 + 派彩');

        $this->member->refresh();
        $this->assertEquals($balanceAfterWin, $this->member->balance);
    }

    public function test_win_returns_round_not_found_when_no_matching_br(): void
    {
        $this->member->update(['balance' => '1000.0000']);

        $response = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(3, 'jkb-tx-win-orphan', 'jkb-round-orphan', '50.0000')
        );

        $response->assertStatus(200)->assertJson([
            'status' => false,
            'message' => 'Round not found',
            'data' => null,
        ]);

        // DomainException rollback → 連 deal_record 也不應留存
        $this->assertEquals(0, JkbswBetRecord::count());
        $this->assertEquals(0, JkbswDealRecord::count());
        $this->assertEquals(0, TotalBetRecord::count());
    }

    public function test_win_idempotent_on_duplicate_transaction_no(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $winAmount = '120.0000';
        $roundId = 'jkb-round-win-idem';

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'jkb-tx-bet-win-idem', $roundId, $betAmount)
        )->assertJson(['status' => true]);

        $payload = $this->transferPayload(3, 'jkb-tx-win-idem', $roundId, $winAmount);

        $this->postJson("{$this->baseurl}/balance/transfer", $payload)
            ->assertJson(['status' => true]);

        // 重送 → 1062 → 回原 balance
        $response = $this->postJson("{$this->baseurl}/balance/transfer", $payload);

        $balanceAfterWin = Common::numAdd(
            Common::numSub($walletBalance, $betAmount, 4),
            $winAmount,
            4
        );

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'data' => [
                'transactionNo' => 'jkb-tx-win-idem',
                'balance' => (float) $balanceAfterWin,
            ],
        ]);

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals(Common::numAdd('0', $winAmount, 4), $betRecord->win, 'win 不應重複累加');

        $this->assertEquals(
            2,
            JkbswDealRecord::count(),
            '重送 win 不應再產生新的 deal_record（bet + win 共 2 筆）'
        );

        $this->member->refresh();
        $this->assertEquals($balanceAfterWin, $this->member->balance, '餘額只應加一次');
    }

    // ────────────────────────────────────────────────────────────────────────
    // transfer action=4 Refund
    // ────────────────────────────────────────────────────────────────────────

    public function test_refund_resets_br_and_returns_bet_amount(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $roundId = 'jkb-round-refund';

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'jkb-tx-bet-refund', $roundId, $betAmount)
        )->assertJson(['status' => true]);

        $response = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(4, 'jkb-tx-refund-1', $roundId, $betAmount)
        );

        // refund 後應退回投注金額，總餘額回復原狀
        $response->assertStatus(200)->assertJson([
            'status' => true,
            'data' => [
                'transactionNo' => 'jkb-tx-refund-1',
                'balance' => (float) $walletBalance,
            ],
        ]);

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals('0.0000', $betRecord->bet);
        $this->assertEquals('0.0000', $betRecord->valid_bet);
        $this->assertEquals('0.0000', $betRecord->win);
        $this->assertEquals('0.0000', $betRecord->win_lose);
        $this->assertEquals(-1, $betRecord->status);
        $this->assertNotNull($betRecord->payout_time, 'refund 沖銷後應寫入 payout_time');

        $refundDeal = JkbswDealRecord::where('action', 4)->first();
        $this->assertEquals($betRecord->id, $refundDeal->bet_record_id);
        $balanceAfterBet = Common::numSub($walletBalance, $betAmount, 4);
        $this->assertEquals($balanceAfterBet, $refundDeal->balance_before, 'refund 前餘額 = bet 扣完後');
        $this->assertEquals($walletBalance, $refundDeal->balance_after, 'refund 後餘額回復原值');

        $this->member->refresh();
        $this->assertEquals($walletBalance, $this->member->balance);
    }

    public function test_refund_with_same_transaction_no_as_bet_still_settles_br(): void
    {
        // 三方實測會把 Bet + Refund 用同一個 transactionNo 下發；UNIQUE 改成
        // (reference_no, action) 後此情境應正常沖銷，而非走 1062 idempotent 跳過。
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $roundId = 'jkb-round-same-tx';
        $sharedTxNo = 'jkb-tx-shared-bet-refund';

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, $sharedTxNo, $roundId, $betAmount)
        )->assertJson(['status' => true]);

        $response = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(4, $sharedTxNo, $roundId, $betAmount)
        );

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'data' => [
                'transactionNo' => $sharedTxNo,
                'balance' => (float) $walletBalance,
            ],
        ]);

        $this->assertEquals(2, JkbswDealRecord::count(), '同 ref 不同 action 應共存兩筆 DR');
        $this->assertEquals(
            1,
            JkbswDealRecord::where('reference_no', $sharedTxNo)->where('action', 2)->count(),
        );
        $this->assertEquals(
            1,
            JkbswDealRecord::where('reference_no', $sharedTxNo)->where('action', 4)->count(),
        );

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals('0.0000', $betRecord->bet);
        $this->assertEquals('0.0000', $betRecord->win_lose);
        $this->assertEquals(-1, $betRecord->status);
        $this->assertNotNull($betRecord->payout_time);

        $this->member->refresh();
        $this->assertEquals($walletBalance, $this->member->balance, '玩家錢應被退回');
    }

    public function test_refund_after_win_zeroes_out_tbr_without_creating_new(): void
    {
        // Bet→Win→Refund edge case：TBR 在 Win 時建立，refund 後應同步沖銷而非殘留 Win 數字。
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $winAmount = '120.0000';
        $roundId = 'jkb-round-bet-win-refund';

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'jkb-tx-bwr-bet', $roundId, $betAmount)
        )->assertJson(['status' => true]);

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(3, 'jkb-tx-bwr-win', $roundId, $winAmount)
        )->assertJson(['status' => true]);

        $this->assertEquals(1, TotalBetRecord::count(), 'win 階段已建立 TBR');

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(4, 'jkb-tx-bwr-refund', $roundId, $betAmount)
        )->assertJson(['status' => true]);

        $this->assertEquals(1, TotalBetRecord::count(), 'refund 不應新建 TBR');

        $tbr = TotalBetRecord::first();
        $this->assertEquals('0.0000', $tbr->bet);
        $this->assertEquals('0.0000', $tbr->valid_bet);
        $this->assertEquals('0.0000', $tbr->win_lose);

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals(-1, $betRecord->status);
        $this->assertNotNull($betRecord->payout_time);
    }

    public function test_refund_without_prior_win_does_not_create_tbr(): void
    {
        // Bet→Refund 公鎚路徑：TBR 從未建立，refund 後也不該無中生有。
        $this->member->update(['balance' => '1000.0000']);

        $roundId = 'jkb-round-br-only';
        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'jkb-tx-br-bet', $roundId, '50.0000')
        )->assertJson(['status' => true]);

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(4, 'jkb-tx-br-refund', $roundId, '50.0000')
        )->assertJson(['status' => true]);

        $this->assertEquals(1, TotalBetRecord::count(), 'BET 建立的 TBR 在 refund 後仍存在（已沖銷為 0）');

        $tbr = TotalBetRecord::first();
        $this->assertEquals('0.0000', $tbr->bet, 'refund 後 TBR.bet 應沖銷為 0');
        $this->assertEquals('0.0000', $tbr->win_lose, 'refund 後 TBR.win_lose 應沖銷為 0');
    }

    public function test_refund_keeps_deal_record_when_no_matching_br(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(4, 'jkb-tx-refund-orphan', 'jkb-round-orphan', '50.0000')
        );

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'data' => [
                'transactionNo' => 'jkb-tx-refund-orphan',
                'balance' => (float) $walletBalance,
            ],
        ]);

        // 找不到 BR 也應留存 deal_record（審計）
        $this->assertEquals(0, JkbswBetRecord::count());
        $this->assertEquals(1, JkbswDealRecord::count());

        $deal = JkbswDealRecord::first();
        $this->assertEquals(4, $deal->action);
        $this->assertNull($deal->bet_record_id);

        $this->member->refresh();
        $this->assertEquals($walletBalance, $this->member->balance, '無對應 BR 不應動到餘額');
    }

    public function test_refund_idempotent_on_already_refunded_status(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = '50.0000';
        $roundId = 'jkb-round-refund-idem';

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(2, 'jkb-tx-bet-refund-idem', $roundId, $betAmount)
        )->assertJson(['status' => true]);

        $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(4, 'jkb-tx-refund-first', $roundId, $betAmount)
        )->assertJson(['status' => true]);

        // 第二次 refund：BR.status 已是 -1，僅留 deal_record 並回原 balance
        $response = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(4, 'jkb-tx-refund-second', $roundId, $betAmount)
        );

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'data' => [
                'transactionNo' => 'jkb-tx-refund-second',
                'balance' => (float) $walletBalance,
            ],
        ]);

        $this->assertEquals(1, JkbswBetRecord::count());
        $this->assertEquals(
            3,
            JkbswDealRecord::count(),
            '同一 round 第二次 refund 仍應落一筆審計 deal_record（bet + refund + refund）'
        );

        $betRecord = JkbswBetRecord::first();
        $this->assertEquals(-1, $betRecord->status);
        $this->assertEquals('0.0000', $betRecord->bet, '第二次 refund 不應再變動 BR 金額');

        $this->member->refresh();
        $this->assertEquals($walletBalance, $this->member->balance, '第二次 refund 不應再退款');
    }

    // ────────────────────────────────────────────────────────────────────────
    // transfer action=5 Rollback
    // ────────────────────────────────────────────────────────────────────────

    public function test_rollback_creates_deal_record_and_returns_success(): void
    {
        $walletBalance = '1000.0000';
        $this->member->update(['balance' => $walletBalance]);

        $response = $this->postJson(
            "{$this->baseurl}/balance/transfer",
            $this->transferPayload(5, 'jkb-tx-rollback-1', 'jkb-round-rollback', '50.0000')
        );

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'data' => [
                'transactionNo' => 'jkb-tx-rollback-1',
                'balance' => (float) $walletBalance,
            ],
        ]);

        // 純 audit：留 deal_record，不動 BR、不動餘額
        $this->assertEquals(0, JkbswBetRecord::count());
        $this->assertEquals(1, JkbswDealRecord::count());

        $deal = JkbswDealRecord::first();
        $this->assertEquals(5, $deal->action);
        $this->assertEquals('jkb-tx-rollback-1', $deal->reference_no);
        $this->assertNull($deal->bet_record_id);

        $this->member->refresh();
        $this->assertEquals($walletBalance, $this->member->balance);
    }

    // ────────────────────────────────────────────────────────────────────────
    // goodRoad（路紙推播）
    // ────────────────────────────────────────────────────────────────────────

    public function test_good_road_returns_success(): void
    {
        $response = $this->postJson("{$this->baseurl}/goodRoad", [
            'tableCode' => 'B01',
            'roadData' => [],
        ]);

        $response->assertStatus(200)->assertJson([
            'status' => true,
            'message' => '',
            'data' => null,
        ]);
    }
}
