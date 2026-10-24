<?php

declare(strict_types=1);

namespace Tests\Feature\Frontend\ThreeHierarchy;

use App\Enums\General\HttpCode;
use App\Enums\Transaction\TransactionEnum;
use App\Models\Agent;
use App\Models\Member;
use App\Models\ThreeHierarchySummaryBonus;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ThreeHierarchyWithdrawalCreateTest extends TestCase
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

    public function test_three_hierarchy_withdrawal_create_success()
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

        $memberBeforeBalance = 100;
        $amount = 100;

        $this->member->balance = $memberBeforeBalance;
        $this->member->save();

        // Act
        $response = $this->act([
            'amount' => $amount,
        ]);
        $this->member->refresh();
        $bonus->refresh();

        // Assert
        $response->assertStatus(HttpCode::HTTP_OK->value)
            ->assertJsonStructure($this->basicResponseDataStructure());

        $data = $response->json('data');
        $this->assertEquals($this->member->balance, $data['balance']);
        $this->assertEquals($bonus->withdraw_amount, $amount);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::V_REWARD,
            'point' => $amount,
            'before_balance' => $memberBeforeBalance,
            'balance' => $this->member->balance,
            'status' => 1000,
        ]);
    }

    private function act(array $params): TestResponse
    {
        $response = $this->json(
            'POST',
            '/frontend/api/three-hierarchy/withdrawals/create',
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
            'data',
        ];
    }
}
