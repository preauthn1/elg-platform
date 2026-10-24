<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThreeHierarchy;

use App\Enums\General\HttpCode;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\Member;
use App\Models\ThreeHierarchyWithdrawLog;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThreeHierarchyWithdrawalHistoriesTest extends TestCase
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

    public function test_three_hierarchy_withdrawal_histories_success()
    {
        // Arrange
        $bonus = ThreeHierarchyWithdrawLog::create([
            'order_no' => 'teststste',
            'type' => 1,
            'member_id' => $this->member->id,
            'withdraw_point' => 100,
            'remaining_point' => Common::numSub(100, 100),
            'status' => 1,
            'admin' => 0,
        ]);

        // Act
        $response = $this->act([
            'page' => 1,
            'per_page' => 10,
        ]);

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/three-hierarchy/withdrawals/histories',
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
                        'no',
                        'amount',
                        'type',
                        'withdrawType',
                        'withdrawAccount',
                    ],
                ],
            ],
        ];
    }
}
