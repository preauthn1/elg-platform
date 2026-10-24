<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\Member;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Services\EventService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EventService::validateLegacyEvent 測試（舊版活動資格驗證 api/event/validate）
 *
 * 僅 tpower-master 有此端點，目前只支援 action 60（邀請玩家活動）。
 *
 * php artisan test tests/Unit/Services/LegacyEventValidateTest.php
 */
class LegacyEventValidateTest extends TestCase
{
    private EventService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(EventService::class);
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
    private function makeInviteEvent(array $prizeInfo = ['achieve_people' => 2], array $detailOverrides = []): array
    {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'auto_payout' => 0,
        ]);

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::TPOWER_INVITE_PLAYER->value,
            'status' => 1,
            'point' => 0,
            'vendor_id' => 0,
            'game_type_id' => 0,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'draw_time' => now()->addDay(),
            'expire_hour' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 0,
            'global_ip_block' => 0,
            'global_phone_block' => 0,
            'global_account_block' => 0,
        ], $detailOverrides));

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 3,
            'name' => 'prize',
            'background' => '',
            'number' => 1,
            'is_repeatedly' => 0,
            'flow_limit_multiple' => 0,
            'max_point' => '1000.0000',
            'expire_date' => now()->addYear(),
            'info' => json_encode($prizeInfo),
            'status' => 1,
        ]);

        $content = PrizeContent::create([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => 'content',
            'number' => 1,
            'level' => 1,
            'point' => 100,
            'percentage' => 0,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        return compact('event', 'detail', 'prize', 'content');
    }

    private function makeInvitee(Event $event, array $recordOverrides = [], array $memberOverrides = []): Member
    {
        $invitee = $this->createMember($this->agent, array_merge([
            'username' => 'invitee_' . Str::random(12),
            'status' => 1,
            'realname_validate' => 1,
            'mobile_validate' => 1,
        ], $memberOverrides));

        EventInviteRecord::create(array_merge([
            'event_id' => $event->id,
            'member_id' => $invitee->id,
            'invite_member_id' => $this->member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ], $recordOverrides));

        return $invitee;
    }

    public function test_throws_when_event_not_found(): void
    {
        $this->expectException(ApiException::class);
        $this->service->validateLegacyEvent($this->member, $this->agent, 0);
    }

    public function test_throws_when_action_id_is_not_supported(): void
    {
        $ctx = $this->makeInviteEvent([], ['action_id' => EventActionIdEnum::BET->value]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_throws_when_invited_members_are_insufficient(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 2]);
        $this->makeInvitee($ctx['event']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1990', ['invite' => 1]));
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_creates_pending_prize_member_when_reached(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 2]);
        $this->makeInvitee($ctx['event']);
        $this->makeInvitee($ctx['event']);

        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);

        $this->assertDatabaseHas('prize_members', [
            'member_id' => $this->member->id,
            'event_detail_id' => $ctx['detail']->id,
            'prize_id' => $ctx['prize']->id,
            'content_id' => $ctx['content']->id,
            'status' => 0,
        ]);
    }

    public function test_does_not_create_duplicate_pending_prize_member(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 1]);
        $this->makeInvitee($ctx['event']);

        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);

        $this->assertSame(1, PrizeMember::where('member_id', $this->member->id)->count());
    }

    public function test_unvalidated_invitees_are_not_counted(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 1]);
        $this->makeInvitee($ctx['event'], [], ['realname_validate' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1990', ['invite' => 0]));
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_duplicate_ip_invitees_are_excluded_when_ip_block_enabled(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 1], ['ip_block' => 1]);
        $this->makeInvitee($ctx['event'], ['ip_duplicate' => 1]);

        $this->expectException(ApiException::class);
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_already_bound_invite_records_are_excluded(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 1]);
        $this->makeInvitee($ctx['event'], ['prize_member_id' => 999]);

        $this->expectException(ApiException::class);
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_global_block_rejects_member_sharing_mobile_with_other_winner(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 1], ['global_phone_block' => 1]);
        $this->makeInvitee($ctx['event']);

        // 另一位會員以相同手機號已取得該明細的獎勵
        $other = $this->createMember($this->agent, [
            'username' => 'other_' . Str::random(12),
            'mobile' => $this->member->mobile,
        ]);
        PrizeMember::create([
            'member_id' => $other->id,
            'event_detail_id' => $ctx['detail']->id,
            'prize_id' => $ctx['prize']->id,
            'content_id' => $ctx['content']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1937'));
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
    }

    public function test_first_deposit_days_condition_filters_invitees(): void
    {
        $ctx = $this->makeInviteEvent(['achieve_people' => 1, 'first_deposit_days' => 7]);
        // 沒有任何符合條件的訂單 → 不計入
        $this->makeInvitee($ctx['event']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1990', ['invite' => 0]));
        $this->service->validateLegacyEvent($this->member, $this->agent, $ctx['event']->id);
    }
}
