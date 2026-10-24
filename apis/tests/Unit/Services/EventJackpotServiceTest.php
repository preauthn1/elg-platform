<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventJackpot;
use App\Models\EventJackpotImage;
use App\Models\EventJackpotRecord;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
use App\Models\Member;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeMember;
use App\Models\TransactionLog;
use App\Services\EventJackpotService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EventJackpotService 測試（積寶活動 event/jackpot/*）
 *
 * php artisan test tests/Unit/Services/EventJackpotServiceTest.php
 */
class EventJackpotServiceTest extends TestCase
{
    private EventJackpotService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(EventJackpotService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, ['event_mode' => 0, 'username' => 'abcdef']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, prize: Prize, jackpot: EventJackpot}
     */
    private function makeJackpotEvent(array $eventOverrides = [], array $jackpotOverrides = []): array
    {
        $event = Event::factory()->create(array_merge([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'type_id' => 3,
            'auto_payout' => 0,
            'title' => 'jackpot event',
            'content' => 'jackpot content',
        ], $eventOverrides));

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 3,
            'name' => 'jackpot prize',
            'background' => '',
            'number' => 1,
            'is_repeatedly' => 0,
            'flow_limit_multiple' => 0,
            'max_point' => '88888.0000',
            'expire_date' => now()->addYear(),
            'info' => json_encode([]),
            'status' => 1,
        ]);

        $jackpot = EventJackpot::create(array_merge([
            'event_id' => $event->id,
            'type' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'expire_date' => now()->addYear(),
            'expire_hour' => 0,
            'achieve_point' => 0,
            'member_number' => 0,
            'agent_number' => 0,
            'pay_number' => 0,
            'number_of_member_in_agent' => 0,
            'point' => 0,
            'flow_limit_multiple' => 0,
            'sort' => 0,
            'admin' => 0,
        ], $jackpotOverrides));

        return compact('event', 'prize', 'jackpot');
    }

    private function makePrizeMember(array $ctx, ?Member $member = null, array $overrides = []): PrizeMember
    {
        // created_at 在 PrizeMember 的 $guarded 內，無法透過 create() 覆寫；改由 query builder 直接更新
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $prizeMember = PrizeMember::create(array_merge([
            'member_id' => ($member ?? $this->member)->id,
            'prize_id' => $ctx['prize']->id,
            'content_id' => 0,
            'event_detail_id' => $ctx['jackpot']->id,
            'lottery_point' => '888.0000',
            'status' => 0,
            'admin' => 0,
        ], $overrides));

        if ($createdAt !== null) {
            \Illuminate\Support\Facades\DB::table('prize_members')
                ->where('id', $prizeMember->id)
                ->update(['created_at' => $createdAt]);
        }

        return $prizeMember->fresh();
    }

    public function test_show_returns_event_and_member_prizes(): void
    {
        $ctx = $this->makeJackpotEvent();
        $this->makePrizeMember($ctx);

        $data = $this->service->getEvent($this->member, $this->agent, 1);

        $this->assertNotNull($data);
        $this->assertSame('jackpot event', $data->title);
        $this->assertSame('88888.00', (string) $data->jackpot);
        $this->assertCount(1, $data->prize);
        $this->assertSame('888.0000', (string) $data->prize[0]->point);
    }

    public function test_show_returns_null_when_no_active_event(): void
    {
        $data = $this->service->getEvent($this->member, $this->agent, 1);

        $this->assertNull($data);
    }

    public function test_show_excludes_prizes_outside_agent_hierarchy(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->hierarchy = "[-1,{$other->id},-2]";
        $other->save();

        $this->makeJackpotEvent(['agent_id' => $other->id]);

        $data = $this->service->getEvent($this->member, $this->agent, 1);

        $this->assertNull($data);
    }

    public function test_win_list_masks_usernames_within_business_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00'));
        $ctx = $this->makeJackpotEvent();

        $winner = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        $this->makePrizeMember($ctx, $winner, [
            'status' => 1,
            'created_at' => now()->subHour(),
        ]);

        $list = $this->service->getWinList($this->agent);

        $this->assertCount(1, $list);
        $this->assertSame('zz**qq', $list[0]['username']);
    }

    public function test_win_list_excludes_outside_business_day(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-01 10:00:00'));
        $ctx = $this->makeJackpotEvent();

        $winner = $this->createMember($this->agent, ['username' => 'zzwwqq']);
        $this->makePrizeMember($ctx, $winner, [
            'status' => 1,
            'created_at' => now()->subDays(2),
        ]);

        $list = $this->service->getWinList($this->agent);

        $this->assertCount(0, $list);
    }

    public function test_win_list_returns_empty_when_no_active_event(): void
    {
        $list = $this->service->getWinList($this->agent);

        $this->assertSame([], $list);
    }

    public function test_receive_credits_balance_and_flow_limit(): void
    {
        $ctx = $this->makeJackpotEvent([], ['flow_limit_multiple' => '2.0000']);
        $prizeMember = $this->makePrizeMember($ctx);

        $this->service->receive($this->member, $this->agent, $prizeMember->id);

        $this->assertSame(888.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) PrizeMember::find($prizeMember->id)->status);

        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'order_id' => $prizeMember->id,
            'type' => TransactionEnum::JACKPOT->value,
            'point' => '888.0000',
        ]);

        $log = TransactionLog::where('order_id', $prizeMember->id)->first();
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'event_id' => $ctx['event']->id,
            'type' => TransactionEnum::JACKPOT->value,
            'reference_id' => $log->id,
            'flow_limit' => '1776.0000',
        ]);
    }

    public function test_receive_skips_flow_limit_when_multiple_is_zero(): void
    {
        $ctx = $this->makeJackpotEvent([], ['flow_limit_multiple' => '0.0000']);
        $prizeMember = $this->makePrizeMember($ctx);

        $this->service->receive($this->member, $this->agent, $prizeMember->id);

        $this->assertSame(0, FlowLimit::where('member_id', $this->member->id)->count());
    }

    public function test_receive_throws_when_not_a_winner(): void
    {
        $this->makeJackpotEvent();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->receive($this->member, $this->agent, 999999);
    }

    public function test_receive_throws_when_already_claimed(): void
    {
        $ctx = $this->makeJackpotEvent();
        $prizeMember = $this->makePrizeMember($ctx, null, ['status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1947'));
        $this->service->receive($this->member, $this->agent, $prizeMember->id);
    }

    public function test_receive_throws_when_expired(): void
    {
        $ctx = $this->makeJackpotEvent();
        $prizeMember = $this->makePrizeMember($ctx, null, ['expired_time' => now()->subDay()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1953'));
        $this->service->receive($this->member, $this->agent, $prizeMember->id);
    }

    public function test_receive_throws_when_member_in_lock_mode(): void
    {
        $ctx = $this->makeJackpotEvent();
        $prizeMember = $this->makePrizeMember($ctx);
        $this->member->update(['event_mode' => 3]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->receive($this->member->fresh(), $this->agent, $prizeMember->id);
    }

    public function test_receive_throws_when_member_has_active_event_v2(): void
    {
        $ctx = $this->makeJackpotEvent();
        $prizeMember = $this->makePrizeMember($ctx);
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
        $this->service->receive($this->member->fresh(), $this->agent, $prizeMember->id);
    }

    public function test_receive_throws_when_member_has_active_newbie_challenge(): void
    {
        $ctx = $this->makeJackpotEvent();
        $prizeMember = $this->makePrizeMember($ctx);
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->receive($this->member->fresh(), $this->agent, $prizeMember->id);
    }

    public function test_receive_rejects_other_members_prize(): void
    {
        $ctx = $this->makeJackpotEvent();
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(6)]);
        $prizeMember = $this->makePrizeMember($ctx, $other);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1946'));
        $this->service->receive($this->member, $this->agent, $prizeMember->id);
    }

    public function test_check_notify_returns_unnotified_records(): void
    {
        $ctx = $this->makeJackpotEvent();

        EventJackpotImage::create([
            'language_id' => 2,
            'event_jackpot_id' => $ctx['jackpot']->id,
            'image' => 'localized.png',
        ]);

        $record = EventJackpotRecord::create([
            'member_id' => $this->member->id,
            'event_jackpot_id' => $ctx['jackpot']->id,
            'prize_member_id' => 0,
            'status' => 0,
        ]);

        EventJackpotRecord::create([
            'member_id' => $this->member->id,
            'event_jackpot_id' => $ctx['jackpot']->id,
            'prize_member_id' => 0,
            'status' => 1,
        ]);

        $default = $this->service->getNotifications($this->member, 1);
        $this->assertCount(1, $default);
        $this->assertSame($record->id, (int) $default[0]->id);

        $localized = $this->service->getNotifications($this->member, 2);
        $this->assertSame('localized.png', $localized[0]->image);
    }

    public function test_confirm_notify_marks_record_as_notified(): void
    {
        $ctx = $this->makeJackpotEvent();
        $record = EventJackpotRecord::create([
            'member_id' => $this->member->id,
            'event_jackpot_id' => $ctx['jackpot']->id,
            'prize_member_id' => 0,
            'status' => 0,
        ]);

        $this->service->confirmNotification($this->member, $record->id);

        $this->assertSame(1, (int) EventJackpotRecord::find($record->id)->status);
    }

    public function test_confirm_notify_ignores_other_members_record(): void
    {
        $ctx = $this->makeJackpotEvent();
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(6)]);
        $record = EventJackpotRecord::create([
            'member_id' => $other->id,
            'event_jackpot_id' => $ctx['jackpot']->id,
            'prize_member_id' => 0,
            'status' => 0,
        ]);

        $this->service->confirmNotification($this->member, $record->id);

        $this->assertSame(0, (int) EventJackpotRecord::find($record->id)->status);
    }

    public function test_confirm_notify_silently_succeeds_when_record_missing(): void
    {
        $this->service->confirmNotification($this->member, 999999);

        $this->assertTrue(true);
    }
}
