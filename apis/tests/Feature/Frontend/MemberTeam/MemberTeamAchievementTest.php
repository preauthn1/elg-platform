<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\MemberTeam;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\GameType;
use App\Models\Member;
use App\Models\ThreeHierarchyBonusReport;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * php artisan test tests/Feature/Frontend/MemberTeam/MemberTeamAchievementTest.php
 */
class MemberTeamAchievementTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    const ENDPOINT = '/frontend/api/member-team/achievement';

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

    public function test_member_team_achievement_success()
    {
        // Arrange
        $subMember_downlevel1 = $this->createMember($this->agent, ['username' => 'subMember_downlevel1']);

        $yesterday = now()->subDay()->startOfDay()->format('Y-m-d H:i:s');
        $twoDaysAgo = now()->subDays(2)->startOfDay()->format('Y-m-d H:i:s');

        $gameType = GameType::create([
            'name' => 'test',
            'status' => 1,
            'admin' => 1,
        ]);

        $yesterdayReport = ThreeHierarchyBonusReport::create([
            'member_id' => $this->member->id,
            'from_member_id' => $subMember_downlevel1->id,
            'game_type_id' => $gameType->id,
            'summary_time' => $yesterday,
            'bet' => 100,
            'down_rebate' => 100,
            'down2_rebate' => 100,
            'rebate' => 100,
            'down_bet' => 100,
            'down2_bet' => 100,
        ]);

        $twoDaysAgoReport = ThreeHierarchyBonusReport::create([
            'member_id' => $this->member->id,
            'from_member_id' => $subMember_downlevel1->id,
            'game_type_id' => $gameType->id,
            'summary_time' => $twoDaysAgo,
            'bet' => 100,
            'down_rebate' => 100,
            'down2_rebate' => 100,
            'rebate' => 100,
            'down_bet' => 100,
            'down2_bet' => 100,
        ]);

        // Act
        $response = $this->requestApi(self::ENDPOINT, []);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals(300, $data['total']['totalAchievement']);
        $this->assertEquals(100, $data['total']['directPlayersAchievement']);
        $this->assertEquals(200, $data['total']['teamAchievement']);

        $this->assertEquals(300, $data['data'][0]['totalAchievement']);
        $this->assertEquals(100, $data['data'][0]['directPlayersAchievement']);
        $this->assertEquals(200, $data['data'][0]['teamAchievement']);
        $this->assertEquals($gameType->name, $data['data'][0]['name']);
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                'data' => [
                    [
                        'name',
                        'totalAchievement',
                        'directPlayersAchievement',
                        'teamAchievement',
                    ],
                ],
                'total' => [
                    'totalAchievement',
                    'directPlayersAchievement',
                    'teamAchievement',
                ],
            ],
        ];
    }
}
