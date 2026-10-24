<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\MemberTeam;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\GameType;
use App\Models\Member;
use App\Models\ThreeHierarchyBonusReport;
use App\Models\ThreeHierarchySummaryBonus;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class MemberTeamAgentAchievementTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    const ENDPOINT = '/frontend/api/member-team/agent-achievement';

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

    public function test_member_team_agent_achievement_success()
    {
        // Arrange
        $subMember_downlevel1 = $this->createMember($this->agent, ['username' => 'subMember_downlevel1']);

        $yesterday = now()->subDay()->startOfDay()->format('Y-m-d H:i:s');
        $twoDaysAgo = now()->subDays(2)->startOfDay()->format('Y-m-d H:i:s');
        $threeDaysAgo = now()->subDays(3)->startOfDay()->format('Y-m-d H:i:s');

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

        $threeDaysAgoReport = ThreeHierarchyBonusReport::create([
            'member_id' => $this->member->id,
            'from_member_id' => $subMember_downlevel1->id,
            'game_type_id' => $gameType->id,
            'summary_time' => $threeDaysAgo,
            'bet' => 100,
            'down_rebate' => 100,
            'down2_rebate' => 100,
            'rebate' => 100,
            'down_bet' => 100,
            'down2_bet' => 100,
        ]);

        ThreeHierarchySummaryBonus::create([
            'member_id' => $this->member->id,
            'bonus_amount' => 300,
            'admin' => 1,
        ]);

        // Act
        $response = $this->requestApi(self::ENDPOINT, []);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals(300, $data['totalAchievement']);
        $this->assertEquals(100, $data['directPlayersAchievement']);
        $this->assertEquals(200, $data['teamAchievement']);
        $this->assertEquals(300, $data['lastpPeriodCommission']);
        $this->assertEquals(300, $data['historicalTotalCommission']);

    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                'totalAchievement',
                'directPlayersAchievement',
                'teamAchievement',
                'lastpPeriodCommission',
                'historicalTotalCommission',
            ],
        ];
    }
}
