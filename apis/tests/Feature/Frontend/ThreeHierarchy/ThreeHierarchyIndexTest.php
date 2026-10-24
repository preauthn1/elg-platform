<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThreeHierarchy;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\Member;
use App\Models\MemberAgent;
use App\Models\SummaryBetRecord;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThreeHierarchyIndexTest extends TestCase
{
    private Agent $agent;

    private Member $member;

    private string $loginToken = [REDACTED]';

    protected function setUp(): void
    {
        parent::setUp();

        // 凍結到「今日 frontend 正午」，避免 fixture summary_time = now() 撞 endpoint 的
        // `summary_time > startOfDay` 嚴格邊界（frontend 午夜首秒相等而被排除）造成 flaky。
        // Laravel TestCase tearDown 會自動 reset setTestNow，無需手動清除。
        Carbon::setTestNow(Carbon::now()->toFrontendTime()->startOfDay()->addHours(12)->toSystemTime());

        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
        $this->loginToken = [REDACTED];

        $this->agent->vendor_customize_group_id = 1;
        $this->agent->save();
    }

    public function test_three_hierarchy_index_success()
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

        SummaryBetRecord::create([
            'member_id' => $subMember_downlevel2->id,
            'summary_time' => now(),
            'data_time' => now(),
            'vendor_id' => 1,
            'game_id' => 1,
            'bet' => 100,
            'win_lose' => 100,
        ]);

        // Act
        $response = $this->act([
            'domain' => $this->domain,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals($this->member->username, $data['username']);
        $this->assertEmpty($data['topUsername']);
        $this->assertEquals(1, $data['directPlayerCount']);
        $this->assertEquals(1, $data['doubleDirectPlayerCount']);
        $this->assertEquals(2, $data['addedToday']);
        $this->assertEquals(1, $data['activeToday']);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/three-hierarchy',
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
                'topUsername',
                'username',
                'directPlayerCount',
                'doubleDirectPlayerCount',
                'addedToday',
                'activeToday',
                'code',
                'link',
            ],
        ];
    }
}
