<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\BeSoftSW;

use App\Helpers\Common;

class BeSoftSWGameResultTest extends BeSoftSWTestCase
{
    private string $transId = 'trans_test_001';

    /**
     * 玩家獲勝 (win > bet)：餘額正確增加，bet_record 與 transaction_log 寫入正確
     */
    public function test_game_result_win(): void
    {
        $initialBalance = '2000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $bet = 100.0;
        $win = 250.0;
        $platformBet = Common::numDiv((string) $bet, $this->vendorInfo->rate, 4);
        $platformWin = Common::numDiv((string) $win, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd(Common::numSub($initialBalance, $platformBet, 4), $platformWin, 4);

        $response = $this->postJson($this->baseUrl, $this->makeParams([
            'action' => 3,
            'roundId' => 'round_test_001',
            'transId' => $this->transId,
            'bet' => $bet,
            'win' => $win,
            'spjpbet' => 0,
            'spjpwin' => 0,
            'mode' => 1,
        ]));

        $response->assertOk()->assertJson([
            'action' => 3,
            'status' => 1,
            'balance' => (float) Common::numMul($expectedBalance, $this->vendorInfo->rate, 4),
            'currency' => $this->vendorInfo->currency,
        ]);

        $this->member->refresh();
        $this->assertEquals(0, Common::numCompare($this->member->balance, $expectedBalance));

        $this->assertDatabaseHas('besoftsw_bet_records', [
            'bet_id' => $this->transId,
            'username' => $this->username,
            'game_code' => $this->gameCode,
            'bet' => $platformBet,
            'valid_bet' => $platformBet,
            'win' => $platformWin,
            'win_lose' => Common::numSub($platformWin, $platformBet, 4),
        ]);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'vendor_id' => $this->vendor->id,
            'before_balance' => $initialBalance,
            'balance' => $expectedBalance,
        ]);
    }

    /**
     * 玩家輸 (win < bet)：餘額正確減少
     */
    public function test_game_result_lose(): void
    {
        $initialBalance = '2000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $bet = 200.0;
        $win = 50.0;
        $platformBet = Common::numDiv((string) $bet, $this->vendorInfo->rate, 4);
        $platformWin = Common::numDiv((string) $win, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd(Common::numSub($initialBalance, $platformBet, 4), $platformWin, 4);

        $response = $this->postJson($this->baseUrl, $this->makeParams([
            'action' => 3,
            'roundId' => 'round_test_002',
            'transId' => $this->transId,
            'bet' => $bet,
            'win' => $win,
            'spjpbet' => 0,
            'spjpwin' => 0,
            'mode' => 1,
        ]));

        $response->assertOk()->assertJson([
            'action' => 3,
            'status' => 1,
        ]);

        $this->member->refresh();
        $this->assertEquals(0, Common::numCompare($this->member->balance, $expectedBalance));
    }

    /**
     * 相同 transId 重送：回應成功，餘額不重複變動
     */
    public function test_game_result_duplicate_trans_id(): void
    {
        $initialBalance = '2000.0000';
        $this->member->update(['balance' => $initialBalance]);

        $params = $this->makeParams([
            'action' => 3,
            'roundId' => 'round_test_003',
            'transId' => $this->transId,
            'bet' => 100.0,
            'win' => 150.0,
            'spjpbet' => 0,
            'spjpwin' => 0,
            'mode' => 1,
        ]);

        // 第一次送出
        $this->postJson($this->baseUrl, $params)->assertOk()->assertJson(['status' => 1]);

        $this->member->refresh();
        $balanceAfterFirst = $this->member->balance;

        // 第二次重送相同 transId
        $this->postJson($this->baseUrl, $params)->assertOk()->assertJson(['status' => 1]);

        $this->member->refresh();

        // 餘額不應再次變動
        $this->assertEquals(0, Common::numCompare($this->member->balance, $balanceAfterFirst));

        // besoftsw_bet_records 應只有一筆
        $this->assertDatabaseCount('besoftsw_bet_records', 1);
    }
}
