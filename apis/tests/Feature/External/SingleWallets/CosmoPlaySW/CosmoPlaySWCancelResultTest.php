<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\CosmoPlaySW;

use App\Helpers\Common;
use App\Vendors\Models\CosmoPlaySWBetRecord;
use Illuminate\Support\Carbon;

/**
 * 以 target/tickets#3048 dev 環境實際擷取到的 cancel-result 請求建立測試。
 * 該次事故跟 bet-result 同一個根因：URL 帶 cache-busting 用的 query string（nocache=...），
 * JWT claims 僅由 JSON body 簽出，verifyJwt() 誤用含 query string 的 $request->all()
 * 比對 claims 導致永遠 401（已由 613492285 修正，改用 $request->json()->all()）。
 */
class CosmoPlaySWCancelResultTest extends CosmoPlaySWTestCase
{
    /**
     * 事故當下實際擷取的請求參數。
     */
    private const REAL_SPIN_ID = '626255713924022273';

    private const REAL_ROUND_ID = '626255713940799489';

    private const REAL_PLAYER_ID = 'TWER_MYR-cosmo00000001';

    private function realPayload(): array
    {
        return [
            'SpinID' => self::REAL_SPIN_ID,
            'RoundID' => self::REAL_ROUND_ID,
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
        ];
    }

    private function createPendingBetRecord(string $bet = '0.5000'): CosmoPlaySWBetRecord
    {
        return CosmoPlaySWBetRecord::create([
            'vendor_info_id' => $this->vendorInfo->id,
            'member_id' => $this->member->id,
            'bet_id' => self::REAL_SPIN_ID,
            'round_id' => self::REAL_ROUND_ID,
            'username' => $this->username,
            'game_code' => $this->gameId,
            'bet' => $bet,
            'valid_bet' => $bet,
            'win' => 0,
            'win_lose' => Common::numSub('0', $bet, 4),
            'rolling_chip' => 0,
            'is_round_end' => 0,
            'status' => 0,
            'bet_time' => Carbon::now(),
            'info' => '[]',
        ]);
    }

    public function test_cancel_result_refunds_bet_and_marks_cancelled(): void
    {
        $bet = '0.5000';
        $this->wallet->update(['balance' => Common::numSub('1000', $bet, 4)]); // 模擬已扣過注的餘額
        $this->createPendingBetRecord($bet);

        $url = "{$this->baseurl}/cancel-result";
        $payload = $this->realPayload();

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $this->wallet->refresh();
        $expectedVendorBalance = (int) Common::numFloor(Common::numMul(Common::numMul($this->wallet->balance, '100', 4), (string) $this->vendorInfo->rate, 4), 0);

        $response->assertStatus(200)
            ->assertJsonPath('Balance', $expectedVendorBalance);

        $this->assertEquals('1000.0000', $this->wallet->balance); // 全額退還

        $this->assertDatabaseHas('cosmoplaysw_bet_records', [
            'bet_id' => self::REAL_SPIN_ID,
            'bet' => '0.0000',
            'valid_bet' => '0.0000',
            'win' => '0.0000',
            'win_lose' => '0.0000',
            'status' => 2,
        ]);
    }

    public function test_cancel_result_succeeds_with_real_incident_query_string(): void
    {
        $this->createPendingBetRecord();

        // 還原事故當下真實 URL：?nocache=1782807093846737380，但 JWT claims 不含這個 key
        $url = "{$this->baseurl}/cancel-result?nocache=1782807093846737380";
        $payload = $this->realPayload();

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(200);
    }

    public function test_cancel_result_is_idempotent_when_already_cancelled(): void
    {
        $betRecord = $this->createPendingBetRecord();
        $betRecord->update(['status' => 2, 'bet' => 0, 'valid_bet' => 0, 'win_lose' => 0]);

        $url = "{$this->baseurl}/cancel-result";
        $payload = $this->realPayload();

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(200);

        $this->wallet->refresh();
        $this->assertEquals('1000.0000', $this->wallet->balance); // 已取消過，不應再變動餘額
    }

    public function test_cancel_result_succeeds_when_bet_record_not_found(): void
    {
        // 注單不存在（如 bet-result 從未成功過）→ 視為冪等成功
        $url = "{$this->baseurl}/cancel-result";
        $payload = $this->realPayload();

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(200)
            ->assertJsonPath('Balance', 100000);
    }

    public function test_cancel_result_rejects_already_settled_bet(): void
    {
        $betRecord = $this->createPendingBetRecord();
        $betRecord->update(['status' => 1]);

        $url = "{$this->baseurl}/cancel-result";
        $payload = $this->realPayload();

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(400)
            ->assertJsonPath('code', 9)
            ->assertJsonPath('message', 'Bet Already Settled');
    }
}
