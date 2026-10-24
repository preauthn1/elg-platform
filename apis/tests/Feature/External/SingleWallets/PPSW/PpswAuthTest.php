<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\PPSW;

use App\Helpers\Common;

/**
 * 驗證 PPSW authenticate / balance 端點行為：
 *   token 驗證、帳號狀態、hash 驗證、用戶不存在。
 *
 * php artisan test tests/Feature/External/SingleWallets/PPSW/PpswAuthTest.php --compact
 */
class PpswAuthTest extends PpswTestCase
{
    // ─────────────────────────────── authenticate ───────────────────────────────

    /**
     * 有效 token：回傳 userId / currency / cash / bonus=0。
     */
    public function test_authenticate_returns_balance_and_user_info(): void
    {
        $walletBalance = '500.0000';
        $this->member->update(['balance' => $walletBalance]);
        $this->account->update(['token' => 'auth-token-abc123']);

        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'token' => 'auth-token-abc123',
        ]);

        $response = $this->postJson("{$this->baseurl}/authenticate.html", $payload);
        $response->assertStatus(200)->assertJson([
            'error' => 0,
            'description' => 'Success',
            'userId' => $this->username,
            'currency' => 'CNY',
            'bonus' => 0,
        ]);

        $expectedCash = (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4);
        $this->assertEquals($expectedCash, $response->json('cash'));
    }

    /**
     * 無效 token（不存在）→ error:4。
     */
    public function test_authenticate_invalid_token_returns_error_4(): void
    {
        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'token' => 'invalid-token-xyz-000',
        ]);

        $this->postJson("{$this->baseurl}/authenticate.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 4]);
    }

    /**
     * 帳號停用（account.status=0）→ error:6。
     */
    public function test_authenticate_inactive_account_returns_error_6(): void
    {
        $this->account->update(['token' => 'inactive-token-001', 'status' => 0]);

        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'token' => 'inactive-token-001',
        ]);

        $this->postJson("{$this->baseurl}/authenticate.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 6]);
    }

    // ─────────────────────────────── balance ───────────────────────────────

    /**
     * 有效 userId：回傳 currency / cash / bonus=0。
     */
    public function test_balance_returns_current_balance(): void
    {
        $walletBalance = '750.0000';
        $this->member->update(['balance' => $walletBalance]);

        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'userId' => $this->username,
        ]);

        $response = $this->postJson("{$this->baseurl}/balance.html", $payload);
        $response->assertStatus(200)->assertJson([
            'error' => 0,
            'description' => 'Success',
            'currency' => 'CNY',
            'bonus' => 0,
        ]);

        $expectedCash = (float) Common::numMul($walletBalance, $this->vendorInfo->rate, 4);
        $this->assertEquals($expectedCash, $response->json('cash'));
    }

    /**
     * Hash 驗證失敗 → error:5。
     */
    public function test_balance_invalid_hash_returns_error_5(): void
    {
        $payload = [
            'providerId' => $this->providerId,
            'userId' => $this->username,
            'hash' => 'invalidhash00000000000000000000000',
        ];

        $this->postJson("{$this->baseurl}/balance.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 5]);
    }

    /**
     * 未知 userId → error:2（player not found）。
     */
    public function test_balance_unknown_user_returns_error_2(): void
    {
        $payload = $this->withHash([
            'providerId' => $this->providerId,
            'userId' => 'non-existent-user-xyz',
        ]);

        $this->postJson("{$this->baseurl}/balance.html", $payload)
            ->assertStatus(200)
            ->assertJson(['error' => 2]);
    }
}
