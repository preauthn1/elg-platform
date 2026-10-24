<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use PHPUnit\Framework\Attributes\Group;

class AstarDetailTokenTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-23')]
    public function test_detailtoken_success()
    {
        $betId = 'test_bet_id';
        \App\Vendors\Models\AstarBetRecord::forceCreate([
            'bet_id' => $betId,
            'bet' => 10,
            'valid_bet' => 10,
            'vendor_username' => $this->username,
            'bet_time' => now()->toDateTimeString(),
            'session_token' => 'token123',
            'game_code' => $this->game->game_type,
            'info' => json_encode([]),
        ]);

        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/game/detailtoken";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->postJson($url, [
            'gametoken' => $this->gametoken,
            'token' => 'token123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.account', $this->username)
            ->assertJsonPath('data.roundid', $betId);
    }
}
