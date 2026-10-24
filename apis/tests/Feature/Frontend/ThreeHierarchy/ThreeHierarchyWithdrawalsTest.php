<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThreeHierarchy;

use App\Enums\General\HttpCode;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\ThreeHierarchySummaryBonus;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThreeHierarchyWithdrawalsTest extends TestCase
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

    public function test_three_hierarchy_withdrawal_success()
    {
        // Arrange
        $bonus = ThreeHierarchySummaryBonus::create([
            'member_id' => $this->member->id,
            'rebate_amount' => 96.18,
            'down_rebate_amount' => 48.1,
            'down2_rebate_amount' => 2.2,
            'bonus_amount' => 140.48,
            'withdraw_amount' => 0,
        ]);

        // Act
        $response = $this->act([]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals($this->member->username, $data['username']);
        $this->assertEquals($bonus->bonus_amount, $data['totalRewardPoints']);
        $this->assertEquals($bonus->withdraw_amount, $data['withdrawnPoints']);
        $this->assertEquals(Common::numSub($bonus->bonus_amount, $bonus->withdraw_amount), Common::numFloor($data['withdrawablePoints']));

    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/three-hierarchy/withdrawals',
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
                'username',
                'totalRewardPoints',
                'withdrawnPoints',
                'withdrawablePoints',
            ],
        ];
    }
}
