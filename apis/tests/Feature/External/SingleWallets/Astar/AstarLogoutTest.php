<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use PHPUnit\Framework\Attributes\Group;

class AstarLogoutTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-8')]
    public function test_logout_success()
    {
        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/player/logout";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->postJson($url, ['gametoken' => $this->gametoken]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0');

        $this->assertDatabaseHas('astar_accounts', [
            'username' => $this->username,
            'session_token' => '',
        ]);
    }
}
