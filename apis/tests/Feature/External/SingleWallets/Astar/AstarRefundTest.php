<?php

declare(strict_types=1);

namespace Tests\Feature\External\SingleWallets\Astar;

use App\Vendors\Astar\Enums\BetRecordStatusEnum;
use App\Vendors\Models\AstarBetRecord;
use PHPUnit\Framework\Attributes\Group;

class AstarRefundTest extends AstarTestCase
{
    #[Group('rules/vendor-astar#R-21')]
    public function test_refund_success()
    {
        $round = 'VW1194217997';
        $betAmount = '50.00';

        AstarBetRecord::forceCreate([
            'bet_id' => $round,
            'bet' => $betAmount,
            'valid_bet' => $betAmount,
            'win_lose' => '-50.00',
            'status' => BetRecordStatusEnum::ROLLOUT,
            'vendor_username' => $this->username,
            'bet_time' => now()->toDateTimeString(),
            'game_code' => $this->game->game_type,
            'session_token' => $this->gametoken,
            'info' => json_encode([]),
        ]);

        // Simulate wallet after prior rollout deduction
        $this->wallet->update(['balance' => '950.0000']);

        $url = "{$this->baseurl}/gamepool/{$this->gamehall}/game/bet/refund";

        $response = $this->withHeaders([
            'Authorization' => $this->csToken,
        ])->postJson($url, [
            'gametoken' => $this->gametoken,
            'mtcode' => 'rel-refund-' . $round,
            'round' => $round,
            'amount' => $betAmount,
            'datetime' => now()->tz('-04:00')->toRfc3339String(),
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('status.code', '0')
            ->assertJsonPath('data.balance', '1000.0000');

        $this->assertDatabaseHas('astar_bet_records', [
            'bet_id' => $round,
            'status' => BetRecordStatusEnum::REFUND->value,
            'valid_bet' => 0,
            'win_lose' => 0,
        ]);
    }
}
