<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventName;
use App\Models\EventSlotCompetition;
use App\Models\EventSlotCompetitionRecord;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\MemberWallet;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\RollingChipLog;
use App\Services\SlotCompetitionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SlotCompetitionService 測試（老虎機爭霸賽 event/slot_competition/*）
 *
 * php artisan test tests/Unit/Services/SlotCompetitionServiceTest.php
 */
class SlotCompetitionServiceTest extends TestCase
{
    private SlotCompetitionService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(SlotCompetitionService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'event_mode' => 0,
            'username' => 'abcdef',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, detail: EventDetail, competition: EventSlotCompetition, prize: Prize, content: PrizeContent}
     */
    private function makeCompetition(array $detailOverrides = [], array $contentOverrides = [], array $compOverrides = []): array
    {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'auto_payout' => 0,
            'content' => 'event content',
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::SLOT_MACHINE_COMPETITION->value,
            'status' => 1,
            'point' => 0,
            'vendor_id' => 0,
            'game_type_id' => 0,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'draw_time' => now()->subHour(),
            'expire_hour' => 48,
        ], $detailOverrides));

        $competition = EventSlotCompetition::create(array_merge([
            'event_id' => $event->id,
            'event_detail_id' => $detail->id,
            'no' => 1,
            'draw_time' => now()->subHour(),
            'status' => 1,
            'admin' => 0,
        ], $compOverrides));

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 3,
            'name' => 'slot prize',
            'background' => '',
            'number' => 1,
            'is_repeatedly' => 0,
            'flow_limit_multiple' => 0,
            'max_point' => 0,
            'expire_date' => now()->addYear(),
            'info' => json_encode([]),
            'status' => 1,
        ]);

        $content = PrizeContent::create(array_merge([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => '頭獎',
            'number' => 3,
            'sent' => 0,
            'level' => 1,
            'point' => 500,
            'percentage' => 100,
            'point_type' => 1,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'flow_limit_multiple' => 0,
            'status' => 1,
            'admin' => 0,
        ], $contentOverrides));

        return compact('event', 'detail', 'competition', 'prize', 'content');
    }

    private function makeRecord(array $ctx, ?Member $member = null, array $overrides = []): EventSlotCompetitionRecord
    {
        return EventSlotCompetitionRecord::create(array_merge([
            'event_slot_competition_id' => $ctx['competition']->id,
            'member_id' => ($member ?? $this->member)->id,
            'bet_amount' => '1000.0000',
            'last_bet_time' => now()->subHours(2),
            'prize_content_id' => $ctx['content']->id,
            'ranking' => 1,
            'point' => '500.0000',
            'type' => 1,
            'status' => 0,
            'admin' => 0,
        ], $overrides));
    }

    public function test_issue_list_returns_own_agent_competitions(): void
    {
        $ctx = $this->makeCompetition();

        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->hierarchy = "[-1,{$other->id},-2]";
        $other->save();
        $otherEvent = Event::factory()->create(['agent_id' => $other->id, 'status' => 1, 'start_time' => now()->subDay()]);
        $otherDetail = EventDetail::factory()->create([
            'event_id' => $otherEvent->id,
            'action_id' => EventActionIdEnum::SLOT_MACHINE_COMPETITION->value,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);
        EventSlotCompetition::create([
            'event_id' => $otherEvent->id,
            'event_detail_id' => $otherDetail->id,
            'no' => 1,
            'draw_time' => now(),
            'status' => 1,
            'admin' => 0,
        ]);

        $list = $this->service->getIssueList($this->agent);

        $this->assertCount(1, $list);
        $this->assertSame($ctx['competition']->id, (int) $list[0]->id);
        $this->assertSame($ctx['event']->id, (int) $list[0]->event_id);
    }

    public function test_leader_board_masks_other_usernames_and_keeps_own(): void
    {
        $ctx = $this->makeCompetition();
        $rival = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'ranking' => 1]);
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '1000.0000', 'ranking' => 2]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, 1, 10);

        $this->assertCount(2, $rows);
        // 他人遮蔽中間兩字（vw 規則）
        $this->assertSame('zz**qq', $rows[0]['username']);
        // 自己不遮
        $this->assertSame('abcdef', $rows[1]['username']);
    }

    public function test_leader_board_appends_self_when_out_of_rank(): void
    {
        $ctx = $this->makeCompetition();
        $rival = $this->createMember($this->agent, ['username' => 'rival1']);
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'ranking' => 1]);
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '10.0000', 'ranking' => 9]);

        // 只取前 1 名 → 自己被擠出，補在最後且名次為 --
        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, 1, 1);

        $this->assertCount(2, $rows);
        $this->assertSame('ri**l1', $rows[0]['username']);
        $this->assertSame('--', $rows[1]['ranking']);
        $this->assertSame('abcdef', $rows[1]['username']);
    }

    public function test_leader_board_without_per_page_returns_all_rows_when_no_active_prize(): void
    {
        $ctx = $this->makeCompetition();
        // 獎項下架 → 預設每頁筆數的 SUM 為 0；舊版此時不加 LIMIT，須撈出全部名次
        $ctx['content']->update(['status' => 0]);

        $rival = $this->createMember($this->agent, ['username' => 'rival1']);
        $this->makeRecord($ctx, $rival, ['bet_amount' => '2000.0000', 'ranking' => 1]);
        $this->makeRecord($ctx, $this->member, ['bet_amount' => '1000.0000', 'ranking' => 2]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, null, null);

        $this->assertCount(2, $rows);
    }

    public function test_leader_board_excludes_disabled_members(): void
    {
        $ctx = $this->makeCompetition();
        $disabled = $this->createMember($this->agent, ['username' => 'banned', 'status' => 0]);
        $this->makeRecord($ctx, $disabled, ['bet_amount' => '9999.0000', 'ranking' => 1]);
        $this->makeRecord($ctx, $this->member, ['ranking' => 2]);

        $rows = $this->service->getLeaderBoard($this->member, $this->agent, $ctx['event']->id, 1, 1, 10);

        $this->assertCount(1, $rows);
        $this->assertSame('abcdef', $rows[0]['username']);
    }

    public function test_my_ranking_marks_expired_unclaimed_as_status_2(): void
    {
        // 開獎後已超過 expire_hour 且未領取 → status 顯示 2
        $ctx = $this->makeCompetition(
            ['expire_hour' => 1],
            [],
            ['draw_time' => now()->subHours(5)],
        );
        $this->makeRecord($ctx, null, ['status' => 0]);

        $rows = $this->service->getMyRanking($this->member, $this->agent, $ctx['event']->id);

        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]->status);
        $this->assertSame(500.0, (float) $rows[0]->point);
    }

    public function test_my_ranking_hides_point_when_not_winning(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx, null, ['type' => 0]);

        $rows = $this->service->getMyRanking($this->member, $this->agent, $ctx['event']->id);

        $this->assertNull($rows[0]->point);
    }

    public function test_my_ranking_excludes_undrawn_issues(): void
    {
        $this->makeCompetition([], [], ['draw_time' => now()->addDay()]);

        $rows = $this->service->getMyRanking($this->member, $this->agent, Event::latest('id')->value('id'));

        $this->assertCount(0, $rows);
    }

    public function test_awards_returns_localized_content_and_prizes(): void
    {
        $ctx = $this->makeCompetition();
        EventName::create([
            'event_id' => $ctx['event']->id,
            'language_id' => 2,
            'title' => 'localized title',
            'content' => 'localized content',
        ]);

        $default = $this->service->getAwards($this->agent, $ctx['event']->id, 1);
        $this->assertSame('event content', $default['content']);
        $this->assertCount(1, $default['prizes']);
        $this->assertSame('頭獎', $default['prizes'][0]->name);

        $localized = $this->service->getAwards($this->agent, $ctx['event']->id, 2);
        $this->assertSame('localized content', $localized['content']);
    }

    public function test_awards_rejects_event_outside_agent_hierarchy(): void
    {
        $ctx = $this->makeCompetition();
        $ctx['event']->update(['agent_id' => $this->agent->id + 9999]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->getAwards($this->agent, $ctx['event']->id, 1);
    }

    public function test_awards_rejects_missing_event(): void
    {
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->getAwards($this->agent, 0, 1);
    }

    public function test_receive_credits_platform_balance_and_flow_limit(): void
    {
        $ctx = $this->makeCompetition([], ['point_type' => 1]);
        $ctx['prize']->update(['flow_limit_multiple' => 2]);
        $record = $this->makeRecord($ctx);

        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(500.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) EventSlotCompetitionRecord::find($record->id)->status);
        $this->assertNotNull(EventSlotCompetitionRecord::find($record->id)->time);
        $this->assertDatabaseHas('prize_members', [
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'content_id' => $ctx['content']->id,
            'status' => 1,
        ]);
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'event_id' => $ctx['event']->id,
            'flow_limit' => '1000.0000',
        ]);
    }

    public function test_receive_credits_rolling_chip_without_flow_limit(): void
    {
        $ctx = $this->makeCompetition([], ['point_type' => 2]);
        $ctx['prize']->update(['flow_limit_multiple' => 2]);
        $this->makeRecord($ctx);

        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);

        $wallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP->value)
            ->first();

        $this->assertNotNull($wallet);
        $this->assertSame(500.0, (float) $wallet->balance);
        $this->assertSame(1, RollingChipLog::where('member_id', $this->member->id)->count());
        // 沿用舊版：禮碼不寫稽核流水
        $this->assertSame(0, FlowLimit::where('member_id', $this->member->id)->count());
        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_receive_throws_when_not_winning(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx, null, ['type' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_throws_when_already_claimed(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx, null, ['status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1947'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_throws_when_expired(): void
    {
        $ctx = $this->makeCompetition(['expire_hour' => 1], [], ['draw_time' => now()->subHours(5)]);
        $this->makeRecord($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1948'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_throws_when_member_in_event_lock_mode(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx);
        $this->member->update(['event_mode' => 7]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_throws_when_member_has_active_event_v2(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx);
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
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_throws_when_member_has_active_newbie_challenge(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx);
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_throws_when_issue_not_drawn(): void
    {
        // esc.status 必須為 1（已派獎）
        $ctx = $this->makeCompetition([], [], ['status' => 0]);
        $this->makeRecord($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_throws_when_event_is_auto_payout(): void
    {
        $ctx = $this->makeCompetition();
        $ctx['event']->update(['auto_payout' => 1]);
        $this->makeRecord($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_receive_is_not_repeatable(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx);

        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1947'));
        $this->service->receive($this->member->fresh(), $this->agent, $ctx['event']->id, 1);
    }

    public function test_other_members_record_is_not_claimable(): void
    {
        $ctx = $this->makeCompetition();
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(6), 'event_mode' => 0]);
        $this->makeRecord($ctx, $other);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_prize_member_records_the_awarded_point(): void
    {
        $ctx = $this->makeCompetition();
        $this->makeRecord($ctx, null, ['point' => '888.0000']);

        $this->service->receive($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(888.0, (float) PrizeMember::where('member_id', $this->member->id)->value('lottery_point'));
        $this->assertSame(888.0, (float) Member::find($this->member->id)->balance);
    }
}
