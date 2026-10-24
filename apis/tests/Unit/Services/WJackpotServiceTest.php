<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\FlowLimit;
use App\Models\LuckyDraw;
use App\Models\LuckyDrawTicket;
use App\Models\Member;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeMember;
use App\Models\RewardItem;
use App\Models\TransactionLog;
use App\Models\WJackpot;
use App\Models\WJackpotRewardItem;
use App\Models\WJackpotTicket;
use App\Services\WJackpotService;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WJackpotService 測試（W-Jackpot 與 W-Jackpot 6D）
 *
 * php artisan test tests/Unit/Services/WJackpotServiceTest.php
 */
class WJackpotServiceTest extends TestCase
{
    private WJackpotService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->service = app(WJackpotService::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, eventDetail: EventDetail, prize: Prize}
     */
    private function makeEvent(Agent $agent, array $eventDetailOverrides = [], int $prizeTypeId = 1, int $flowLimitMultiple = 0): array
    {
        $event = Event::factory()->create([
            'agent_id' => $agent->id,
            'auto_payout' => 0,
        ]);

        $eventDetail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => 1,
            'start_time' => now()->subDays(2),
            'end_time' => now()->addDay(),
            'draw_time' => now()->addDay(),
            'expire_hour' => 24,
            'status' => 1,
        ], $eventDetailOverrides));

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => $prizeTypeId,
            'name' => 'test prize',
            'background' => '',
            'number' => 1,
            'flow_limit_multiple' => $flowLimitMultiple,
            'expire_date' => now()->addYear(),
            'status' => 1,
        ]);

        return compact('event', 'eventDetail', 'prize');
    }

    private function makeWJackpot(array $ctx, array $overrides = []): WJackpot
    {
        return WJackpot::create(array_merge([
            'event_id' => $ctx['event']->id,
            'event_detail_id' => $ctx['eventDetail']->id,
            'no' => 1,
            'draw_time' => now()->subHour(),
            'draw_number' => '0114',
            'draw_number_2' => '0115',
            'draw_number_3' => '0116',
            'draw_number_4' => '0117,0118',
            'draw_number_5' => '0119,0120',
            'status' => 1,
        ], $overrides));
    }

    private function makeWJackpotTicket(WJackpot $issue, Member $member, array $overrides = []): WJackpotTicket
    {
        return WJackpotTicket::create(array_merge([
            'w_jackpot_id' => $issue->id,
            'member_id' => $member->id,
            'ticket_number' => '0114',
            'reward_item_id' => 0,
            'point' => null,
            'type' => 0,
            'status' => 0,
        ], $overrides));
    }

    private function makeWJackpotRewardItem(int $type = 1): WJackpotRewardItem
    {
        return WJackpotRewardItem::create([
            'prize_content_id' => 0,
            'name' => 'r',
            'level' => 1,
            'type' => $type,
            'bonus' => 0,
            'prize_name' => '',
            'status' => 1,
        ]);
    }

    private function makePrizeMember(Prize $prize, int $ticketId, int $memberId): PrizeMember
    {
        return PrizeMember::create([
            'member_id' => $memberId,
            'prize_id' => $prize->id,
            'lottery_number' => $ticketId,
            'status' => 0,
            'admin' => 0,
        ]);
    }

    // ---------------------------------------------------------------- W-Jackpot

    public function test_get_issue_list_returns_only_three_latest_issues(): void
    {
        $agent = $this->createAgentAndDomain();
        $ctx = $this->makeEvent($agent);

        $issues = [];
        foreach ([1, 2, 3, 4, 5] as $no) {
            $issues[$no] = $this->makeWJackpot($ctx, ['no' => $no]);
        }

        $list = $this->service->getIssueList($agent);

        // issues_1272 領取時間限制：只回最近 3 期，且依期數新到舊
        $this->assertCount(3, $list);
        $this->assertSame(
            [$issues[5]->id, $issues[4]->id, $issues[3]->id],
            $list->pluck('issue_id')->all()
        );
    }

    public function test_get_issue_list_excludes_other_agent_events(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->domain = Str::random(12) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();

        $own = $this->makeWJackpot($this->makeEvent($agent), ['no' => 1]);
        $other = $this->makeWJackpot($this->makeEvent($otherAgent), ['no' => 2]);

        $issueIds = $this->service->getIssueList($agent)->pluck('issue_id')->all();

        $this->assertContains($own->id, $issueIds);
        $this->assertNotContains($other->id, $issueIds);
    }

    public function test_get_issue_detail_masks_draw_numbers_before_draw(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx, ['status' => 0]);

        $detail = $this->service->getIssueDetail($issue->id, $member->id);

        // 未開獎時五組號碼皆以 '----' 佔位（4 碼，與 6D 的 '------' 不同）
        $this->assertSame('----', $detail['result_number']);
        $this->assertSame('----', $detail['result_number_2']);
        $this->assertSame('----', $detail['result_number_3']);
        $this->assertSame(['----'], $detail['result_number_4']);
        $this->assertSame(['----'], $detail['result_number_5']);
    }

    public function test_get_issue_detail_splits_multi_draw_numbers_and_sums_summary(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);

        $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '100.0000', 'status' => 0]);
        $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '50.0000', 'status' => 1]);

        $detail = $this->service->getIssueDetail($issue->id, $member->id);

        $this->assertSame(['0117', '0118'], $detail['result_number_4']);
        $this->assertSame(['0119', '0120'], $detail['result_number_5']);
        $this->assertSame(2, $detail['summary']['totalCount_tickets']);
        $this->assertSame('150.00', $detail['summary']['totalCount_winnings']);
        $this->assertSame('100.00', $detail['summary']['totalCount_unclaimed']);
    }

    public function test_get_issue_detail_zeroes_unclaimed_after_expiry(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        // 領獎期限 = event_details.draw_time + expire_hour，設為早已過期
        $ctx = $this->makeEvent($agent, ['draw_time' => now()->subDays(10), 'expire_hour' => 1]);
        $issue = $this->makeWJackpot($ctx);

        $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '100.0000', 'status' => 0]);

        $detail = $this->service->getIssueDetail($issue->id, $member->id);

        $this->assertSame('0', $detail['summary']['totalCount_unclaimed']);
    }

    public function test_get_issue_tickets_resolves_status(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();

        $won = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0]);
        $claimed = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '20.0000', 'status' => 1]);
        $lost = $this->makeWJackpotTicket($issue, $member, ['type' => 0, 'reward_item_id' => 0, 'point' => null, 'status' => 0]);

        $result = $this->service->getIssueTickets($issue->id, $member->id, 1, 20, 1);
        $items = collect($result['paginator']->items())->keyBy('ticket_id');

        $this->assertSame(2, $items[$won->id]['status']);      // 已開獎-未領取
        $this->assertSame(1, $items[$claimed->id]['status']);  // 已開獎-已領取
        $this->assertSame(3, $items[$lost->id]['status']);     // 已開獎-沒中獎
        $this->assertSame(['0117', '0118'], $result['result_number_4']);
    }

    public function test_receive_credits_balance_and_writes_transaction_log(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '0.0000']);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();

        $ticket = $this->makeWJackpotTicket($issue, $member, [
            'type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '100.0000', 'status' => 0,
        ]);
        $this->makePrizeMember($ctx['prize'], $ticket->id, $member->id);

        $this->service->receive($member, $ticket->id);

        $member->refresh();
        $ticket->refresh();

        $this->assertSame('100.0000', $member->balance);
        $this->assertSame(1, $ticket->status);

        $log = TransactionLog::where('order_id', $ticket->id)->latest('id')->first();
        $this->assertSame(30, $log->type); // W_JACKPOT_REWARD
        $this->assertSame('system', $log->operator);

        // flow_limit_multiple = 0 → 不寫稽核流水
        $this->assertFalse(FlowLimit::where('reference_id', $log->id)->exists());
    }

    public function test_receive_writes_flow_limit_from_prize_flow_limit_multiple(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '0.0000']);
        // 稽核流水倍數取自 prizes.flow_limit_multiple（非 agent 設定）
        $ctx = $this->makeEvent($agent, [], 1, 3);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();

        $ticket = $this->makeWJackpotTicket($issue, $member, [
            'type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '100.0000', 'status' => 0,
        ]);
        $this->makePrizeMember($ctx['prize'], $ticket->id, $member->id);

        $this->service->receive($member, $ticket->id);

        $log = TransactionLog::where('order_id', $ticket->id)->latest('id')->first();
        $flowLimit = FlowLimit::where('reference_id', $log->id)->first();

        $this->assertNotNull($flowLimit);
        $this->assertSame('300.0000', $flowLimit->flow_limit);
        $this->assertSame(30, $flowLimit->type); // 沿用舊版：存 TransactionEnum 值
    }

    public function test_receive_throws_when_event_lock_mode_active(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['event_mode' => 1]);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();
        $ticket = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_throws_when_member_has_active_event_v2(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();
        $ticket = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000']);

        $detail = EventV2Detail::factory()->create([
            'event_v2_id' => 1,
            'agent_id' => $agent->id,
            'cost_type' => EventV2DetailCostTypeEnum::COMPLETE->value,
        ]);
        EventV2UserJoin::create([
            'event_v2_detail_id' => $detail->id,
            'member_id' => $member->id,
            'order_id' => 0,
            'reward' => 0,
            'status' => EventV2UserJoinStatusEnum::JOIN->value,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1991'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_throws_when_member_has_active_newbie_challenge(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();
        $ticket = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000']);

        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_throws_when_realname_required_but_not_validated(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->createAgentSetting($agent, AgentCustomizedSettingKeyEnum::EVENT_REWARD_REALNAME_VALIDATION, 1);
        $member = $this->createMember($agent, ['realname_validate' => 0]);

        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();
        $ticket = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.realname_invalid.event_reward'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_throws_when_reward_is_physical_prize(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem(2); // 2 = 獎品

        $ticket = $this->makeWJackpotTicket($issue, $member, [
            'type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1944'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_all_claims_every_winning_ticket(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '0.0000']);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);
        $rewardItem = $this->makeWJackpotRewardItem();

        $a = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0]);
        $b = $this->makeWJackpotTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '20.0000', 'status' => 0]);
        $this->makeWJackpotTicket($issue, $member, ['type' => 0, 'reward_item_id' => 0, 'point' => null, 'status' => 0]);

        $this->makePrizeMember($ctx['prize'], $a->id, $member->id);
        $this->makePrizeMember($ctx['prize'], $b->id, $member->id);

        $this->service->receiveAll($member, $issue->id);

        $member->refresh();
        $this->assertSame('30.0000', $member->balance);
        $this->assertSame(1, $a->refresh()->status);
        $this->assertSame(1, $b->refresh()->status);
    }

    /**
     * 沿用舊版：W-Jackpot 的 receive-all 查無可領取抽獎券時視為成功（不拋錯），
     * 與 6D 版拋 1000 的行為不同，兩者刻意保留差異。
     */
    public function test_receive_all_succeeds_silently_when_nothing_claimable(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '5.0000']);
        $ctx = $this->makeEvent($agent);
        $issue = $this->makeWJackpot($ctx);

        $this->service->receiveAll($member, $issue->id);

        $member->refresh();
        $this->assertSame('5.0000', $member->balance);
    }

    // ------------------------------------------------------------ W-Jackpot 6D

    private function makeLuckyDraw(array $ctx, array $overrides = []): LuckyDraw
    {
        return LuckyDraw::create(array_merge([
            'event_id' => $ctx['event']->id,
            'event_detail_id' => $ctx['eventDetail']->id,
            'no' => 1,
            'draw_time' => now()->subHour(),
            'draw_number' => '123456',
            'status' => 1,
        ], $overrides));
    }

    private function makeLuckyDrawTicket(LuckyDraw $issue, Member $member, array $overrides = []): LuckyDrawTicket
    {
        return LuckyDrawTicket::create(array_merge([
            'lucky_draw_id' => $issue->id,
            'member_id' => $member->id,
            'ticket_number' => '123456',
            'reward_item_id' => 0,
            'point' => null,
            'type' => 0,
            'status' => 0,
        ], $overrides));
    }

    private function makeRewardItem(int $type = 1): RewardItem
    {
        return RewardItem::create([
            'prize_content_id' => 0,
            'name' => 'r',
            'level' => 1,
            'type' => $type,
            'bonus' => 0,
            'prize_name' => '',
            'status' => 1,
        ]);
    }

    public function test_6d_issue_list_only_includes_prize_type_id_7(): void
    {
        $agent = $this->createAgentAndDomain();

        $sixD = $this->makeLuckyDraw($this->makeEvent($agent, [], 7), ['no' => 1]);
        $normal = $this->makeLuckyDraw($this->makeEvent($agent, [], 1), ['no' => 2]);

        $issueIds = $this->service->get6dIssueList($agent)->pluck('issue_id')->all();

        $this->assertContains($sixD->id, $issueIds);
        $this->assertNotContains($normal->id, $issueIds);
    }

    public function test_6d_issue_detail_masks_draw_number_with_six_dashes(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeLuckyDraw($this->makeEvent($agent, [], 7), ['status' => 0]);

        $detail = $this->service->get6dIssueDetail($issue->id, $member->id);

        $this->assertSame('------', $detail['result_number']);
    }

    public function test_6d_issue_tickets_marks_won_ticket_expired_after_deadline(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        // draw_time + expire_hour 已過，但整體 expire_date 判定同樣過期
        $ctx = $this->makeEvent($agent, ['draw_time' => now()->subDays(3), 'expire_hour' => 1], 7);
        $issue = $this->makeLuckyDraw($ctx);
        $rewardItem = $this->makeRewardItem();

        $won = $this->makeLuckyDrawTicket($issue, $member, [
            'type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0,
        ]);

        $result = $this->service->get6dIssueTickets($issue->id, $member->id, 1, 20, 1);
        $items = collect($result['paginator']->items())->keyBy('ticket_id');

        $this->assertSame(4, $items[$won->id]['status']); // 已過期
    }

    public function test_6d_receive_credits_balance(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '0.0000']);
        $ctx = $this->makeEvent($agent, [], 7);
        $issue = $this->makeLuckyDraw($ctx);
        $rewardItem = $this->makeRewardItem();

        $ticket = $this->makeLuckyDrawTicket($issue, $member, [
            'type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '88.0000', 'status' => 0,
        ]);
        $this->makePrizeMember($ctx['prize'], $ticket->id, $member->id);

        $this->service->receive6d($member, $ticket->id);

        $member->refresh();
        $this->assertSame('88.0000', $member->balance);
        $this->assertSame(1, $ticket->refresh()->status);

        $log = TransactionLog::where('order_id', $ticket->id)->latest('id')->first();
        $this->assertSame(31, $log->type); // W_JACKPOT_6D_REWARD
    }

    public function test_6d_receive_throws_when_past_claim_deadline(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, ['draw_time' => now()->subDays(3), 'expire_hour' => 1], 7);
        $issue = $this->makeLuckyDraw($ctx);
        $rewardItem = $this->makeRewardItem();

        $ticket = $this->makeLuckyDrawTicket($issue, $member, [
            'type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0,
        ]);
        $this->makePrizeMember($ctx['prize'], $ticket->id, $member->id);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1948'));

        $this->service->receive6d($member, $ticket->id);
    }

    public function test_6d_receive_all_claims_only_unexpired_tickets(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '0.0000']);
        $ctx = $this->makeEvent($agent, [], 7);
        $issue = $this->makeLuckyDraw($ctx);
        $rewardItem = $this->makeRewardItem();

        $a = $this->makeLuckyDrawTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0]);
        $b = $this->makeLuckyDrawTicket($issue, $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '20.0000', 'status' => 0]);

        $this->makePrizeMember($ctx['prize'], $a->id, $member->id);
        $this->makePrizeMember($ctx['prize'], $b->id, $member->id);

        $this->service->receive6dAll($member, $issue->id);

        $member->refresh();
        $this->assertSame('30.0000', $member->balance);
    }

    /**
     * 沿用舊版：6D 的 receive-all 查無可領取抽獎券時拋 1000（與非 6D 版靜默成功不同）
     */
    public function test_6d_receive_all_throws_when_nothing_claimable(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeLuckyDraw($this->makeEvent($agent, [], 7));

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1000'));

        $this->service->receive6dAll($member, $issue->id);
    }

    public function test_6d_receive_all_skips_tickets_past_deadline(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $ctx = $this->makeEvent($agent, ['draw_time' => now()->subDays(3), 'expire_hour' => 1], 7);
        $issue = $this->makeLuckyDraw($ctx);
        $rewardItem = $this->makeRewardItem();

        $ticket = $this->makeLuckyDrawTicket($issue, $member, [
            'type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0,
        ]);
        $this->makePrizeMember($ctx['prize'], $ticket->id, $member->id);

        // 逾期券被 whereRaw 過濾掉 → 視同查無資料
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1000'));

        $this->service->receive6dAll($member, $issue->id);
    }
}
