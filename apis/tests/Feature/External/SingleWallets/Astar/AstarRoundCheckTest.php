<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use App\Vendors\Astar\Enums\BetRecordStatusEnum;
use PHPUnit\Framework\Attributes\Group;

class AstarRoundCheckTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-24')]
    public function test_roundcheck_success()
    {
        $betId = 'check_bet_id';
        \App\Vendors\Models\AstarBetRecord::forceCreate([
            'bet_id' => $betId,
            'bet' => 10,
            'valid_bet' => 10,
            'status' => BetRecordStatusEnum::ROLLOUT,
            'vendor_username' => $this->username,
            'bet_time' => now()->toDateTimeString(),
            'game_code' => $this->gamehall . 'BACCARAT',
            'session_token' => $this->gametoken,
            'info' => json_encode([]),
        ]);

        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/game/roundcheck";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->getJson($url . '?' . http_build_query([
            'gametoken' => $this->gametoken,
            'fromdate' => now()->subDay()->toRfc3339String(),
            'todate' => now()->addDay()->toRfc3339String(),
        ]));

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonFragment([$betId]);
    }
}
