<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\ClotPlaySW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\TestDox;

class ClotPlaySWBetAndSettleTest extends ClotPlaySWTestCase
{
    #[TestDox('同步投注結算：正確扣除投注並加回派彩，balance 為 wallet - bet + win')]
    public function test_bet_and_settle_applies_net_change_to_wallet(): void
    {
        $walletBalance = 2000;
        $betAmount = 100;
        $winAmount = 80;
        $this->member->update(['balance' => $walletBalance]);

        $params = $this->makeBetAndSettleParams([
            'bet' => $betAmount,
            'win' => $winAmount,
        ]);
        $gameRoundId = $params['game_round_id'];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/betSettlement', $params);

        $this->wallet->refresh();

        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $platformWin = Common::numDiv($winAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd(
            Common::numSub($walletBalance, $platformBet, 4),
            $platformWin,
            4,
        );
        $expectedVendorBalance = Common::numMul($expectedBalance, $this->vendorInfo->rate, 4);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'data' => ['balance' => $expectedVendorBalance],
            ]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas($this->betRecordTable, [
            'bet_id' => $gameRoundId,
            'username' => $this->username,
            'bet' => $platformBet,
            'win' => $platformWin,
        ]);
    }

    #[TestDox('同步投注結算：win=0 時僅扣除投注金額，無任何派彩')]
    public function test_bet_and_settle_with_zero_win_only_deducts_bet(): void
    {
        $walletBalance = 2000;
        $betAmount = 100;
        $this->member->update(['balance' => $walletBalance]);

        $params = $this->makeBetAndSettleParams([
            'bet' => $betAmount,
            'win' => 0,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/betSettlement', $params);

        $this->wallet->refresh();

        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numSub($walletBalance, $platformBet, 4);

        $response->assertStatus(200)->assertJson(['code' => 0]);
        $this->assertEquals($expectedBalance, $this->wallet->balance);
    }

    #[TestDox('同步投注結算：相同 game_round_id 重複送出時冪等回傳成功')]
    public function test_bet_and_settle_duplicate_is_idempotent(): void
    {
        $this->member->update(['balance' => 2000]);

        $params = $this->makeBetAndSettleParams(['bet' => 100, 'win' => 80]);

        $this->withHeaders($this->authHeaders())->postJson($this->baseUrl . '/betSettlement', $params);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/betSettlement', $params);

        $response->assertStatus(200)->assertJson(['code' => 0]);
    }

    #[TestDox('同步投注結算：Authorization header 錯誤時回傳 authorization error')]
    public function test_bet_and_settle_invalid_token_returns_authorization_error(): void
    {
        $params = $this->makeBetAndSettleParams();

        $response = $this->withHeaders(['Authorization' => 'wrong_token'])
            ->postJson($this->baseUrl . '/betSettlement', $params);

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'authorization error']);
    }
}
