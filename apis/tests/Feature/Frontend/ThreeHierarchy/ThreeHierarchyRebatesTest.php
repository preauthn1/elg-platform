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

/**
 * php artisan test tests/Feature/Frontend/ThreeHierarchy/ThreeHierarchyRebatesTest.php
 */
class ThreeHierarchyRebatesTest extends TestCase
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

    public function test_three_hierarchy_rebates_success()
    {
        // Arrange
        $vendor = $this->createBasicVendorGames($this->agent);
        $gameType = GameType::create([
            'parent' => 0,
            'name' => 'test',
            'status' => 1,
            'admin' => 0,
        ]);

        // 兩筆報表必須落在不同的 summary_time 日期，才會在 rebates 端被分成兩個群組（orderByDesc('summary_time')）。
        // 以固定偏移（昨天 / 前天）產生，避免依賴 Carbon in-place 變異或當下時辰造成跨午夜時段的 flaky。
        $baseFrontendDate = \Carbon\Carbon::now()->toFrontendTime();
        $report1 = ThreeHierarchyBonusReport::create([
            'summary_time' => $baseFrontendDate->copy()->subDay()->format('Y-m-d 00:00:00'),
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
            // 'created_at' => now(),
            // 'updated_at' => now(),
        ]);

        $report2 = ThreeHierarchyBonusReport::create([
            'summary_time' => $baseFrontendDate->copy()->subDays(2)->format('Y-m-d 00:00:00'),
            'rebate_type' => 2,
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
            // 'created_at' => now(),
            // 'updated_at' => now(),
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
        $this->assertEquals(\Carbon\Carbon::parse($report1->created_at)->toFrontendTime()->format('Y-m-d H:i:s'), $data['items'][0]['date']);
        $this->assertEquals($report1->rebate, $data['items'][0]['rebate_1']);
        $this->assertEquals($report1->down_rebate, $data['items'][0]['rebate_2']);
        $this->assertEquals($report1->down2_rebate, $data['items'][0]['rebate_3']);
        $this->assertEquals(0, $data['items'][0]['lose_rebate_1']);
        $this->assertEquals(0, $data['items'][0]['lose_rebate_2']);
        $this->assertEquals(0, $data['items'][0]['lose_rebate_3']);
        $this->assertEquals(($report1->rebate + $report1->down_rebate + $report1->down2_rebate), $data['items'][0]['reward']);

        $this->assertEquals(\Carbon\Carbon::parse($report2->created_at)->toFrontendTime()->format('Y-m-d H:i:s'), $data['items'][1]['date']);
        $this->assertEquals(0, $data['items'][1]['rebate_1']);
        $this->assertEquals(0, $data['items'][1]['rebate_2']);
        $this->assertEquals(0, $data['items'][1]['rebate_3']);
        $this->assertEquals($report2->rebate, $data['items'][1]['lose_rebate_1']);
        $this->assertEquals($report2->down_rebate, $data['items'][1]['lose_rebate_2']);
        $this->assertEquals($report2->down2_rebate, $data['items'][1]['lose_rebate_3']);
        $this->assertEquals(($report2->rebate + $report2->down_rebate + $report2->down2_rebate), $data['items'][1]['reward']);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/three-hierarchy/rebates',
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
                        'rebate_1',
                        'rebate_2',
                        'rebate_3',
                        'lose_rebate_1',
                        'lose_rebate_2',
                        'lose_rebate_3',
                        'reward',
                    ],
                ],
            ],
        ];
    }
}
