<?php

declare(strict_types=1);

namespace Tests\Unit\Frontend;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\BindReward\BindRewardTypeEnum;
use App\Enums\WalletEnum;
use App\Helpers\Common;
use App\Models\Agent;
use App\Models\BindReward;
use App\Models\BindRewardDetail;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Services\Frontend\ValidateToken\MobileBindService;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Tests\TestCase;

/**
 * MobileBindService 手機綁定服務測試
 *
 * php artisan test tests/Unit/Frontend/MobileBindServiceTest.php
 */
class MobileBindServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Member $member;

    private MobileBindService $service;

    private Agent $agent;

    private BindReward $bindReward;

    private BindRewardDetail $bindRewardDetail;

    private string $phone = '13800138000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SyncAgentSettingDefinesSeeder::class);

        $this->agent = $this->createAgentAndDomain();

        $this->member = Member::factory()->create([
            'parent' => $this->agent->id,
        ]);

        $this->bindReward = BindReward::create([
            'agent_id' => $this->agent->id,
            'reward_name' => 'test_reward',
            'status' => 1,
            'start_time' => now()->subDay()->format('Y-m-d H:i:s'),
            'end_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'type' => 1,
            'point' => 100,
            'flow_limits' => json_encode([
                'flow_limit_multiple' => 1,
                'vendor_id_restriction' => [0],
                'game_type_id_restriction' => [0],
            ]),
        ]);

        $this->bindRewardDetail = BindRewardDetail::create([
            'bind_reward_id' => $this->bindReward->id,
            'phone' => $this->phone,
            'status' => 0,
        ]);

        $this->service = new MobileBindService($this->member);
    }

    public function test_update_member_and_give_reward_member_balance()
    {
        // Arrange
        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals($this->bindReward->point, Common::numFloor($this->member->balance, 4));

        $this->assertEquals(1, $this->bindRewardDetail->status);
        $this->assertEquals($this->member->id, $this->bindRewardDetail->member_id);
    }

    #[RunInSeparateProcess]
    public function test_update_member_and_give_reward_game_wallet_balance()
    {
        // Arrange：type=2 已廢除改為只查不建（決議⑬），存量錢包須由測試預建——
        // 本測試守的是「存量錢包還在的過渡期，多錢包獎勵仍正確入帳」
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 1);
        MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'balance' => 0,
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        $gameWallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_GAME)
            ->first();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertNotNull($gameWallet);
        $this->assertEquals($this->bindReward->point, Common::numFloor($gameWallet->balance, 4));

        $this->assertEquals(1, $this->bindRewardDetail->status);
        $this->assertEquals($this->member->id, $this->bindRewardDetail->member_id);
    }

    public function test_update_member_and_give_reward_rolling_chip()
    {
        // Arrange
        $this->bindReward->update([
            'type' => BindRewardTypeEnum::TYPE_ROLLING_CHIPS,
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        $rollingChipWallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP)
            ->first();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertNotNull($rollingChipWallet);
        $this->assertEquals($this->bindReward->point, Common::numFloor($rollingChipWallet->balance, 4));

        $this->assertEquals(1, $this->bindRewardDetail->status);
        $this->assertEquals($this->member->id, $this->bindRewardDetail->member_id);
    }

    public function test_fail_detail_not_found_different_phone()
    {
        // Arrange
        $differentPhone = '11002213456';

        // Act
        $result = $this->service->updateMemberAndGiveReward($differentPhone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($differentPhone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertEquals(0, $this->bindRewardDetail->status);
        $this->assertNull($this->bindRewardDetail->member_id);
    }

    public function test_fail_detail_not_found_detail_already_bound()
    {
        // Arrange
        $anotherMember = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => 'test_user2',
        ]);
        $this->bindRewardDetail->update([
            'status' => 1,
            'member_id' => $anotherMember->id,
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertEquals(1, $this->bindRewardDetail->status);
        $this->assertEquals($anotherMember->id, $this->bindRewardDetail->member_id);
    }

    public function test_fail_list_not_found_disabled()
    {
        // Arrange
        $this->bindReward->update([
            'status' => 0,
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertEquals(0, $this->bindRewardDetail->status);
        $this->assertNull($this->bindRewardDetail->member_id);
    }

    public function test_fail_list_not_found_expired_not_started_yet()
    {
        // Arrange
        $this->bindReward->update([
            'start_time' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_time' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertEquals(0, $this->bindRewardDetail->status);
        $this->assertNull($this->bindRewardDetail->member_id);
    }

    /**
     * php artisan test tests/Unit/Frontend/MobileBindServiceTest.php --filter test_fail_list_not_found_expired_ended
     */
    public function test_fail_list_not_found_expired_ended()
    {
        // Arrange
        $this->bindReward->update([
            'end_time' => now()->subDay()->format('Y-m-d H:i:s'),
            'status' => 1,
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertEquals(0, $this->bindRewardDetail->status);
        $this->assertNull($this->bindRewardDetail->member_id);

        $this->assertEquals(0, $this->bindReward->status);
    }

    public function test_fail_member_already_got_the_other_reward()
    {
        // Arrange
        BindRewardDetail::create([
            'bind_reward_id' => $this->bindReward->id,
            'phone' => '35438313887',
            'status' => 1,
            'member_id' => $this->member->id,
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertEquals(0, $this->bindRewardDetail->status);
        $this->assertNull($this->bindRewardDetail->member_id);
    }

    public function test_fail_member_not_same_agent()
    {
        // Arrange
        $anotherAgent = Agent::create([
            'parent' => 0,
            'code' => 'temp',
            'username' => 'temp_agent',
            'email' => 'user@example.invalid',
            'password' => Hash::make('123456'),
            'status' => 1,
            'admin' => 1,
            'frontend_register' => 1, // For member creation
            'organization_id' => 1,
        ]);
        $this->bindReward->update([
            'agent_id' => $anotherAgent->id,
        ]);

        // Act
        $result = $this->service->updateMemberAndGiveReward($this->phone);
        $this->member->refresh();
        $this->bindReward->refresh();
        $this->bindRewardDetail->refresh();

        // Assert
        $this->assertNull($result);

        $this->assertEquals($this->phone, $this->member->mobile);
        $this->assertEquals(0, $this->member->balance);

        $this->assertEquals(0, $this->bindRewardDetail->status);
        $this->assertNull($this->bindRewardDetail->member_id);
    }
}
