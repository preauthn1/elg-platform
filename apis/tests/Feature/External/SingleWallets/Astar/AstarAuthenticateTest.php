<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use PHPUnit\Framework\Attributes\Group;

class AstarAuthenticateTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-5')]
    public function test_authenticate_success()
    {
        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/player/auth";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->postJson($url, ['gametoken' => $this->gametoken]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.account', $this->username);
    }

    #[Group('rules/vendor-astar#R-3')]
    public function test_authenticate_failed_with_wrong_token()
    {
        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/player/auth";

        $response = $this->withHeaders([
            'Authorization' => 'wrong_token',
        ])->postJson($url, ['gametoken' => $this->gametoken]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '4'); // TOKEN_INVALID
    }
}
