<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Game;

use App\Enums\General\HttpCode;
use App\Enums\Lobby\VendorStatusEnum;
use App\Enums\Vendor\VendorMaintainTypeEnum;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test --filter=GameShowTest
 */
class GameShowTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken;

    public const string API_GAME_SHOW = '/frontend/api/game/show';

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

    public function test_game_show_success()
    {
        $vendor1 = $this->createBasicVendorGames($this->agent);
        $game1 = $vendor1->games->first();

        $response = $this->requestApi(self::API_GAME_SHOW, [
            'game_id' => $game1->id,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals($game1->id, $data['id']);
        $this->assertEquals($vendor1->id, $data['vendor_id']);
        $this->assertEquals($game1->type_id, $data['type_id']);
        $this->assertEquals($game1->lottery_pool, $data['lottery_pool']);
        $this->assertEquals($game1->name, $data['name']);
        $this->assertEquals($game1->image, $data['image']);
        $this->assertEquals($game1->is_new, $data['is_new']);
        $this->assertEquals($game1->is_hot, $data['is_hot']);
    }

    public function test_game_show_failed_in_maintenance()
    {
        $vendor1 = $this->createBasicVendorGames($this->agent);
        $game1 = $vendor1->games->first();

        $vendor1->vendorMaintenances()->create([
            'type' => VendorMaintainTypeEnum::BOTH_CLOSED->value,
            'start_time' => now()->subDays(1),
            'end_time' => now()->addDays(1),
            'agent_id' => $this->agent->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $response = $this->requestApi(self::API_GAME_SHOW, [
            'game_id' => $game1->id,
        ]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals(VendorStatusEnum::MAINTENANCE->value, $data['status']);
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'data' => [
                'id',
                'vendor_id',
                'vendor_name',
                'status',
                'type_id',
                'lottery_pool',
                'name',
                'image',
                'is_new',
                'is_hot',
                'join_bet_event',
                'join_win_event',
            ],
        ];
    }
}
