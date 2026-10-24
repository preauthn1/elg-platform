<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberVip;
use App\Models\MemberVipPrize;
use App\Models\MemberWallet;
use App\Models\NewbieChallengeProgress;
use App\Models\RollingChipLog;
use App\Models\TransactionLog;
use App\Models\VipContentReward;
use App\Models\VipFlowLimit;
use App\Models\VipReward;
use App\Services\VipService;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VipService::getFlowLimits / claimReward / claimRebateRewards 測試
 * （vip/flow_limit、vip/receive、vip/receive_rebate；tpower-master 與 vw-master 逐行完全相同）
 *
 * php artisan test tests/Unit/Services/VipServiceTest.php
 */
class VipServiceTest extends TestCase
{
    private VipService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(VipService::class);
        $this->agent = $this->createAgentAndDomain();
        // VIP 領獎強制實名（不看代理開關），預設給已實名的會員，未實名情境另有專門測試
        $this->member = $this->createMember($this->agent, ['event_mode' => 0, 'realname_validate' => 1]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeContentReward(array $overrides = []): VipContentReward
    {
        return VipContentReward::create(array_merge([
            'vip_content_id' => 1,
            'vip_reward_id' => 1,
            'type' => 0,
            'max_point' => 0,
            'point_type' => 1,
            'condition' => 0,
            'point' => '100.0000',
            'point_percentage' => 0,
            'expired_hour' => 0,
            'flow_limit_multiple' => 0,
            'status' => 1,
            'admin' => 0,
        ], $overrides));
    }

    private function makePrize(VipContentReward $reward, array $overrides = []): MemberVipPrize
    {
        return MemberVipPrize::create(array_merge([
            'member_id' => $this->member->id,
            'vip_content_reward_id' => $reward->id,
            'point_type' => $reward->point_type,
            'base_amount' => '0.0000',
            'point' => $reward->point,
            'expired_time' => null,
            'status' => 0,
            'admin' => 0,
        ], $overrides));
    }

    // ---- getFlowLimits ----

    public function test_get_flow_limits_paginates_and_excludes_cancelled_status(): void
    {
        for ($i = 0; $i < 3; $i++) {
            VipFlowLimit::create([
                'type' => 1, 'member_id' => $this->member->id,
                'deposit_amount' => '100.0000', 'flow_limit' => '200.0000', 'reach' => '0.0000',
                'status' => 0, 'admin' => 0,
            ]);
        }
        // 狀態 9（取消稽核）不應計入
        VipFlowLimit::create([
            'type' => 1, 'member_id' => $this->member->id,
            'deposit_amount' => '999.0000', 'flow_limit' => '999.0000', 'reach' => '0.0000',
            'status' => 9, 'admin' => 0,
        ]);

        $result = $this->service->getFlowLimits($this->member->id, 1, 2);

        $this->assertSame(3, $result['total']);
        $this->assertCount(2, $result['data']);
    }

    // ---- claimReward ----

    public function test_claim_reward_type0_credits_platform_balance(): void
    {
        $reward = $this->makeContentReward(['type' => 0, 'point' => '100.0000', 'point_type' => 1]);
        $prize = $this->makePrize($reward, ['point' => '100.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertSame(100.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) MemberVipPrize::find($prize->id)->status);
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'order_id' => $prize->id,
            'type' => TransactionEnum::VIP_REWARD->value,
            'point' => '100.0000',
        ]);
    }

    public function test_claim_reward_type0_rejects_point_mismatch(): void
    {
        $reward = $this->makeContentReward(['type' => 0, 'point' => '100.0000', 'point_type' => 1]);
        $prize = $this->makePrize($reward, ['point' => '50.0000', 'point_type' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1944'));
        $this->service->claimReward($this->member, $prize->id);
    }

    public function test_claim_reward_type1_percentage_of_base_amount(): void
    {
        $reward = $this->makeContentReward([
            'type' => 1, 'point_percentage' => '0.1000', 'condition' => '500.0000', 'point_type' => 1,
        ]);
        $prize = $this->makePrize($reward, ['base_amount' => '1000.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertSame(100.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame('100.0000', (string) MemberVipPrize::find($prize->id)->point);
    }

    public function test_claim_reward_type1_rejects_when_condition_not_met(): void
    {
        $reward = $this->makeContentReward([
            'type' => 1, 'point_percentage' => '0.1000', 'condition' => '5000.0000', 'point_type' => 1,
        ]);
        $prize = $this->makePrize($reward, ['base_amount' => '1000.0000', 'point_type' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1936'));
        $this->service->claimReward($this->member, $prize->id);
    }

    public function test_claim_reward_type2_uses_base_amount_and_caps_at_max_point(): void
    {
        $reward = $this->makeContentReward(['type' => 2, 'max_point' => '80.0000', 'point_type' => 1]);
        $prize = $this->makePrize($reward, ['base_amount' => '500.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertSame(80.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame('80.0000', (string) MemberVipPrize::find($prize->id)->point);
    }

    public function test_claim_reward_rolling_chip_credits_wallet_and_creates_log(): void
    {
        $reward = $this->makeContentReward(['type' => 0, 'point' => '50.0000', 'point_type' => 2]);
        $prize = $this->makePrize($reward, ['point' => '50.0000', 'point_type' => 2]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);
        $this->assertDatabaseHas('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => '50.0000',
        ]);
        $this->assertSame(1, RollingChipLog::where('member_id', $this->member->id)->count());
    }

    public function test_claim_reward_rolling_chip_accumulates_existing_wallet(): void
    {
        MemberWallet::create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => '30.0000',
            'first_deposit' => null,
            'status' => 1,
            'admin' => 0,
        ]);
        $reward = $this->makeContentReward(['type' => 0, 'point' => '20.0000', 'point_type' => 2]);
        $prize = $this->makePrize($reward, ['point' => '20.0000', 'point_type' => 2]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertDatabaseHas('member_wallets', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_ROLLING_CHIP->value,
            'balance' => '50.0000',
        ]);
    }

    public function test_claim_reward_creates_flow_limit_when_multiple_positive(): void
    {
        $reward = $this->makeContentReward(['type' => 0, 'point' => '100.0000', 'point_type' => 1, 'flow_limit_multiple' => 3]);
        $prize = $this->makePrize($reward, ['point' => '100.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'type' => TransactionEnum::VIP_REWARD->value,
            'flow_limit' => '300.0000',
        ]);
    }

    public function test_claim_reward_skips_flow_limit_when_multiple_zero(): void
    {
        $reward = $this->makeContentReward(['type' => 0, 'point' => '100.0000', 'point_type' => 1, 'flow_limit_multiple' => 0]);
        $prize = $this->makePrize($reward, ['point' => '100.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertSame(0, FlowLimit::where('member_id', $this->member->id)->count());
    }

    public function test_claim_reward_throws_when_prize_not_found(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->claimReward($this->member, 999999);
    }

    public function test_claim_reward_throws_when_already_claimed(): void
    {
        $reward = $this->makeContentReward();
        $prize = $this->makePrize($reward, ['status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->claimReward($this->member, $prize->id);
    }

    public function test_claim_reward_throws_when_expired(): void
    {
        $reward = $this->makeContentReward();
        $prize = $this->makePrize($reward, ['expired_time' => now()->subDay()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1953'));
        $this->service->claimReward($this->member, $prize->id);
    }

    public function test_claim_reward_throws_when_content_reward_disabled(): void
    {
        $reward = $this->makeContentReward(['status' => 0]);
        $prize = $this->makePrize($reward);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1941'));
        $this->service->claimReward($this->member, $prize->id);
    }

    public function test_claim_reward_throws_when_point_type_mismatch(): void
    {
        $reward = $this->makeContentReward(['point_type' => 1]);
        $prize = $this->makePrize($reward, ['point_type' => 2]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1944'));
        $this->service->claimReward($this->member, $prize->id);
    }

    public function test_claim_reward_throws_when_member_in_lock_mode(): void
    {
        $reward = $this->makeContentReward();
        $prize = $this->makePrize($reward);
        $this->member->update(['event_mode' => 5]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->claimReward($this->member->fresh(), $prize->id);
    }

    public function test_claim_reward_throws_when_member_has_active_event_v2(): void
    {
        $reward = $this->makeContentReward();
        $prize = $this->makePrize($reward);
        $this->joinEventV2();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1991'));
        $this->service->claimReward($this->member->fresh(), $prize->id);
    }

    public function test_claim_reward_throws_when_member_has_active_newbie_challenge(): void
    {
        $reward = $this->makeContentReward();
        $prize = $this->makePrize($reward);
        $this->joinNewbieChallenge();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->claimReward($this->member->fresh(), $prize->id);
    }

    public function test_claim_reward_throws_when_member_not_realname_validated(): void
    {
        $reward = $this->makeContentReward();
        $prize = $this->makePrize($reward);
        $this->member->update(['realname_validate' => 0]);

        // VIP 領獎強制實名，不受代理 EVENT_REWARD_REALNAME_VALIDATION 開關影響
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.realname_invalid.event_reward'));
        $this->service->claimReward($this->member->fresh(), $prize->id);
    }

    public function test_claim_rebate_rewards_throws_when_member_not_realname_validated(): void
    {
        $reward = $this->makeContentReward(['vip_reward_id' => 7, 'type' => 2, 'point_type' => 1]);
        $this->makePrize($reward, ['base_amount' => '300.0000']);
        MemberVip::create(['member_id' => $this->member->id, 'vip_content_id' => 1, 'admin' => 0]);
        $this->member->update(['realname_validate' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.realname_invalid.event_reward'));
        $this->service->claimRebateRewards($this->member->fresh());
    }

    public function test_claim_reward_rejects_other_members_prize(): void
    {
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(12)]);
        $reward = $this->makeContentReward();
        $prize = $this->makePrize($reward, ['member_id' => $other->id]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->claimReward($this->member, $prize->id);
    }

    public function test_claim_reward_of_achievement_bonus_also_claims_other_pending_achievement_bonuses(): void
    {
        // vip_reward_id = 4（達標獎金）
        $rewardA = $this->makeContentReward(['vip_reward_id' => 4, 'type' => 0, 'point' => '100.0000', 'point_type' => 1]);
        $rewardB = $this->makeContentReward(['vip_reward_id' => 4, 'type' => 0, 'point' => '50.0000', 'point_type' => 1]);
        $prizeA = $this->makePrize($rewardA, ['point' => '100.0000', 'point_type' => 1]);
        $prizeB = $this->makePrize($rewardB, ['point' => '50.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prizeA->id);

        $this->assertSame(150.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) MemberVipPrize::find($prizeA->id)->status);
        $this->assertSame(1, (int) MemberVipPrize::find($prizeB->id)->status);
        $this->assertSame(2, TransactionLog::where('member_id', $this->member->id)->count());
    }

    public function test_claim_reward_of_non_achievement_bonus_does_not_cascade_other_achievement_bonuses(): void
    {
        // vip_reward_id 預設即為 1（非達標獎金）
        $reward = $this->makeContentReward(['type' => 0, 'point' => '100.0000', 'point_type' => 1]);
        $prize = $this->makePrize($reward, ['point' => '100.0000', 'point_type' => 1]);
        // 名下另有一筆達標獎金待領，但這次領的不是達標獎金，不應被連帶領取
        $achievementReward = $this->makeContentReward(['vip_reward_id' => 4, 'type' => 0, 'point' => '50.0000', 'point_type' => 1]);
        $achievementPrize = $this->makePrize($achievementReward, ['point' => '50.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prize->id);

        $this->assertSame(100.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(0, (int) MemberVipPrize::find($achievementPrize->id)->status);
    }

    public function test_claim_reward_achievement_bonus_cascade_skips_data_mismatched_prize(): void
    {
        $rewardA = $this->makeContentReward(['vip_reward_id' => 4, 'type' => 0, 'point' => '100.0000', 'point_type' => 1]);
        $prizeA = $this->makePrize($rewardA, ['point' => '100.0000', 'point_type' => 1]);
        // 資料異常：獎勵設定金額與領獎紀錄不符，resolveVipRewardPoint 會判定不合法
        $rewardB = $this->makeContentReward(['vip_reward_id' => 4, 'type' => 0, 'point' => '999.0000', 'point_type' => 1]);
        $prizeB = $this->makePrize($rewardB, ['point' => '1.0000', 'point_type' => 1]);
        $rewardC = $this->makeContentReward(['vip_reward_id' => 4, 'type' => 0, 'point' => '50.0000', 'point_type' => 1]);
        $prizeC = $this->makePrize($rewardC, ['point' => '50.0000', 'point_type' => 1]);

        $this->service->claimReward($this->member, $prizeA->id);

        $this->assertSame(150.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) MemberVipPrize::find($prizeA->id)->status);
        $this->assertSame(0, (int) MemberVipPrize::find($prizeB->id)->status);
        $this->assertSame(1, (int) MemberVipPrize::find($prizeC->id)->status);
    }

    // ---- claimRebateRewards ----

    public function test_claim_rebate_rewards_pays_all_pending_rebate_prizes(): void
    {
        MemberVip::create(['member_id' => $this->member->id, 'vip_content_id' => 1, 'admin' => 0]);
        VipReward::forceCreate(['id' => 7, 'name' => '每日返水', 'status' => 1, 'admin' => 0]);
        $rewardA = $this->makeContentReward(['vip_reward_id' => 7, 'type' => 2, 'point_type' => 1]);
        $rewardB = $this->makeContentReward(['vip_reward_id' => 7, 'type' => 2, 'point_type' => 1]);
        $prizeA = $this->makePrize($rewardA, ['base_amount' => '30.0000']);
        $prizeB = $this->makePrize($rewardB, ['base_amount' => '70.0000']);

        $this->service->claimRebateRewards($this->member);

        $this->assertSame(100.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) MemberVipPrize::find($prizeA->id)->status);
        $this->assertSame(1, (int) MemberVipPrize::find($prizeB->id)->status);
        $this->assertSame(2, TransactionLog::where('member_id', $this->member->id)->count());
    }

    public function test_claim_rebate_rewards_ignores_non_rebate_reward_id(): void
    {
        MemberVip::create(['member_id' => $this->member->id, 'vip_content_id' => 1, 'admin' => 0]);
        VipReward::forceCreate(['id' => 7, 'name' => '每日返水', 'status' => 1, 'admin' => 0]);
        // 有效的返水設定存在，但玩家名下的待領獎勵是綁在別的 vip_reward_id（非 7），不應被領走
        $this->makeContentReward(['vip_reward_id' => 7, 'type' => 2]);
        $otherReward = $this->makeContentReward(['vip_reward_id' => 1, 'type' => 0]);
        $prize = $this->makePrize($otherReward, ['base_amount' => '999.0000']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        try {
            $this->service->claimRebateRewards($this->member);
        } finally {
            $this->assertSame(0, (int) MemberVipPrize::find($prize->id)->status);
        }
    }

    public function test_claim_rebate_rewards_throws_when_no_member_vip(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1941'));
        $this->service->claimRebateRewards($this->member);
    }

    public function test_claim_rebate_rewards_throws_when_no_active_rebate_configured(): void
    {
        MemberVip::create(['member_id' => $this->member->id, 'vip_content_id' => 1, 'admin' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1941'));
        $this->service->claimRebateRewards($this->member);
    }

    public function test_claim_rebate_rewards_throws_when_member_in_lock_mode(): void
    {
        MemberVip::create(['member_id' => $this->member->id, 'vip_content_id' => 1, 'admin' => 0]);
        $this->member->update(['event_mode' => 5]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->claimRebateRewards($this->member->fresh());
    }

    public function test_claim_rebate_rewards_throws_when_member_has_active_event_v2(): void
    {
        MemberVip::create(['member_id' => $this->member->id, 'vip_content_id' => 1, 'admin' => 0]);
        $this->joinEventV2();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1991'));
        $this->service->claimRebateRewards($this->member->fresh());
    }

    public function test_claim_rebate_rewards_throws_when_member_has_active_newbie_challenge(): void
    {
        MemberVip::create(['member_id' => $this->member->id, 'vip_content_id' => 1, 'admin' => 0]);
        $this->joinNewbieChallenge();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->claimRebateRewards($this->member->fresh());
    }

    /**
     * 建立一筆 status=JOIN 的 EventV2UserJoin，模擬會員目前有 V2 活動進行中
     */
    private function joinEventV2(): void
    {
        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => 1,
            'agent_id' => $this->agent->id,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ]);
        EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $this->member->id,
            'order_id' => 0,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
        ]);
    }

    /**
     * 建立一筆 stage=CHALLENGING 的 NewbieChallengeProgress，模擬會員目前有新人專享挑戰活動進行中
     */
    private function joinNewbieChallenge(): void
    {
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);
    }
}
