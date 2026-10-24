<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberMission;
use App\Models\Mission;
use App\Models\MissionAvailableAgent;
use App\Models\MissionClaimRecord;
use App\Services\Frontend\Missions\MissionService;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Tests\TestCase;

/**
 * MissionService 任務服務測試
 *
 * php artisan test tests/Unit/Frontend/MissionServiceTest.php
 */
class MissionServiceTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private MissionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        // 创建测试数据
        $this->agent = $this->createAgentAndDomain();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
            'admin' => 0,
        ]);

        $this->service = new MissionService;
    }

    public function test_get_mission_list()
    {
        // Arrange
        $missions = $this->createMissions();

        // Act
        $result = $this->service->list($this->member, 1);

        // Assert
        $this->assertEquals(count($missions), count($result));
    }

    public function test_get_mission_list_without_bind_agent()
    {
        // Arrange
        $missions = $this->createMissions(3, false);

        // Act
        $result = $this->service->list($this->member, 1);

        // Assert
        $this->assertEquals(0, count($result));
    }

    public function test_success_claim_mission()
    {
        // Arrange
        $missions = $this->createMissions(3);

        $targetMission = $missions[0];
        [$summaryStartDate, $summaryEndDate] = $targetMission->reset_type->getTimeRange($targetMission);
        MemberMission::create([
            'summary_time' => $summaryStartDate,
            'mission_id' => $targetMission->id,
            'member_id' => $this->member->id,
            'progress' => 10,
            'is_claimed' => 0,
        ]);

        $timeOffset = now()->toFrontendTime()->offsetHours;
        $memberMission = MemberMission::select('*')
            ->selectRaw('DATE_FORMAT(DATE_ADD(summary_time, INTERVAL ? HOUR), ?) AS date', [$timeOffset, '%Y-%m-%d'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(summary_time, INTERVAL ? HOUR), ?) AS week', [$timeOffset, '%x-%v'])
            ->selectRaw('DATE_FORMAT(DATE_ADD(summary_time, INTERVAL ? HOUR), ?) AS month', [$timeOffset, '%Y-%m'])
            ->where('mission_id', $targetMission->id)
            ->where('member_id', $this->member->id)
            ->first();

        MissionClaimRecord::create([
            'mission_id' => $targetMission->id,
            'member_id' => $this->member->id,
            'reward_type' => 'balance',
            'reward_value' => '10',
            'date' => $memberMission->date,
            'week' => $memberMission->week,
            'month' => $memberMission->month,
            'finish_time' => Carbon::now(),
        ]);

        $this->member->balance = 0;
        $this->member->save();

        $reward = $targetMission->info['reward_config']['value'];

        // Act
        $this->service->claim($this->member, $targetMission->id);
        $this->member->refresh();
        $targetMission->refresh();

        $memberMission = MemberMission::where('mission_id', $targetMission->id)
            ->where('member_id', $this->member->id)
            ->first();

        // Assert
        $this->assertEquals(1, $memberMission->is_claimed);
        $this->assertEquals(10, $memberMission->progress);
        $this->assertEquals((float) $reward, (float) $this->member->balance);
    }

    public function test_failed_claim_mission_condition_not_met()
    {
        // Assert, exception should be set first.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage(__('error_message.3003'));

        // Arrange
        $missions = $this->createMissions(3);

        $targetMission = $missions[0];
        [$summaryStartDate] = $targetMission->reset_type->getTimeRange($targetMission);
        MemberMission::create([
            'summary_time' => $summaryStartDate,
            'mission_id' => $targetMission->id,
            'member_id' => $this->member->id,
            'progress' => 1,
            'is_claimed' => 0,
        ]);

        // Act
        $this->service->claim($this->member, $targetMission->id);
    }

    private function createMissions(int $count = 3, bool $bindAgent = true)
    {
        $missions = Mission::factory()->count($count)->create([
            'status' => 1,
            'agent_id' => $this->agent->id,
        ]);

        if (!$bindAgent) {
            return $missions;
        }

        $availableAgents = [];
        foreach ($missions as $mission) {
            $availableAgents[] = [
                'mission_id' => $mission->id,
                'agent_id' => $this->agent->id,
            ];
        }

        MissionAvailableAgent::insert($availableAgents);

        return $missions;
    }
}
