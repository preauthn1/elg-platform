<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\CosmoPlaySW;

/**
 * 對應 target/tickets#3048 實際事故：dev 環境收到 CosmoPlay 真實請求時，
 * 標準 Authorization header 被中間 gateway 改寫成不相干的內部 token
 * （claims: code/exp/orig_iat），CosmoPlay 真正依規格簽出的 JWT
 * （claims = 完整 request payload）實際帶在 AuthorizationV2 header，
 * 導致 verifyJwt() 永遠驗證失敗、回傳 401 code16 Unauthorized。
 */
class CosmoPlaySWAuthCheckTest extends CosmoPlaySWTestCase
{
    /**
     * 事故當下實際擷取到的 PlayerID。
     */
    private const REAL_PLAYER_ID = 'TWER_MYR-cosmo00000001';

    /**
     * 事故當下實際擷取到的標準 Authorization header 內容：
     * 與 CosmoPlay 規格無關的內部 token（claims: code/exp/orig_iat），
     * 修復後此 header 不再被驗證，僅放在測試中還原真實請求樣態。
     */
    private const REAL_UNRELATED_AUTHORIZATION_HEADER = 'Bearer [REDACTED]';

    public function test_auth_check_success_reproducing_real_incident_request(): void
    {
        $url = "{$this->baseurl}/auth-check";

        $payload = [
            'AuthToken' => $this->authToken,
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
        ];

        $response = $this->withHeaders([
            // 還原事故當下的真實請求：兩個 header 同時存在
            'Authorization' => self::REAL_UNRELATED_AUTHORIZATION_HEADER,
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(200)
            ->assertJsonPath('GameID', $this->gameId)
            ->assertJsonPath('IsWalletIntegrated', false)
            ->assertJsonPath('Balance', 100000)
            ->assertJsonPath('DecimalPlace', 2);
    }

    public function test_auth_check_fails_when_only_standard_authorization_header_is_used(): void
    {
        $url = "{$this->baseurl}/auth-check";

        $payload = [
            'AuthToken' => $this->authToken,
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
        ];

        // 即使簽章與 claims 都正確，放在標準 Authorization header 上也不再被接受
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(401)
            ->assertJsonPath('code', 16)
            ->assertJsonPath('message', 'Unauthorized');
    }

    public function test_auth_check_fails_with_invalid_signature_under_authorization_v2(): void
    {
        $url = "{$this->baseurl}/auth-check";

        $payload = [
            'AuthToken' => $this->authToken,
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
        ];

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload, 'wrong-secret'),
        ])->postJson($url, $payload);

        $response->assertStatus(401)
            ->assertJsonPath('code', 16)
            ->assertJsonPath('message', 'Unauthorized');
    }

    public function test_auth_check_fails_when_claims_mismatch_request_body(): void
    {
        $url = "{$this->baseurl}/auth-check";

        $payload = [
            'AuthToken' => $this->authToken,
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
        ];

        // JWT claims 帶的 GameID 跟實際 request body 不一致（避免 JWT 被重放到其他請求）
        $tamperedClaims = ['GameID' => 'OTHER'] + $payload;

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($tamperedClaims),
        ])->postJson($url, $payload);

        $response->assertStatus(401)
            ->assertJsonPath('code', 16)
            ->assertJsonPath('message', 'Unauthorized');
    }

    public function test_auth_check_succeeds_with_cache_busting_query_string_not_in_jwt_claims(): void
    {
        // 對應 target/tickets#3048 第二個事故：CosmoPlay 部分請求（如 bet-result）會在 URL 帶
        // cache-busting 用的 query string（如 nocache=...），但 JWT claims 只簽 JSON body，
        // 比對時若誤用 $request->all()（含 query string）會誤判 claims 不一致而 401。
        $url = "{$this->baseurl}/auth-check?nocache=1782807093698611927";

        $payload = [
            'AuthToken' => $this->authToken,
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
        ];

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(200)
            ->assertJsonPath('GameID', $this->gameId);
    }

    public function test_auth_check_fails_with_mismatched_auth_token(): void
    {
        $url = "{$this->baseurl}/auth-check";

        $payload = [
            'AuthToken' => 'someOtherAuthTokenNotMatchingAccount',
            'PlayerID' => self::REAL_PLAYER_ID,
            'GameID' => $this->gameId,
        ];

        $response = $this->withHeaders([
            'AuthorizationV2' => 'Bearer ' . $this->generateCosmoPlayJwt($payload),
        ])->postJson($url, $payload);

        $response->assertStatus(401)
            ->assertJsonPath('code', 16)
            ->assertJsonPath('message', 'Invalid AuthToken');
    }
}
