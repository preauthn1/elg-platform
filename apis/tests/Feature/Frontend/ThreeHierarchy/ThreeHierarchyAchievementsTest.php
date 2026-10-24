<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThreeHierarchy;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\GameType;
use App\Models\Member;
use App\Models\ThreeHierarchyBonusReport;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThreeHierarchyAchievementsTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_three_hierarchy_achievements_success()
    {
        // Arrange
        $vendor = $this->createBasicVendorGames($this->agent);
        \DB::enableQueryLog();
        $gameType = GameType::create([
            'parent' => 0,
            'name' => 'test',
            'status' => 1,
            'admin' => 0,
        ]);

        $nowFrontendTime = \Carbon\Carbon::now()->toFrontendTime();
        ThreeHierarchyBonusReport::create([
            'summary_time' => ($nowFrontendTime->hour >= 23) ? $nowFrontendTime->format('Y-m-d 00:00:00') : $nowFrontendTime->subDay()->format('Y-m-d 00:00:00'),
            'rebate_type' => 1,
            'reward_type' => 2,
            'agent_id' => 0,
            'member_id' => $this->member->id,
            'from_member_id' => $this->member->id,
            'vendor_id' => $vendor->id,
            'game_type_id' => $gameType->id,
            'bet' => 1800,
            'down_bet' => 0,
            'down2_bet' => 0,
            'rebate' => 9,
            'down_rebate' => 0,
            'down2_rebate' => 0,
        ]);

        // Act
        $response = $this->act([
            'page' => 1,
            'per_page' => 10,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals($this->member->username, $data['items'][0]['username']);
        $this->assertEquals(1, $data['items'][0]['rebate_type']);
        $this->assertEquals($vendor->name, $data['items'][0]['vendor']);
        $this->assertEquals($gameType->name, $data['items'][0]['game_type']);
        $this->assertEquals(1800, $data['items'][0]['bet']);
        $this->assertEquals(9, $data['items'][0]['reward']);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/three-hierarchy/achievements',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                'items' => [
                    [
                        'date',
                        'rebate_type',
                        'username',
                        'vendor',
                        'game_type',
                        'bet',
                        'reward',
                    ],
                ],
            ],
        ];
    }
}
