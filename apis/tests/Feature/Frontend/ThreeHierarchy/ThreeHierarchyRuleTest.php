<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThreeHierarchy;

use App\Enums\General\HttpCode;
use App\Models\Agent;
use App\Models\GameType;
use App\Models\Member;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThreeHierarchyRuleTest extends TestCase
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

    public function test_three_hierarchy_rule_success()
    {
        $vendor = $this->createBasicVendorGames($this->agent);
        $gameType = GameType::create([
            'parent' => 0,
            'name' => 'test',
            'status' => 1,
            'admin' => 0,
        ]);

        DB::table('three_hierarchy_proportions')->insert([
            'agent_group_id' => $this->agent->vendor_customize_group_id,
            'type' => 1,
            'vendor_id' => $vendor->id,
            'game_type_id' => $gameType->id,
            'proportion_1' => 0.3,
            'proportion_2' => 0.2,
            'proportion_3' => 0.1,
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->act([]);

        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure([
                'code',
                'message',
                'data' => [
                    'items' => [
                        [
                            'vendor_id',
                            'vendor_name',
                            'vendor_image',
                            'game_type_name',
                            'type',
                            'proportion_1',
                            'proportion_2',
                            'proportion_3',
                        ],
                    ],
                ],
            ]);

        $data = $response->json('data.items');

        $this->assertCount(1, $data);
        $this->assertEquals($vendor->id, $data[0]['vendor_id']);
        $this->assertEquals($vendor->name, $data[0]['vendor_name']);
        $this->assertEquals('test', $data[0]['game_type_name']);
        $this->assertEquals(1, $data[0]['type']);
        $this->assertEquals(30, $data[0]['proportion_1']);
        $this->assertEquals(20, $data[0]['proportion_2']);
        $this->assertEquals(10, $data[0]['proportion_3']);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/three-hierarchy/rule',
            $this->encryptParams($params),
            [
                'Accept-Language' => 'zh-TW',
                'Authorization' => 'Bearer ' . $this->loginToken,
            ]
        );

        return $response;
    }
}
