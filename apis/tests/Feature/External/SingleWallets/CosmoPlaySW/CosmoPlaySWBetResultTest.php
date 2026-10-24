<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\CosmoPlaySW;

use App\Helpers\Common;

/**
 * 以 target/tickets#3048 dev 環境實際擷取到的 bet-result 請求建立測試。
 * 該次事故：CosmoPlay 在 URL 帶了 cache-busting 用的 query string（nocache=...），
 * JWT claims 僅由 JSON body 簽出，verifyJwt() 誤用含 query string 的 $request->all()
 * 比對 claims 導致永遠 401。
 */
class CosmoPlaySWBetResultTest extends CosmoPlaySWTestCase
{
    /**
     * 事故當下實際擷取的請求參數。
     */
    private const REAL_SPIN_ID = '626255713924022273';

    private const REAL_ROUND_ID = '626255713940799489';

    private const REAL_PLAYER_ID = 'TWER_MYR-cosmo00000001';

    private const REAL_BET_AMOUNT = 50;

    private function realPayload(): array
    {
        return [
            'SpinID' => self::REAL_SPIN_ID,
            'RoundID' => self::REAL_ROUND_ID,
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
            'BetAmount' => self::REAL_BET_AMOUNT,
        ];
    }

    public function test_bet_result_deducts_wallet_and_creates_bet_record(): void
    {
        $url = "{$this->baseurl}/bet-result";
        $payload = $this->realPayload();
        $bet = Common::numDiv(Common::numDiv((string) self::REAL_BET_AMOUNT, '100', 4), (string) $this->vendorInfo->rate, 4);

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $this->wallet->refresh();
        $expectedBalance = Common::numSub('1000', $bet, 4);
        $expectedVendorBalance = (int) Common::numFloor(Common::numMul(Common::numMul($expectedBalance, '100', 4), (string) $this->vendorInfo->rate, 4), 0);

        $response->assertStatus(200)
            ->assertJsonPath('Balance', $expectedVendorBalance);

        $this->assertEquals($expectedBalance, $this->wallet->balance);

        $this->assertDatabaseHas('cosmoplaysw_bet_records', [
            'bet_id' => self::REAL_SPIN_ID,
            'round_id' => self::REAL_ROUND_ID,
            'username' => $this->username,
            'game_code' => $this->gameId,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win' => 0,
            'win_lose' => Common::numSub('0', $bet, 4),
            'status' => 0,
        ]);
    }

    public function test_bet_result_succeeds_with_real_incident_query_string(): void
    {
        // 還原事故當下真實 URL：?nocache=1782807093698611927，但 JWT claims 不含這個 key
        $url = "{$this->baseurl}/bet-result?nocache=1782807093698611927";
        $payload = $this->realPayload();

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(200);
    }

    public function test_bet_result_is_idempotent_for_duplicate_spin_id(): void
    {
        $url = "{$this->baseurl}/bet-result";
        $payload = $this->realPayload();

        $first = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);
        $first->assertStatus(200);

        $this->wallet->refresh();
        $balanceAfterFirst = $this->wallet->balance;

        $second = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);
        $second->assertStatus(200)
            ->assertJsonPath('Balance', $first->json('Balance'));

        $this->wallet->refresh();
        $this->assertEquals($balanceAfterFirst, $this->wallet->balance);

        $this->assertDatabaseCount('cosmoplaysw_bet_records', 1);
    }

    public function test_bet_result_with_insufficient_balance_returns_error(): void
    {
        $this->wallet->update(['balance' => 0]);

        $url = "{$this->baseurl}/bet-result";
        $payload = $this->realPayload();

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(200)
            ->assertJsonPath('code', 17)
            ->assertJsonPath('message', 'Not Enough Balance');
    }
}
