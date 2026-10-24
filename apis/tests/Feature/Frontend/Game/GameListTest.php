<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Game;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class GameListTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken;

    const API_GAME_LIST = '/frontend/api/game/list';

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

    public function test_game_list_success()
    {
        $vendor1 = $this->createBasicVendorGames($this->agent);
        $vendor2 = $this->createBasicVendorGames($this->agent);

        $response = $this->requestApi(self::API_GAME_LIST, [
            'domain' => $this->domain,
            'page' => 1,
            'per_page' => 10,
        ]);
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals($vendor2->id, $data['games'][0]['vendor_id']);
        $this->assertEquals($vendor1->id, $data['games'][1]['vendor_id']);
    }

    public function test_game_list_success_different_agent()
    {
        $anotherAgent = Agent::factory()->create([
            'username' => 'anotherAgent',
            'vendor_customize_group_id' => $this->agent->vendor_customize_group_id,
        ]);
        $anotherAgent->hierarchy_path = "/{$anotherAgent->id}";
        $anotherAgent->hierarchy = "[-1,{$anotherAgent->id},-2]";
        $anotherAgent->save();

        $vendor_sameAgent = $this->createBasicVendorGames($this->agent);
        $vendor_anotherAgent = $this->createBasicVendorGames($anotherAgent);

        $response = $this->requestApi(self::API_GAME_LIST, [
            'domain' => $this->domain,
            'page' => 1,
            'per_page' => 10,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals($vendor_sameAgent->id, $data['games'][0]['vendor_id']);
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'data' => [
                'games' => [
                    [
                        'id',
                        'vendor_id',
                        'type_id',
                        'lottery_pool',
                        'name',
                        'image',
                        'is_favourite',
                        'disable',
                    ],
                ],
                'redirect_url',
            ],
        ];
    }
}
