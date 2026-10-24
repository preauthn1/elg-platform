<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\ClotPlaySW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\TestDox;

class ClotPlaySWBetTest extends ClotPlaySWTestCase
{
    #[TestDox('投注：正常扣除餘額並建立 bet record')]
    public function test_bet_deducts_wallet_and_creates_bet_record(): void
    {
        $walletBalance = 2000;
        $this->member->update(['balance' => $walletBalance]);

        $betAmount = 100;
        $params = $this->makeBetParams(['amount' => $betAmount]);
        $gameRoundId = $params['game_round_id'];

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/bet', $params);

        $this->wallet->refresh();

        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numSub($walletBalance, $platformBet, 4);
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
            'game_code' => $this->gameCode,
            'bet' => $platformBet,
            'valid_bet' => $platformBet,
            'win' => '0.0000',
        ]);
    }

    #[TestDox('投注：餘額不足時回傳 insufficient balance 錯誤')]
    public function test_bet_with_insufficient_balance_returns_error(): void
    {
        $this->member->update(['balance' => 10]);

        $params = $this->makeBetParams(['amount' => 10000]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/bet', $params);

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'insufficient balance']);
    }

    #[TestDox('投注：相同 game_round_id 重複送出時冪等回傳成功')]
    public function test_bet_duplicate_game_round_id_is_idempotent(): void
    {
        $this->member->update(['balance' => 2000]);

        $params = $this->makeBetParams(['amount' => 100]);

        $this->withHeaders($this->authHeaders())->postJson($this->baseUrl . '/bet', $params);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/bet', $params);

        $response->assertStatus(200)
            ->assertJson(['code' => 0]);
    }

    #[TestDox('投注：帳號停用時回傳 member not found 錯誤')]
    public function test_bet_disabled_account_returns_error(): void
    {
        $this->account->update(['status' => 0]);
        $this->member->update(['balance' => 2000]);

        $params = $this->makeBetParams(['amount' => 100]);

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/bet', $params);

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'member not found']);
    }

    #[TestDox('投注：Authorization header 錯誤時回傳 authorization error')]
    public function test_bet_invalid_token_returns_authorization_error(): void
    {
        $this->member->update(['balance' => 2000]);

        $params = $this->makeBetParams(['amount' => 100]);

        $response = $this->withHeaders(['Authorization' => 'wrong_token'])
            ->postJson($this->baseUrl . '/bet', $params);

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'authorization error']);
    }
}
