<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\ClotPlaySW;

use App\Helpers\Common;
use PHPUnit\Framework\Attributes\TestDox;

class ClotPlaySWCancelTest extends ClotPlaySWTestCase
{
    #[TestDox('取消：有投注紀錄時退款至餘額，settle_status 設為 2')]
    public function test_cancel_refunds_bet_and_marks_record_cancelled(): void
    {
        $walletBalance = 2000;
        $betAmount = 100;
        $this->member->update(['balance' => $walletBalance]);

        $betParams = $this->makeBetParams(['amount' => $betAmount]);
        $gameRoundId = $betParams['game_round_id'];
        $this->withHeaders($this->authHeaders())->postJson($this->baseUrl . '/bet', $betParams);
        $this->wallet->refresh();
        $balanceAfterBet = $this->wallet->balance;

        $cancelParams = $this->makeCancelParams($gameRoundId);
        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/cancel', $cancelParams);

        $this->wallet->refresh();

        $platformBet = Common::numDiv($betAmount, $this->vendorInfo->rate, 4);
        $expectedBalance = Common::numAdd($balanceAfterBet, $platformBet, 4);
        $expectedVendorBalance = Common::numMul($expectedBalance, $this->vendorInfo->rate, 4);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'data' => ['balance' => $expectedVendorBalance],
            ]);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas($this->betRecordTable, [
            'bet_id' => $gameRoundId,
            'settle_status' => 2,
        ]);
    }

    #[TestDox('取消：無對應投注紀錄時直接回傳當前餘額，不做任何操作')]
    public function test_cancel_without_bet_record_returns_current_balance(): void
    {
        $walletBalance = 1500;
        $this->member->update(['balance' => $walletBalance]);

        $cancelParams = $this->makeCancelParams('round_nonexistent_' . uniqid());
        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/cancel', $cancelParams);

        $this->wallet->refresh();

        $expectedVendorBalance = Common::numMul($walletBalance, $this->vendorInfo->rate, 4);

        $response->assertStatus(200)
            ->assertJson([
                'code' => 0,
                'data' => ['balance' => $expectedVendorBalance],
            ]);

        $this->assertEquals($walletBalance, $this->wallet->balance);
    }

    #[TestDox('取消：已取消紀錄重複送出時冪等回傳成功，不重複退款')]
    public function test_cancel_already_cancelled_record_is_idempotent(): void
    {
        $walletBalance = 2000;
        $betAmount = 100;
        $this->member->update(['balance' => $walletBalance]);

        $betParams = $this->makeBetParams(['amount' => $betAmount]);
        $gameRoundId = $betParams['game_round_id'];
        $this->withHeaders($this->authHeaders())->postJson($this->baseUrl . '/bet', $betParams);
        $this->wallet->refresh();
        $balanceAfterBet = $this->wallet->balance;

        $cancelParams = $this->makeCancelParams($gameRoundId);
        $this->withHeaders($this->authHeaders())->postJson($this->baseUrl . '/cancel', $cancelParams);
        $this->wallet->refresh();
        $balanceAfterFirstCancel = $this->wallet->balance;

        $response = $this->withHeaders($this->authHeaders())
            ->postJson($this->baseUrl . '/cancel', $cancelParams);

        $this->wallet->refresh();

        $response->assertStatus(200)->assertJson(['code' => 0]);
        $this->assertEquals($balanceAfterFirstCancel, $this->wallet->balance);
    }

    #[TestDox('取消：Authorization header 錯誤時回傳 authorization error')]
    public function test_cancel_invalid_token_returns_authorization_error(): void
    {
        $params = $this->makeCancelParams('round_auth_' . uniqid());

        $response = $this->withHeaders(['Authorization' => 'wrong_token'])
            ->postJson($this->baseUrl . '/cancel', $params);

        $response->assertStatus(200)
            ->assertJson(['code' => -1, 'msg' => 'authorization error']);
    }
}
