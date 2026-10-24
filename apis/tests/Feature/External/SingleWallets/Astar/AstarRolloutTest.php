<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use PHPUnit\Framework\Attributes\Group;

class AstarRolloutTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-10')]
    public function test_rollout_success()
    {
        $round = 'VW1194683567';
        $amount = '20.00';

        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/table/rollout";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->postJson($url, [
            'gametoken' => $this->gametoken,
            'amount' => $amount,
            'betpoint' => 'BANKER:20.00',
            'datetime' => now()->tz('-04:00')->toRfc3339String(),
            'gamecode' => $this->game->game_type,
            'id' => $this->username,
            'ip' => '0.0.0.0',
            'mtcode' => 'rel-rollout-' . $round,
            'round' => $round,
            'roundnumber' => '246361978',
            'tableid' => '3006',
            'tablename' => 'BACCARAT#LV07',
            'tabletype' => '1',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '980.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'bet' => $amount,
        ]);
    }
}
