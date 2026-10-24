<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\Lobby;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\AgentGame;
use App\Models\AgentVendor;
use App\Models\Game;
use App\Models\Member;
use App\Models\MemberAccessToken;
use App\Models\PrizeContentFlowLimit;
use App\Models\Vendor;
use App\Models\VendorCustomizedItem;
use App\Models\VendorInfo;
use App\Models\VendorMaintenance;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/Lobby/LobbyVendorListTest.php
 */
class LobbyVendorListTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $memberPassword = [REDACTED]';

    private string $loginToken = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $_SERVER['REQUEST_METHOD'] = 'POST';

        $this->agent = $this->createAgentAndDomain();
        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();

        $this->member = Member::create([
            'balance' => 0.00,
            'username' => 'testuser',
            'nickname' => 'testuser',
            'parent' => $this->agent->id,
            'status' => 1,
            'password' => Hash::make($this->memberPassword),
            'admin' => 0,
        ]);

        MemberAccessToken:[REDACTED]
            'member_id' => $this->member->id,
            'token' => $this->loginToken,
            'expire_date' => now()->copy()->addDays(1)->format('Y-m-d H:i:s'),
            'admin' => 0,
        ]);
    }

    public function test_get_vendor_list_success()
    {
        // Arrange
        $this->createVendor();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());
    }

    public function test_get_vendor_list_with_prize_content_flow_limit()
    {
        // Arrange
        $vendor1 = $this->createVendor();
        $vendor2 = $this->createVendor();

        PrizeContentFlowLimit::create([
            'prize_content_id' => 1,
            'vendor_id' => $vendor1->id,
            'game_type_id' => 0,
            'admin' => 0,
        ]);

        PrizeContentFlowLimit::create([
            'prize_content_id' => 2,
            'vendor_id' => $vendor2->id,
            'game_type_id' => 2,
            'admin' => 0,
        ]);

        $this->member->event_mode = 1;
        $this->member->save();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        $data = json_decode($response->getContent(), true)['data'];
        $vendor1Data = collect($data)->firstWhere('id', $vendor1->id);
        $vendor2Data = collect($data)->firstWhere('id', $vendor2->id);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $this->assertEquals(2, count($data));
        $this->assertEquals(1, $vendor1Data['status']);
        $this->assertEquals(4, $vendor2Data['status']); // Wrong event mode vendors, status should be 4
    }

    public function test_get_vendor_list_with_vendor_maintenance()
    {
        // Arrange
        $vendor1 = $this->createVendor();
        $vendor2 = $this->createVendor();

        $systemNowTime = now();

        $startTime = $systemNowTime->copy()->subDays(1);
        $endTime = $systemNowTime->copy()->addDays(1);
        VendorMaintenance::create([
            'vendor_id' => $vendor2->id,
            'agent_id' => $this->agent->id,
            'status' => 1,
            'start_time' => $startTime->format('Y-m-d H:i:s'),
            'end_time' => $endTime->format('Y-m-d H:i:s'),
            'admin' => 0,
        ]);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        $data = json_decode($response->getContent(), true)['data'];
        $vendor1Data = collect($data)->firstWhere('id', $vendor1->id);
        $vendor2Data = collect($data)->firstWhere('id', $vendor2->id);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $this->assertEquals(2, count($data));
        $this->assertEmpty($vendor1Data['maintenance_end_time']);
        $this->assertNotEmpty($vendor2Data['maintenance_end_time']);
        $this->assertEquals(\Carbon\Carbon::parse($endTime)->toFrontendTime()->format('Y-m-d H:i:s'), $vendor2Data['maintenance_end_time']);
    }

    public function test_get_vendor_list_with_rolling_chip_status_enabled()
    {
        // Arrange
        $vendor1 = $this->createVendor();
        $vendor2 = $this->createVendor();

        VendorInfo::where('vendor_id', $vendor1->id)->update([
            'rolling_chip_status' => 1,
        ]);

        $systemNowTime = now();

        VendorMaintenance::create([
            'vendor_id' => $vendor2->id,
            'agent_id' => $this->agent->id,
            'status' => 1,
            'start_time' => $systemNowTime->copy()->subDays(1)->format('Y-m-d H:i:s'),
            'end_time' => $systemNowTime->copy()->addDays(1)->format('Y-m-d H:i:s'),
            'admin' => 0,
        ]);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        $data = json_decode($response->getContent(), true)['data'];
        $vendor1Data = collect($data)->firstWhere('id', $vendor1->id);
        $vendor2Data = collect($data)->firstWhere('id', $vendor2->id);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $this->assertEquals(2, count($data));
        $this->assertEquals(1, $vendor1Data['giftcode']);
        $this->assertEquals(0, $vendor2Data['giftcode']);
    }

    public function test_get_vendor_list_empty_data()
    {
        // Arrange
        $vendor1 = $this->createVendor();
        $vendor2 = $this->createVendor();

        AgentVendor::where('vendor_id', $vendor1->id)->delete();
        AgentVendor::where('vendor_id', $vendor2->id)->delete();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
                'message' => __('error_message.1000'),
                'data' => [],
            ]);
    }

    public function test_get_vendor_list_with_vendor_ids()
    {
        // Arrange
        $vendor1 = $this->createVendor();
        $vendor2 = $this->createVendor();

        // Act
        $response = $this->act([
            'domain' => $this->domain,
            'vendor_ids' => [$vendor1->id],
        ]);

        // Assert
        $response
            ->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJson([
                'code' => HttpCode::HTTP_OK->value,
            ])
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = json_decode($response->getContent(), true)['data'];
        $vendor1Data = collect($data)->firstWhere('id', $vendor1->id);
        $vendor2Data = collect($data)->firstWhere('id', $vendor2->id);

        $this->assertEquals(1, count($data));
        $this->assertNotNull($vendor1Data);
        $this->assertNull($vendor2Data);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            'frontend/api/lobby/vendors',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }

    private function createVendor(): Vendor
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
            'admin' => 0,
        ]);

        AgentGame::create([
            'agent_id' => $this->agent->id,
            'game_id' => $game->id,
            'status' => 1,
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
                    'item_type',
                    'id',
                    'game_id',
                    'type_id',
                    'path',
                    'name',
                    'image',
                    'front_page_image',
                    'app_icon',
                    'is_new',
                    'is_hot',
                    'only_one_game',
                    'need_login',
                    'giftcode',
                    'status',
                    'currency_name',
                    'rate',
                    'maintenance_end_time',
                ],
            ],
        ];
    }
}
