<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Lobby;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\AgentGame;
use App\Models\AgentGroupGameTag;
use App\Models\AgentGroupTag;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\GameName;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use App\Models\VendorInfo;
use App\Models\VendorInfoGame;
use Database\Seeders\LanguageSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Lobby/LobbyNonAuth_GamesByTagTest.php
 */
class LobbyNonAuth_GamesByTagTest extends TestCase
{
    private Agent $agent;

    private string $memberPassword = [REDACTED]';

    private string $loginToken = [REDACTED]';

    private string $defaultTag = 'hot';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(LanguageSeeder::class);

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->agent = $this->createAgentAndDomain();
        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_get_game_tag_success()
    {
        // Arrange
        $vendor = $this->createVendor();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'tag' => $this->defaultTag,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());
    }

    public function test_get_game_tag_with_game_names()
    {
        // Arrange
        $vendor = $this->createVendor();

        $gameIds = Game::where('vendor_id', $vendor->id)->pluck('id');
        GameName::create([
            'language_id' => 2,
            'game_id' => $gameIds[0],
            'name' => 'AAAAAAAAA',
            'admin' => 0,
        ]);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'tag' => $this->defaultTag,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $this->assertNotEmpty($data);
        $this->assertEquals('AAAAAAAAA', $data[0]['name']);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/lobby/games',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }

    private function createVendor(int $gameSort = 0): Vendor
    {
        $vendor = Vendor::factory()->create();

        $vendor_info = VendorInfo::factory()->create([
            'vendor_id' => $vendor->id,
        ]);

        $agent_vendor = AgentVendor::create([
            'agent_id' => $this->agent->id,
            'vendor_id' => $vendor->id,
            'vendor_info_id' => $vendor_info->id,
            'status' => 1,
            'self_status' => 1,
            'admin' => 0,
        ]);

        VendorCustomizedItem::create([
            'agent_group_id' => $this->agent->vendor_customize_group_id,
            'vendor_id' => $vendor->id,
            'type_id' => 0,
            'status' => 1,
        ]);

        $game = Game::create([
            'vendor_id' => $vendor->id,
            'type_id' => 0,
            'name' => 'test',
            'game_type' => 'TEST',
            'status' => 1,
            'open_window' => 0,
            'image' => 'https://example.invalid',
            'admin' => 0,
        ]);

        VendorInfoGame::create([
            'vendor_info_id' => $vendor_info->id,
            'game_id' => $game->id,
            'status' => 1,
        ]);

        AgentGame::create([
            'agent_id' => $this->agent->id,
            'game_id' => $game->id,
            'status' => 1,
        ]);

        $agentGroupTag = AgentGroupTag::firstOrCreate(
            [
                'agent_group_id' => 0,
                'tag_name' => $this->defaultTag,
            ],
            [
                'status' => 1,
            ]
        );

        AgentGroupGameTag::create([
            'agent_group_id' => $this->agent->vendor_customize_group_id,
            'game_id' => $game->id,
            'agent_group_tag_id' => $agentGroupTag->id,
            'status' => 1,
            'sort' => $gameSort,
        ]);

        return $vendor;
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                [
                    'id',
                    'vendor_id',
                    'vendor_name',
                    'type_id',
                    'rtp',
                    'name',
                    'lottery_pool',
                    'image',
                    'is_new',
                    'is_hot',
                    'disable',
                ],
            ],
        ];
    }
}
