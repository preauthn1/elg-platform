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
use App\Models\EventProcess;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
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
 * PrizeService::drawAllPrizes 測試（舊版 api/getAllPrize）
 *
 * php artisan test tests/Unit/Services/PrizeDrawAllTest.php
 */
class PrizeDrawAllTest extends TestCase
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
        MemberBankAccount::create([
            'member_id' => $this->member->id,
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
    private function makeEvent(array $detailOverrides = [], array $contentOverrides = [], array $prizeOverrides = []): array
    {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value,
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

        $prize = Prize::create(array_merge([
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
        ], $prizeOverrides));

        $content = PrizeContent::create(array_merge([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => 'content',
            'number' => -1,
            'sent' => 0,
            'level' => 0,
            'point' => 20,
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

    private function makeProcess(array $ctx): void
    {
        EventProcess::create([
            'member_id' => $this->member->id,
            'detail_id' => $ctx['detail']->id,
            'point' => '0.0000',
            'received' => 0,
            'admin' => 0,
        ]);
    }

    private function makePending(array $ctx, array $overrides = []): PrizeMember
    {
        return PrizeMember::create(array_merge([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'status' => 0,
            'admin' => 0,
        ], $overrides));
    }

    public function test_claims_every_pending_reward_at_once(): void
    {
        $ctx = $this->makeEvent();
        $this->makeProcess($ctx);
        $a = $this->makePending($ctx);
        $b = $this->makePending($ctx);
        $c = $this->makePending($ctx);

        $result = $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);

        $this->assertCount(3, $result['prize']);
        $this->assertSame(60.0, (float) Member::find($this->member->id)->balance);
        foreach ([$a, $b, $c] as $pm) {
            $this->assertSame(1, (int) PrizeMember::find($pm->id)->status);
        }
    }

    public function test_uses_already_drawn_content_without_redrawing(): void
    {
        $ctx = $this->makeEvent();
        $this->makeProcess($ctx);
        $this->makePending($ctx, ['content_id' => $ctx['content']->id]);

        $result = $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);

        $this->assertCount(1, $result['prize']);
        $this->assertSame(0, (int) PrizeContent::find($ctx['content']->id)->sent);
        $this->assertSame(20.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_drawing_increments_sent_per_reward(): void
    {
        $ctx = $this->makeEvent();
        $this->makeProcess($ctx);
        $this->makePending($ctx);
        $this->makePending($ctx);

        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);

        $this->assertSame(2, (int) PrizeContent::find($ctx['content']->id)->sent);
    }

    /**
     * 本次統一走 sendPrize，故每筆獎勵各寫一列 flow_limits（舊版 tpower 為彙總一列，見文件 2-12）
     */
    public function test_flow_limit_is_written_per_reward(): void
    {
        $ctx = $this->makeEvent([], ['flow_limit_multiple' => 2, 'point' => 20]);
        $this->makeProcess($ctx);
        $this->makePending($ctx);
        $this->makePending($ctx);

        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);

        $rows = FlowLimit::where('member_id', $this->member->id)->get();
        $this->assertCount(2, $rows);
        $this->assertSame(80.0, (float) $rows->sum('flow_limit'));
    }

    public function test_throws_when_nothing_pending(): void
    {
        $ctx = $this->makeEvent();
        $this->makeProcess($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1949'));
        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_throws_when_event_detail_not_in_time_window(): void
    {
        $ctx = $this->makeEvent(['start_time' => now()->addDay(), 'end_time' => now()->addDays(2)]);
        $this->makePending($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_throws_when_vendor_lock_and_member_already_locked(): void
    {
        $ctx = $this->makeEvent(['vendor_lock' => 1]);
        $this->makePending($ctx);
        $this->member->update(['event_mode' => 4]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_throws_when_member_has_active_event_v2(): void
    {
        $ctx = $this->makeEvent();
        $this->makePending($ctx);
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
        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_throws_when_member_has_active_newbie_challenge(): void
    {
        $ctx = $this->makeEvent();
        $this->makePending($ctx);
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_throws_when_mobile_validation_required_but_unverified(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::EVENT_REWARD_MOBILE_VALIDATION, 1);
        Cache::flush();
        $this->member->update(['mobile_validate' => 0]);

        $ctx = $this->makeEvent();
        $this->makePending($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9007'));
        $this->service->drawAllPrizes($this->member, $this->agent->fresh(), $ctx['event']->id);
    }

    public function test_throws_when_first_name_missing(): void
    {
        $ctx = $this->makeEvent();
        $this->makePending($ctx);
        $this->member->update(['first_name' => '']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1095'));
        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_blocks_duplicate_mobile_and_pays_nothing(): void
    {
        $ctx = $this->makeEvent(['phone_block' => 1]);
        $this->makeProcess($ctx);
        $this->makePending($ctx);
        $this->makePending($ctx);

        $other = $this->createMember($this->agent, [
            'username' => 'other_' . Str::random(10),
            'mobile' => $this->member->mobile,
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
            $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
            $this->fail('should be blocked');
        } catch (ApiException $e) {
            // 重複性檢查改成逐筆進行後，兩筆都會被判定手機重複而取消；整批沒有可領取的獎勵時，
            // 回傳對應到 EventPayoutCancel.type 的具體重複原因（1951 手機號碼），不是通用的 1949
            $this->assertSame(__('error_message.1951'), $e->getMessage());
        }

        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(3, (int) EventPayoutCancel::where('member_id', $this->member->id)->value('type'));
    }

    public function test_writes_reward_claim_records_once(): void
    {
        $ctx = $this->makeEvent();
        $this->makeProcess($ctx);
        $this->makePending($ctx);
        $this->makePending($ctx);

        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);

        $types = RewardClaimRecord::where('event_detail_id', $ctx['detail']->id)
            ->where('member_id', $this->member->id)
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2, 3, 4], $types);
    }

    public function test_expired_reward_is_skipped(): void
    {
        $ctx = $this->makeEvent();
        $this->makeProcess($ctx);
        $this->makePending($ctx);
        $expired = $this->makePending($ctx, ['expired_time' => now()->subDay()]);

        $result = $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);

        $this->assertCount(1, $result['prize']);
        $this->assertSame(0, (int) PrizeMember::find($expired->id)->status);
    }

    public function test_throws_when_progress_not_reached(): void
    {
        $ctx = $this->makeEvent(['point' => 100]);
        EventProcess::create([
            'member_id' => $this->member->id,
            'detail_id' => $ctx['detail']->id,
            'point' => '10.0000',
            'received' => 0,
            'admin' => 0,
        ]);
        $this->makePending($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2004'));
        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_other_members_rewards_are_untouched(): void
    {
        $ctx = $this->makeEvent();
        $this->makeProcess($ctx);
        $this->makePending($ctx);

        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(10)]);
        $theirs = PrizeMember::create([
            'member_id' => $other->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $this->service->drawAllPrizes($this->member, $this->agent, $ctx['event']->id);

        $this->assertSame(0, (int) PrizeMember::find($theirs->id)->status);
    }
}
