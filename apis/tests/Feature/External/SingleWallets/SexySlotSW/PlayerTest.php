<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\SexySlotSW;

/**
 * 驗證 SexySlotSW player 端點：查詢餘額、refreshtoken、玩家不存在、hashkey 錯誤、帳號停用。
 *
 * php artisan test tests/Feature/External/SingleWallets/SexySlotSW/PlayerTest.php --compact
 */
class PlayerTest extends SexySlotSWTestCase
{
    public function test_player_returns_balance_and_account_info(): void
    {
        $this->member->update(['balance' => '500.0000']);

        $response = $this->postJson("{$this->baseUrl}/player", $this->playerPayload());

        $response->assertStatus(200)
            ->assertJsonPath('playerdetailsresponse.status.code', 200)
            ->assertJsonPath('playerdetailsresponse.accountid', $this->username)
            ->assertJsonPath('playerdetailsresponse.balance', 500)
            ->assertJsonPath('playerdetailsresponse.currencycode', 'MYR');
    }

    public function test_player_refreshtoken_returns_new_token(): void
    {
        $this->member->update(['balance' => '100.0000']);

        $payload = $this->playerPayload();
        $payload['playerdetailsrequest']['refreshtoken'] = true;

        $response = $this->postJson("{$this->baseUrl}/player", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('playerdetailsresponse.status.code', 200);

        $newToken = [REDACTED]'playerdetailsresponse.refreshtoken');
        $this->assertNotEquals('false', $newToken);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{32}$/', $newToken);

        $this->account->refresh();
        $this->assertEquals($newToken, $this->account->session_token);
    }

    public function test_player_not_found_returns_402(): void
    {
        $payload = $this->playerPayload();
        $payload['playerdetailsrequest']['client_player_id'] = 'nonexistent_player';

        $response = $this->postJson("{$this->baseUrl}/player", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('playerdetailsresponse.status.code', 402)
            ->assertJsonPath('playerdetailsresponse.status.status', 'PLAYER_NOT_FOUND');
    }

    public function test_player_invalid_hashkey_returns_402(): void
    {
        $payload = $this->playerPayload();
        $payload['hashkey'] = 'invalidhashkey000000000000000000';

        $response = $this->postJson("{$this->baseUrl}/player", $payload);

        $response->assertStatus(200)
            ->assertJsonPath('playerdetailsresponse.status.code', 402);
    }

    public function test_player_disabled_account_returns_402(): void
    {
        $this->account->update(['status' => 0]);

        $response = $this->postJson("{$this->baseUrl}/player", $this->playerPayload());

        $response->assertStatus(200)
            ->assertJsonPath('playerdetailsresponse.status.code', 402);
    }
}
