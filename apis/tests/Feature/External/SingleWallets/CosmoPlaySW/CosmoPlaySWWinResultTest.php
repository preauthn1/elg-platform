<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\CosmoPlaySW;

use App\Helpers\Common;

/**
 * php artisan test --filter CosmoPlaySWWinResultTest
 * 以 target/tickets#3048 dev 環境實際擷取到的 win-result 請求建立測試。
 * WinAmount 單位為分（÷100 再 ÷rate 轉為內部金額）。
 */
class CosmoPlaySWWinResultTest extends CosmoPlaySWTestCase
{
    private const SPIN_ID = '626382206213816321';

    private const ROUND_ID = '626382206230593537';

    private const PLAYER_ID = 'TWER_MYR-cosmo00000001';

    private const BET_AMOUNT = 50;

    private const WIN_AMOUNT = 270;

    /** 先呼叫 bet-result 建立注單，再呼叫 win-result 派彩 */
    private function placeBet(): void
    {
        $betPayload = [
            'SpinID' => self::SPIN_ID,
            'RoundID' => self::ROUND_ID,
            'PlayerID' => self::PLAYER_ID,
            'GameID' => $this->gameId,
            'BetAmount' => self::BET_AMOUNT,
        ];

        $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($betPayload),
        ])->postJson("{$this->baseurl}/bet-result", $betPayload)->assertStatus(200);
    }

    private function winPayload(): array
    {
        return [
            'SpinID' => self::SPIN_ID,
            'RoundID' => self::ROUND_ID,
            'PlayerID' => self::PLAYER_ID,
            'GameID' => $this->gameId,
            'WinAmount' => self::WIN_AMOUNT,
            'IsRoundEnd' => true,
        ];
    }

    public function test_win_result_credits_wallet_and_settles_bet_record(): void
    {
        $this->placeBet();

        $bet = Common::numDiv(Common::numDiv((string) self::BET_AMOUNT, '100', 4), (string) $this->vendorInfo->rate, 4);
        $win = Common::numDiv(Common::numDiv((string) self::WIN_AMOUNT, '100', 4), (string) $this->vendorInfo->rate, 4);

        $payload = $this->winPayload();
        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson("{$this->baseurl}/win-result", $payload);

        $this->wallet->refresh();
        $expectedBalance = Common::numAdd(Common::numSub('1000', $bet, 4), $win, 4);
        $expectedVendorBalance = (int) Common::numFloor(Common::numMul(Common::numMul($expectedBalance, '100', 4), (string) $this->vendorInfo->rate, 4), 0);

        $response->assertStatus(200)
            ->assertJsonPath('Balance', $expectedVendorBalance);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('cosmoplaysw_bet_records', [
            'bet_id' => self::SPIN_ID,
            'round_id' => self::ROUND_ID,
            'win' => $win,
            'win_lose' => Common::numSub($win, $bet, 4),
            'is_round_end' => 1,
            'status' => 1,
        ]);
    }

    public function test_win_result_with_zero_win_amount_settles_as_loss(): void
    {
        $this->placeBet();

        $bet = Common::numDiv(Common::numDiv((string) self::BET_AMOUNT, '100', 4), (string) $this->vendorInfo->rate, 4);

        $payload = array_merge($this->winPayload(), ['WinAmount' => 0]);
        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson("{$this->baseurl}/win-result", $payload);

        $this->wallet->refresh();
        $expectedBalance = Common::numSub('1000', $bet, 4);

        $response->assertStatus(200);
        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('cosmoplaysw_bet_records', [
            'bet_id' => self::SPIN_ID,
            'win' => '0.0000',
            'win_lose' => Common::numSub('0', $bet, 4),
            'status' => 1,
        ]);
    }

    public function test_win_result_is_idempotent(): void
    {
        $this->placeBet();

        $payload = $this->winPayload();
        $headers = ['AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload)];

        $first = $this->withHeaders($headers)->postJson("{$this->baseurl}/win-result", $payload);
        $first->assertStatus(200);

        $second = $this->withHeaders($headers)->postJson("{$this->baseurl}/win-result", $payload);
        $second->assertStatus(200)
            ->assertJsonPath('Balance', $first->json('Balance'));

        $this->assertDatabaseCount('cosmoplaysw_bet_records', 1);
    }

    public function test_win_result_returns_error_when_bet_record_not_found(): void
    {
        $this->placeBet();

        // 用不存在的 SpinID 觸發 "Bet Record Not Found"
        $payload = array_merge($this->winPayload(), ['SpinID' => 'non-existent-spin-id']);

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson("{$this->baseurl}/win-result", $payload);

        $response->assertStatus(404)
            ->assertJsonPath('code', 5)
            ->assertJsonPath('message', 'Bet Record Not Found');
    }
}
