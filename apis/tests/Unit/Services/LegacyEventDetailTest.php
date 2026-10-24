<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Event\EventActionIdEnum;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventCode;
use App\Models\EventDetail;
use App\Models\EventProcess;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Services\EventService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * EventService::getLegacyEventDetail 測試（舊版活動詳情 api/event）
 *
 * php artisan test tests/Unit/Services/LegacyEventDetailTest.php
 */
class LegacyEventDetailTest extends TestCase
{
    private EventService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->service = app(EventService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, detail: EventDetail, prize: Prize}
     */
    private function makeEvent(Agent $agent, int $actionId, array $prizeOverrides = [], array $detailOverrides = []): array
    {
        $event = Event::factory()->create([
            'agent_id' => $agent->id,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'auto_payout' => 0,
        ]);

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => $actionId,
            'status' => 1,
            'point' => 100,
            'vendor_id' => 0,
            'game_type_id' => 0,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'draw_time' => now()->addDay(),
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
            'max_point' => '1000.0000',
            'expire_date' => now()->addYear(),
            'status' => 1,
        ], $prizeOverrides));

        return compact('event', 'detail', 'prize');
    }

    private function makePrizeContent(Prize $prize, array $overrides = []): PrizeContent
    {
        return PrizeContent::create(array_merge([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => 'content',
            'number' => 1,
            'level' => 1,
            'point' => 100,
            'percentage' => 0,
            'point_percentage' => '0.5000',
            'achieve_point' => 0,
            'status' => 1,
            'admin' => 0,
        ], $overrides));
    }

    public function test_returns_empty_when_event_not_found(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->assertSame([], $this->service->getLegacyEventDetail($member, $agent, 0, 1));
    }

    /**
     * 舊版未限制品牌，此處補上代理層級限制（刻意偏離，見遷移文件 2-4）
     */
    public function test_returns_empty_when_event_belongs_to_other_agent(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->hierarchy = "[-1,{$other->id},-2]";
        $other->save();
        $theirs = $this->makeEvent($other, EventActionIdEnum::BET->value);

        $result = $this->service->getLegacyEventDetail($member, $agent, $theirs['event']->id, 1);

        $this->assertSame([], $result);
    }

    public function test_returns_event_with_detail_and_prize_contents(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::BET->value);
        $this->makePrizeContent($ctx['prize']);

        $result = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1);

        $this->assertCount(1, $result);
        $row = $result[0];
        $this->assertSame($ctx['event']->id, (int) $row->id);
        $this->assertCount(1, $row->detail);
        $this->assertSame($ctx['detail']->id, (int) $row->detail[0]->id);
        // 非 action 9 時獎項掛在 prize
        $this->assertCount(1, $row->prize);
        $this->assertObjectNotHasProperty('prize_content', $row);
    }

    public function test_claimable_prize_marks_show_exchange(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::BET->value, [], ['point' => 0]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertSame(1, $row->show_exchange);
        $this->assertSame(1, $row->remaining_number);
        $this->assertSame(1, (int) $row->count);
    }

    public function test_exchanged_prize_is_not_claimable(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::BET->value, [], ['point' => 0]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertSame(0, $row->show_exchange);
        $this->assertSame(__('param.prize_button.exchanged'), $row->show_exchange_text);
    }

    public function test_show_exchange_is_downgraded_when_progress_not_reached(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        // 門檻 100，會員進度 10 → 雖有可領獎項，仍不可兌換
        $ctx = $this->makeEvent($agent, EventActionIdEnum::BET->value, [], ['point' => 100]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 0,
            'admin' => 0,
        ]);
        EventProcess::create([
            'member_id' => $member->id,
            'detail_id' => $ctx['detail']->id,
            'point' => '10.0000',
            'received' => 0,
            'admin' => 0,
        ]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertSame(0, $row->show_exchange);
    }

    public function test_login_event_without_prize_member_is_claimable(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::LOGIN->value, [], ['point' => 0]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertSame(1, $row->show_exchange);
    }

    public function test_invite_event_creates_event_code_once(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::INVITE_PLAYER_OFFERS->value);

        $first = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];
        $second = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertNotEmpty($first->detail[0]->code);
        $this->assertSame($first->detail[0]->code, $second->detail[0]->code);
        $this->assertSame(1, EventCode::where('event_id', $ctx['event']->id)->where('member_id', $member->id)->count());
    }

    public function test_slot_rebate_attaches_pay_out_detail_and_percentage(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::SLOT_LOSE_REBATE->value, [], [
            'point' => 0,
            'draw_time' => now()->subHour(),
            'expire_hour' => 0,
        ]);
        $this->makePrizeContent($ctx['prize'], ['point_percentage' => '0.5000']);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertCount(1, $row->pay_out_detail);
        $this->assertSame(1, $row->pay_out_detail[0]->show_exchange);
        // action 9 時獎項掛在 prize_content 並轉為百分比
        $this->assertArrayNotHasKey('prize', $row->getAttributes());
        $this->assertSame(50.0, (float) $row->prize_content[0]->point_percentage);
    }

    public function test_games_are_not_attached_when_detail_has_no_vendor(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::BET->value, [], ['vendor_id' => 0]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertObjectNotHasProperty('games', $row->detail[0]);
    }

    public function test_games_are_attached_when_vendor_is_playable(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $vendor = $this->createBasicVendorGames($agent);
        // action 1 不套用 join_bet_event / join_win_event 條件
        $ctx = $this->makeEvent($agent, EventActionIdEnum::DEPOSIT->value, [], ['vendor_id' => $vendor->id]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];

        $this->assertCount(1, $row->detail[0]->games);
        $this->assertSame($vendor->id, (int) $row->detail[0]->games[0]->vendor_id);
        $this->assertSame(0, (int) $row->detail[0]->games[0]->is_favourite);
    }

    public function test_bet_event_games_require_join_bet_event_flag(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $vendor = $this->createBasicVendorGames($agent);
        $ctx = $this->makeEvent($agent, EventActionIdEnum::BET->value, [], ['vendor_id' => $vendor->id]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];
        $this->assertCount(0, $row->detail[0]->games);

        Cache::flush();
        $vendor->vendorInfos()->first()->vendorInfoGames()->update(['join_bet_event' => 1]);

        $row = $this->service->getLegacyEventDetail($member, $agent, $ctx['event']->id, 1)[0];
        $this->assertCount(1, $row->detail[0]->games);
    }
}
