<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

class AstarRolloutDebugTest extends AstarTestCase
{
    public function test_rollout_debug()
    {
        $round = 'VW1194683567';

        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/table/rollout";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->postJson($url, [
            'gametoken' => $this->gametoken,
            'amount' => '20.00',
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

        $this->assertTrue(true);
    }
}
