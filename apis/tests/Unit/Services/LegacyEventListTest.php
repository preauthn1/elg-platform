<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Services\EventService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * EventService::getLegacyEventList 測試（舊版活動列表 api/events）
 *
 * 重點覆蓋 tpower-master / vw-master 聯集後的分支：
 * action 17（每周救援金）、action 59（每日連續儲值）、prize type_id=10、flow_limit_game_type
 *
 * php artisan test tests/Unit/Services/LegacyEventListTest.php
 */
class LegacyEventListTest extends TestCase
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
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'draw_time' => now()->addDay(),
            'expire_hour' => 0,
            'account_block' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'tgid_block' => 0,
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

    private function findRow(array $result, int $eventId): ?object
    {
        foreach ($result['data'] as $row) {
            if ((int) $row->id === $eventId) {
                return $row;
            }
        }

        return null;
    }

    public function test_returns_events_for_own_agent_only(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $mine = $this->makeEvent($agent, 2);

        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->hierarchy = "[-1,{$other->id},-2]";
        $other->save();
        $theirs = $this->makeEvent($other, 2);

        $result = $this->service->getLegacyEventList($member, $agent, 1);

        $this->assertNotNull($this->findRow($result, $mine['event']->id));
        $this->assertNull($this->findRow($result, $theirs['event']->id));
    }

    public function test_excludes_event_whose_prize_has_expired(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $this->makeEvent($agent, 2, ['expire_date' => now()->subDay()]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);

        $this->assertSame(0, $result['total']);
    }

    public function test_claimable_prize_is_sorted_first_and_marks_is_reddeem(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        // 一個可兌換（有未領取的 prize_member，且達標門檻為 0）、一個純儲值活動
        $claimable = $this->makeEvent($agent, 2, [], ['point' => 0]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $claimable['prize']->id,
            'status' => 0,
            'admin' => 0,
        ]);
        $this->makeEvent($agent, 51);

        $result = $this->service->getLegacyEventList($member, $agent, 1);

        $this->assertSame(1, $result['is_reddeem']);
        // sort=1（可兌換）應排在最前
        $this->assertSame($claimable['event']->id, (int) $result['data'][0]->id);
        $this->assertSame(1, $result['data'][0]->show_exchange);
    }

    /**
     * 沿用舊版：即使有未領取的 prize_member，若 event_processes 進度未達 detail.point，
     * 仍會被降級為不可兌換（no_schedule_action_id 之外的 action 才適用）
     */
    public function test_claimable_is_downgraded_when_progress_not_reached(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $ctx = $this->makeEvent($agent, 2, [], ['point' => 100]);
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        // 進度 0 < 門檻 100 → 不可兌換
        $this->assertSame(0, $row->show_exchange);
        // is_reddeem 仍依降級前狀態計算（舊版行為）
        $this->assertSame(1, $result['is_reddeem']);
    }

    /**
     * vw-master 獨有欄位，聯集後保留
     */
    public function test_exposes_flow_limit_game_type_from_prize_info(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 2, [
            'info' => json_encode(['flow_limit_game_type' => ['1', '2']]),
        ]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(['1', '2'], $row->flow_limit_game_type);
    }

    public function test_flow_limit_game_type_defaults_to_zero_when_absent(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 2);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(0, $row->flow_limit_game_type);
    }

    /**
     * tpower-master 獨有：每周救援金以 lottery_point * point_percentage 計算，並受 max_point 上限
     */
    public function test_weekly_rescue_fund_reward_point_is_capped_by_max_point(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 17, ['max_point' => '500.0000']);

        $content = PrizeContent::create([
            'prize_id' => $ctx['prize']->id,
            'type' => 1,
            'name' => 'c',
            'number' => 1,
            'level' => 1,
            'point' => 0,
            'percentage' => 0,
            'point_percentage' => '0.5000',
            'achieve_point' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        // 2000 * 0.5 = 1000 > max_point 500 → 取 500
        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'content_id' => $content->id,
            'lottery_point' => '2000.0000',
            'status' => 0,
            'admin' => 0,
        ]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame('500.00', $row->reward_point);
    }

    public function test_weekly_rescue_fund_reward_point_is_zero_without_claimable_record(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 17);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame('0.00', $row->reward_point);
    }

    /**
     * tpower-master 獨有：每日連續儲值進度
     */
    public function test_consecutive_deposit_exposes_progress(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 59);

        PrizeContent::create([
            'prize_id' => $ctx['prize']->id,
            'type' => 0,
            'name' => 'c',
            'number' => 1,
            'level' => 3,
            'point' => 100,
            'percentage' => 0,
            'point_percentage' => 0,
            'achieve_point' => '100.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        DB::table('event_member_orders')->insert([
            'event_id' => $ctx['event']->id,
            'member_id' => $member->id,
            'consecutive_times' => 3,
            'max_consecutive_times' => 3,
            'accumulated_amount' => '500.0000',
            'is_first_day' => 0,
            'last_deposit_time' => now()->toDateTimeString(),
        ]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(3, (int) $row->consecutive_day);
        $this->assertSame(3, (int) $row->max_consecutive_day);
    }

    public function test_consecutive_deposit_defaults_to_zero_without_order(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 59);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(0, $row->consecutive_day);
        $this->assertSame(0, $row->max_consecutive_day);
    }

    /**
     * 下線充值獎金：達標人數不足時不可兌換
     */
    public function test_downline_deposit_bonus_reports_receive_counts(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 16, ['info' => json_encode(['achieve_people' => 1])]);

        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(1, (int) $row->can_receive);
        $this->assertSame(0, (int) $row->has_receive);
        $this->assertSame(1, $row->show_exchange);
    }

    /**
     * 登入送：沒有 prize_members 即視為可領取
     */
    public function test_login_gift_is_claimable_when_no_prize_member_exists(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 12);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(1, $row->show_exchange);
    }

    /**
     * 每日領取一次：當日已領取則標記 reached_today 並顯示已兌換
     */
    public function test_daily_claim_marks_reached_today(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 55);

        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(1, $row->reached_today);
        $this->assertSame(0, $row->show_exchange);
    }

    public function test_includes_prize_content_and_detail(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, 2);

        PrizeContent::create([
            'prize_id' => $ctx['prize']->id,
            'type' => 0,
            'name' => 'content-a',
            'number' => 1,
            'level' => 1,
            'point' => 50,
            'percentage' => 0,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'status' => 1,
            'admin' => 0,
        ]);

        $result = $this->service->getLegacyEventList($member, $agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertCount(1, $row->prize_content);
        $this->assertSame('content-a', $row->prize_content[0]->name);
        // percentage 不外露
        $this->assertObjectNotHasProperty('percentage', $row->prize_content[0]);
        $this->assertCount(1, $row->detail);
        $this->assertSame($ctx['detail']->id, (int) $row->detail[0]->id);
    }

    // ---------------------------------------------- eventsByDomain（免登入）

    public function test_by_domain_returns_events_without_member_state(): void
    {
        $agent = $this->createAgentAndDomain();
        $ctx = $this->makeEvent($agent, 2);

        $result = $this->service->getLegacyEventListByDomain($agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertNotNull($row);
        // 未登入：一律不可兌換、無剩餘次數
        $this->assertSame(0, $row->show_exchange);
        $this->assertSame(0, $row->remaining_number);
        $this->assertSame(0, $result['is_reddeem']);
        // 進度固定為 0（無 event_processes join）
        $this->assertSame(0, (int) $row->detail[0]->now_point);
    }

    /**
     * 沿用舊版：未登入版不做 event_details 起訖時間過濾，
     * 因此尚未開始 / 已結束的 detail 對應的活動仍會出現在列表
     */
    public function test_by_domain_does_not_filter_by_event_detail_time_window(): void
    {
        $agent = $this->createAgentAndDomain();
        $ctx = $this->makeEvent($agent, 2, [], [
            'start_time' => now()->addDays(5),
            'end_time' => now()->addDays(10),
        ]);

        $result = $this->service->getLegacyEventListByDomain($agent, 1);

        $this->assertNotNull($this->findRow($result, $ctx['event']->id));
    }

    public function test_by_domain_masks_invite_code(): void
    {
        $agent = $this->createAgentAndDomain();
        $ctx = $this->makeEvent($agent, 31);

        $result = $this->service->getLegacyEventListByDomain($agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        // 未登入不產生真實邀請碼
        $this->assertSame('-', $row->detail[0]->code);
        $this->assertSame(0, DB::table('event_codes')->where('event_id', $ctx['event']->id)->count());
    }

    public function test_by_domain_sets_reached_today_zero_for_daily_pickup(): void
    {
        $agent = $this->createAgentAndDomain();
        $ctx = $this->makeEvent($agent, 55);

        $result = $this->service->getLegacyEventListByDomain($agent, 1);
        $row = $this->findRow($result, $ctx['event']->id);

        $this->assertSame(0, $row->reached_today);
    }

    public function test_by_domain_excludes_other_agent_events(): void
    {
        $agent = $this->createAgentAndDomain();
        $mine = $this->makeEvent($agent, 2);

        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->hierarchy = "[-1,{$other->id},-2]";
        $other->save();
        $theirs = $this->makeEvent($other, 2);

        $result = $this->service->getLegacyEventListByDomain($agent, 1);

        $this->assertNotNull($this->findRow($result, $mine['event']->id));
        $this->assertNull($this->findRow($result, $theirs['event']->id));
    }
}
