<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Event\EventActionIdEnum;
use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventPayoutCancel;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberBankAccount;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\RewardClaimRecord;
use App\Services\PrizeService;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PrizeService::choosePrize 測試（舊版 api/choosePrize，tpower-master 獨有）
 *
 * php artisan test tests/Unit/Services/PrizeChooseTest.php
 */
class PrizeChooseTest extends TestCase
{
    private PrizeService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->service = app(PrizeService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'event_mode' => 0,
            'first_name' => 'Tester ' . Str::random(6),
            'mobile' => '0900' . random_int(100000, 999999),
            'mobile_validate' => 1,
            'register_ip' => ip2long('0.0.0.0'),
        ]);
        $this->makeBankAccount($this->member);
    }

    private function makeBankAccount(Member $member): MemberBankAccount
    {
        return MemberBankAccount::create([
            'member_id' => $member->id,
            'bank_id' => 1,
            'account_no' => (string) random_int(1000000000, 9999999999),
            'account_name' => 'Tester',
            'status' => 1,
            'sort' => 0,
            'operator_cs' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, detail: EventDetail, prize: Prize, content: PrizeContent}
     */
    private function makeEvent(array $detailOverrides = [], array $contentOverrides = []): array
    {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::BET->value,
            'status' => 1,
            'point' => 0,
            'vendor_id' => 0,
            'game_type_id' => 0,
            'vendor_lock' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 0,
            'global_account_block' => 0,
            'tgid_block' => 0,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'draw_time' => now()->subHour(),
            'expire_hour' => 0,
        ], $detailOverrides));

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 3,
            'name' => 'prize',
            'background' => '',
            'number' => 1,
            'is_repeatedly' => 0,
            'flow_limit_multiple' => 0,
            'max_point' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addYear(),
            'info' => json_encode([]),
            'status' => 1,
        ]);

        $content = PrizeContent::create(array_merge([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => 'content',
            'number' => 10,
            'sent' => 0,
            'level' => 0,
            'point' => 60,
            'percentage' => 100,
            'point_type' => 1,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'flow_limit_multiple' => 0,
            'status' => 1,
            'admin' => 0,
        ], $contentOverrides));

        return compact('event', 'detail', 'prize', 'content');
    }

    private function makePendingChoice(array $ctx, ?Member $member = null, array $overrides = []): PrizeMember
    {
        return PrizeMember::create(array_merge([
            'member_id' => ($member ?? $this->member)->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'content_id' => -1,
            'status' => 0,
            'admin' => 0,
        ], $overrides));
    }

    public function test_credits_balance_and_marks_claimed(): void
    {
        $ctx = $this->makeEvent();
        $prizeMember = $this->makePendingChoice($ctx);

        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);

        $this->assertSame(60.0, (float) Member::find($this->member->id)->balance);
        $fresh = PrizeMember::find($prizeMember->id);
        $this->assertSame(1, (int) $fresh->status);
        $this->assertSame($ctx['content']->id, (int) $fresh->content_id);
    }

    public function test_writes_reward_claim_records(): void
    {
        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx);

        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);

        // 真實姓名 / 註冊IP / 領獎IP / 手機
        $types = RewardClaimRecord::where('event_detail_id', $ctx['detail']->id)
            ->where('member_id', $this->member->id)
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2, 3, 4], $types);
    }

    public function test_throws_when_no_pending_choice(): void
    {
        $ctx = $this->makeEvent();
        // content_id 非 -1 者不算待自選
        $this->makePendingChoice($ctx, null, ['content_id' => $ctx['content']->id]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1949'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_throws_when_pending_choice_expired(): void
    {
        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx, null, ['expired_time' => now()->subDay()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1949'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_throws_when_mobile_validation_required_but_unverified(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::EVENT_REWARD_MOBILE_VALIDATION, 1);
        Cache::flush();
        $this->member->update(['mobile_validate' => 0]);

        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9007'));
        $this->service->choosePrize($this->member, $this->agent->fresh(), $ctx['event']->id, $ctx['content']->id);
    }

    public function test_throws_when_vendor_lock_and_member_already_locked(): void
    {
        $ctx = $this->makeEvent(['vendor_lock' => 1]);
        $this->makePendingChoice($ctx);
        $this->member->update(['event_mode' => 9]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_throws_when_member_has_active_event_v2(): void
    {
        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx);
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

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1991'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_throws_when_member_has_active_newbie_challenge(): void
    {
        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx);
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_throws_when_first_name_missing(): void
    {
        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx);
        $this->member->update(['first_name' => '']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1095'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_throws_when_bank_account_required_but_missing(): void
    {
        MemberBankAccount::where('member_id', $this->member->id)->delete();

        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2040'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_passes_when_bank_account_check_disabled(): void
    {
        MemberBankAccount::where('member_id', $this->member->id)->delete();
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::EVENT_NEED_BANK_ACCOUNT, 0);
        Cache::flush();

        $ctx = $this->makeEvent();
        $this->makePendingChoice($ctx);

        $this->service->choosePrize($this->member, $this->agent->fresh(), $ctx['event']->id, $ctx['content']->id);

        $this->assertSame(60.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_blocks_duplicate_first_name_and_records_cancel(): void
    {
        $ctx = $this->makeEvent(['account_block' => 1]);
        $this->makePendingChoice($ctx);

        // 另一位同名會員已領過同一活動明細
        $other = $this->createMember($this->agent, [
            'username' => 'other_' . Str::random(10),
            'first_name' => $this->member->first_name,
        ]);
        PrizeMember::create([
            'member_id' => $other->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'content_id' => $ctx['content']->id,
            'status' => 1,
            'admin' => 0,
        ]);

        try {
            $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
            $this->fail('should be blocked');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.1952'), $e->getMessage());
        }

        $cancel = EventPayoutCancel::where('member_id', $this->member->id)->first();
        $this->assertNotNull($cancel);
        $this->assertSame(1, (int) $cancel->type);
        $this->assertSame($other->id, (int) $cancel->block_member_id);
        $this->assertStringContainsString($other->username, (string) $cancel->remark);
        // 被擋下時不得派彩
        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_blocks_global_duplicate_first_name_as_type_7(): void
    {
        $ctx = $this->makeEvent(['global_account_block' => 1]);
        $this->makePendingChoice($ctx);

        // 全站同名，不限同一活動
        $this->createMember($this->agent, [
            'username' => 'other_' . Str::random(10),
            'first_name' => $this->member->first_name,
        ]);

        try {
            $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
            $this->fail('should be blocked');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.1952'), $e->getMessage());
        }

        $this->assertSame(7, (int) EventPayoutCancel::where('member_id', $this->member->id)->value('type'));
    }

    public function test_missing_first_name_is_rejected_before_block_check(): void
    {
        $ctx = $this->makeEvent(['account_block' => 1]);
        $this->makePendingChoice($ctx);
        $this->member->update(['first_name' => null]);

        // 真實姓名缺漏會先被 checkMemberFirstNameAndWithdrawAccount 擋下
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1095'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_existing_payout_cancel_blocks_immediately(): void
    {
        $ctx = $this->makeEvent();
        $prizeMember = $this->makePendingChoice($ctx);

        EventPayoutCancel::create([
            'event_id' => $ctx['event']->id,
            'prize_member_id' => $prizeMember->id,
            'from_member_id' => $this->member->id,
            'member_id' => $this->member->id,
            'type' => 3,
            'block_member_id' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1951'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }

    public function test_daily_action_only_compares_today_records(): void
    {
        $ctx = $this->makeEvent([
            'action_id' => EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
            'account_block' => 1,
        ]);
        $this->makePendingChoice($ctx);

        // 另一位同名會員的領取紀錄是昨天的 → 不應被擋
        $other = $this->createMember($this->agent, [
            'username' => 'other_' . Str::random(10),
            'first_name' => $this->member->first_name,
        ]);
        $old = PrizeMember::create([
            'member_id' => $other->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'content_id' => $ctx['content']->id,
            'status' => 1,
            'admin' => 0,
        ]);
        PrizeMember::where('id', $old->id)->update(['created_at' => now()->subDays(2)]);

        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);

        $this->assertSame(60.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_other_members_pending_choice_is_not_taken(): void
    {
        $ctx = $this->makeEvent();
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(10), 'event_mode' => 0]);
        $this->makePendingChoice($ctx, $other);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1949'));
        $this->service->choosePrize($this->member, $this->agent, $ctx['event']->id, $ctx['content']->id);
    }
}
