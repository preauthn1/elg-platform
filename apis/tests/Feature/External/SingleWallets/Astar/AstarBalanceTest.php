<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use PHPUnit\Framework\Attributes\Group;

class AstarBalanceTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-7')]
    public function test_balance_success()
    {
        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/player/balance/{$this->username}";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->getJson($url);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1000.0000');
    }
}
