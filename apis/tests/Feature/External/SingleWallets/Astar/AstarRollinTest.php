<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use App\Vendors\Astar\Enums\BetRecordStatusEnum;
use App\Vendors\Models\AstarBetRecord;
use PHPUnit\Framework\Attributes\Group;

class AstarRollinTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-16')]
    public function test_rollin_success()
    {
        $round = 'VW1194683621';
        $bet = '40.00';
        $validbet = '40.00';
        $win = '40.00';

        AstarBetRecord::forceCreate([
            'bet_id' => $round,
            'bet' => $bet,
            'valid_bet' => $validbet,
            'win_lose' => '-40.00',
            'status' => BetRecordStatusEnum::ROLLOUT,
            'vendor_username' => $this->username,
            'bet_time' => now()->toDateTimeString(),
            'game_code' => $this->game->game_type,
            'session_token' => $this->gametoken,
            'info' => json_encode([]),
        ]);

        // Simulate wallet after prior rollout deduction
        $this->wallet->update(['balance' => '960.0000']);

        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/table/rollin";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->postJson($url, [
            'gametoken' => $this->gametoken,
            'amount' => '80.00',
            'bet' => $bet,
            'betpoint' => 'BANKER:40.00@40.00@40.00#',
            'datetime' => now()->tz('-04:00')->toRfc3339String(),
            'gamecode' => $this->game->game_type,
            'id' => $this->username,
            'mtcode' => 'rel-rollin-' . $round,
            'rake' => '0.00',
            'result' => 'BANKER',
            'roomfee' => '0.00',
            'round' => $round,
            'roundnumber' => '246362074',
            'tableid' => '3006',
            'tablename' => 'BACCARAT#LV07',
            'tabletype' => '1',
            'validbet' => $validbet,
            'win' => $win,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1040.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'win_lose' => $win,
            'status' => BetRecordStatusEnum::ROLLIN->value,
        ]);
    }
}
