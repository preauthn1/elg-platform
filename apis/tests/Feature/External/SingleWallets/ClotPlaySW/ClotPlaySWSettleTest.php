<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\ClotPlaySW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\TestDox;

class ClotPlaySWSettleTest extends ClotPlaySWTestCase
{
    #[TestDox('結算：有前置投注紀錄時更新並派彩，餘額正確增加')]
    public function test_settle_after_bet_credits_win_and_updates_record(): void
    {
        $walletBalance = 2000;
        $betAmount = 100;
        $winAmount = 80;
        $this->member->update(['balance' => $walletBalance]);

        $betParams = $this->makeBetParams(['amount' => $betAmount]);
        $gameRoundId = $betParams['game_round_id'];
        $this->withHeaders($this->authHeaders())->postJson($this->baseUrl . '/bet', $betParams);
        $this->wallet->refresh();
        $balanceAfterBet = $this->wallet->balance;

        $settleParams = $this->makeSettleParams($gameRoundId, [
            'valid_bet' => $betAmount,
            'amount' => $winAmount,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/settlement', $settleParams);

        $this->wallet->refresh();

        $platformWin = Common::numDiv($winAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($balanceAfterBet, $platformWin, 4);
        $expectedVendorBalance = Common::numMul($expectedBalance, $this->vendorInfo->rate, 4);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'data' => ['balance' => $expectedVendorBalance],
            ]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $this->assertDatabaseHas($this->betRecordTable, [
            'bet_id' => $gameRoundId,
            'bet' => $platformBet,
            'win' => Common::numDiv($winAmount, $this->vendorInfo->rate, 4),
        ]);
    }

    #[TestDox('結算：無前置投注紀錄時自動建立 bet record 並派彩')]
    public function test_settle_without_prior_bet_creates_bet_record(): void
    {
        $walletBalance = 2000;
        $betAmount = 100;
        $winAmount = 80;
        $this->member->update(['balance' => $walletBalance]);

        $gameRoundId = 'round_settle_only_' . uniqid();
        $settleParams = $this->makeSettleParams($gameRoundId, [
            'valid_bet' => $betAmount,
            'amount' => $winAmount,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/settlement', $settleParams);

        $this->wallet->refresh();

        // 無前置投注時，結算仍代表一整局（下注 + 派彩），故淨變化為 win - bet
        $platformWin = Common::numDiv($winAmount, $this->vendorInfo->rate, 4);
        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($walletBalance, Common::numSub($platformWin, $platformBet, 4), 4);

        $response->assertStatus(200)->assertJson(['code' => 0]);
        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas($this->betRecordTable, [
            'bet_id' => $gameRoundId,
            'username' => $this->username,
        ]);
    }

    #[TestDox('結算：Authorization header 錯誤時回傳 authorization error')]
    public function test_settle_invalid_token_returns_authorization_error(): void
    {
        $gameRoundId = 'round_settle_auth_' . uniqid();
        $params = $this->makeSettleParams($gameRoundId);

        $response = $this->withHeaders(['Authorization' => 'wrong_token'])
            ->postJson($this->baseUrl . '/settlement', $params);

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'authorization error']);
    }
}
