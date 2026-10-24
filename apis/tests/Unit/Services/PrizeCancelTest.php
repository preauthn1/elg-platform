<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventExcludeAgent;
use App\Models\EventPayoutCancel;
use App\Models\Member;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Services\PrizeService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PrizeService::cancelPrize 測試（舊版 api/cancelPrize）
 *
 * php artisan test tests/Unit/Services/PrizeCancelTest.php
 */
class PrizeCancelTest extends TestCase
{
    private PrizeService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(PrizeService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, detail: EventDetail, prize: Prize, content: PrizeContent}
     */
    private function makeEvent(array $detailOverrides = [], array $eventOverrides = []): array
    {
        $event = Event::factory()->create(array_merge([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ], $eventOverrides));

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            // 53 首充：屬 cancelPrize 的處理範圍
            'action_id' => EventActionIdEnum::FIRST_DEPOSIT_MANUAL->value,
            'status' => 1,
            'point' => 0,
            'vendor_id' => 0,
            'game_type_id' => 0,
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
            'expire_date' => now()->addYear(),
            'info' => json_encode([]),
            'status' => 1,
        ]);

        $content = PrizeContent::create([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => 'content',
            'number' => 10,
            'sent' => 0,
            'level' => 0,
            'point' => 50,
            'percentage' => 100,
            'point_type' => 1,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'flow_limit_multiple' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        return compact('event', 'detail', 'prize', 'content');
    }

    private function makePending(array $ctx, array $overrides = []): PrizeMember
    {
        return PrizeMember::create(array_merge([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'content_id' => $ctx['content']->id,
            'status' => 0,
            'admin' => 0,
        ], $overrides));
    }

    public function test_cancels_pending_reward_and_records_cancel(): void
    {
        $ctx = $this->makeEvent();
        $prizeMember = $this->makePending($ctx);

        $this->service->cancelPrize($this->member, $this->agent);

        $this->assertSame(2, (int) PrizeMember::find($prizeMember->id)->status);
        $this->assertDatabaseHas('event_payout_cancels', [
            'event_id' => $ctx['event']->id,
            'prize_member_id' => $prizeMember->id,
            'member_id' => $this->member->id,
            'from_member_id' => $this->member->id,
            'type' => 5,
        ]);
    }

    public function test_cancels_every_pending_reward(): void
    {
        $first = $this->makeEvent();
        $second = $this->makeEvent(['action_id' => EventActionIdEnum::PICK_UP_ONCE_A_DAY->value]);
        $a = $this->makePending($first);
        $b = $this->makePending($second);

        $this->service->cancelPrize($this->member, $this->agent);

        $this->assertSame(2, (int) PrizeMember::find($a->id)->status);
        $this->assertSame(2, (int) PrizeMember::find($b->id)->status);
        $this->assertSame(2, EventPayoutCancel::where('member_id', $this->member->id)->count());
    }

    public function test_throws_when_no_qualifying_event_detail(): void
    {
        // action 2 不在 53/54/55 範圍內
        $this->makeEvent(['action_id' => EventActionIdEnum::BET->value]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2001'));
        $this->service->cancelPrize($this->member, $this->agent);
    }

    public function test_already_claimed_reward_is_untouched(): void
    {
        $ctx = $this->makeEvent();
        $claimed = $this->makePending($ctx, ['status' => 1]);

        $this->service->cancelPrize($this->member, $this->agent);

        $this->assertSame(1, (int) PrizeMember::find($claimed->id)->status);
        $this->assertSame(0, EventPayoutCancel::where('member_id', $this->member->id)->count());
    }

    public function test_expired_reward_is_untouched(): void
    {
        $ctx = $this->makeEvent();
        $expired = $this->makePending($ctx, ['expired_time' => now()->subDay()]);

        $this->service->cancelPrize($this->member, $this->agent);

        $this->assertSame(0, (int) PrizeMember::find($expired->id)->status);
        $this->assertSame(0, EventPayoutCancel::where('member_id', $this->member->id)->count());
    }

    public function test_null_expired_time_is_cancellable(): void
    {
        $ctx = $this->makeEvent();
        $prizeMember = $this->makePending($ctx, ['expired_time' => null]);

        $this->service->cancelPrize($this->member, $this->agent);

        $this->assertSame(2, (int) PrizeMember::find($prizeMember->id)->status);
    }

    public function test_other_members_reward_is_untouched(): void
    {
        $ctx = $this->makeEvent();
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(10)]);
        $theirs = PrizeMember::create([
            'member_id' => $other->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'content_id' => $ctx['content']->id,
            'status' => 0,
            'admin' => 0,
        ]);
        $mine = $this->makePending($ctx);

        $this->service->cancelPrize($this->member, $this->agent);

        $this->assertSame(2, (int) PrizeMember::find($mine->id)->status);
        $this->assertSame(0, (int) PrizeMember::find($theirs->id)->status);
    }

    public function test_excluded_agent_event_is_not_cancellable(): void
    {
        $ctx = $this->makeEvent();
        $this->makePending($ctx);
        EventExcludeAgent::create([
            'event_id' => $ctx['event']->id,
            'agent_id' => $this->agent->id,
        ]);
        Cache::flush();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2001'));
        $this->service->cancelPrize($this->member, $this->agent);
    }

    public function test_expired_prize_event_is_not_cancellable(): void
    {
        $ctx = $this->makeEvent();
        $this->makePending($ctx);
        $ctx['prize']->update(['expire_date' => now()->subDay()]);
        Cache::flush();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2001'));
        $this->service->cancelPrize($this->member, $this->agent);
    }
}
