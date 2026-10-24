<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\MemberTeam;

use App\Enums\General\HttpCode;
use App\Http\Middleware\Frontend\DecryptRequest;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\ThreeHierarchyBonusReport;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

class MemberTeamInfoTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    const ENDPOINT = '/frontend/api/member-team/info';

    protected function setUp(): void
    {
        parent::setUp();

        // 凍結到「今日 frontend 正午」，避免 created_at / 視窗下界在 frontend 午夜首秒撞嚴格邊界造成 flaky。
        // Laravel TestCase tearDown 會自動 reset setTestNow，無需手動清除。
        Carbon::setTestNow(Carbon::now()->toFrontendTime()->startOfDay()->addHours(12)->toSystemTime());

        $this->withoutMiddleware(DecryptRequest::class);
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_member_team_info_success()
    {
        // Arrange
        $subMember_downlevel1 = $this->createMember($this->agent, ['username' => 'subMember_downlevel1']);
        $subMember_downlevel2 = $this->createMember($this->agent, ['username' => 'subMember_downlevel2']);

        MemberAgent::create([
            'member_id' => $this->member->id,
            'parent_id' => $this->agent->id,
            'agent_mode' => 1,
            'agent_level' => 1,
            'agent_relation' => "0,{$this->member->id},",
        ]);

        MemberAgent::create([
            'member_id' => $subMember_downlevel1->id,
            'topper_member_id' => $this->member->id,
            'parent_id' => $this->agent->id,
            'agent_mode' => 1,
            'agent_level' => 2,
            'agent_relation' => "0,{$this->member->id},{$subMember_downlevel1->id},",
        ]);

        MemberAgent::create([
            'member_id' => $subMember_downlevel2->id,
            'topper_member_id' => $subMember_downlevel1->id,
            'parent_id' => $this->agent->id,
            'agent_mode' => 1,
            'agent_level' => 3,
            'agent_relation' => "0,{$this->member->id},{$subMember_downlevel1->id},{$subMember_downlevel2->id},",
        ]);

        $yesterday = now()->subDay()->startOfDay()->format('Y-m-d H:i:s');
        $twoDaysAgo = now()->subDays(2)->startOfDay()->format('Y-m-d H:i:s');

        $yesterdayReport = ThreeHierarchyBonusReport::create([
            'member_id' => $this->member->id,
            'from_member_id' => $subMember_downlevel1->id,
            'summary_time' => $yesterday,
            'down_rebate' => 100,
            'down2_rebate' => 100,
            'bet' => 100,
            'rebate' => 100,
            'down_bet' => 100,
            'down2_bet' => 100,
        ]);

        $twoDaysAgoReport = ThreeHierarchyBonusReport::create([
            'member_id' => $this->member->id,
            'from_member_id' => $subMember_downlevel1->id,
            'summary_time' => $twoDaysAgo,
            'down_rebate' => 100,
            'down2_rebate' => 100,
            'rebate' => 100,
            'down_bet' => 100,
            'down2_bet' => 100,
        ]);

        // Act
        $response = $this->requestApi(self::ENDPOINT, [
            'domain' => $this->domain,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals(1, $data['directPlayerCount']);
        $this->assertEquals(1, $data['addedToday']);
        $this->assertEquals(3, $data['addedTodayGroupMember']);
        $this->assertEquals(3, $data['totalGroupMember']);
        $this->assertEquals(300, $data['todayCommission']);
    }

    private function basicResponseDataStructure(): array
    {
        return [
            'code',
            'message',
            'data' => [
                'directPlayerCount',
                'addedToday',
                'addedTodayGroupMember',
                'totalGroupMember',
                'todayCommission',
                'is_payout',
                'code',
                'link',
            ],
        ];
    }
}
