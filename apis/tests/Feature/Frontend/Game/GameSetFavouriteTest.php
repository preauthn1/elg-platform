<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Game;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class GameSetFavouriteTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken;

    const API_GAME_SET_FAVOURITE = '/frontend/api/game/set-favourite';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(DecryptRequest::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_game_set_favourite_success_status_true()
    {
        $vendor1 = $this->createBasicVendorGames($this->agent);
        $game1 = $vendor1->games->first();

        $response = $this->requestApi(self::API_GAME_SET_FAVOURITE, [
            'game_id' => $game1->id,
            'status' => true,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('favourite_games', [
            'member_id' => $this->member->id,
            'game_id' => $game1->id,
            'status' => 1,
        ]);
    }

    public function test_game_set_favourite_failed_status_false()
    {
        $vendor1 = $this->createBasicVendorGames($this->agent);
        $game1 = $vendor1->games->first();

        $response = $this->requestApi(self::API_GAME_SET_FAVOURITE, [
            'game_id' => $game1->id,
            'status' => false,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseMissing('favourite_games', [
            'member_id' => $this->member->id,
            'game_id' => $game1->id,
        ]);
    }

    public function test_game_set_favourite_failed_status_true_then_false()
    {
        $vendor1 = $this->createBasicVendorGames($this->agent);
        $game1 = $vendor1->games->first();

        $this->requestApi(self::API_GAME_SET_FAVOURITE, [
            'game_id' => $game1->id,
            'status' => true,
        ]);
        $response = $this->requestApi(self::API_GAME_SET_FAVOURITE, [
            'game_id' => $game1->id,
            'status' => false,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value);

        $this->assertDatabaseHas('favourite_games', [
            'member_id' => $this->member->id,
            'game_id' => $game1->id,
            'status' => 0,
        ]);
    }
}
