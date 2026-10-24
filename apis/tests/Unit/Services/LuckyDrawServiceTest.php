<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\LuckyDraw;
use App\Models\LuckyDrawTicket;
use App\Models\Member;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeMember;
use App\Models\RewardItem;
use App\Services\LuckyDrawService;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * LuckyDrawService 測試
 *
 * php artisan test tests/Unit/Services/LuckyDrawServiceTest.php
 */
class LuckyDrawServiceTest extends TestCase
{
    private LuckyDrawService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new LuckyDrawService;
        $this->seed(SyncAgentSettingDefinesSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{agent: Agent, event: Event, eventDetail: EventDetail, luckyDraw: LuckyDraw, prize: Prize}
     */
    private function makeIssue(Agent $agent, array $eventOverrides = [], array $eventDetailOverrides = [], array $luckyDrawOverrides = []): array
    {
        $event = Event::factory()->create(array_merge([
            'agent_id' => $agent->id,
            'auto_payout' => 0,
        ], $eventOverrides));

        $eventDetail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => 1,
            'start_time' => now()->subDays(2),
            'end_time' => now()->subDay(),
            // 領獎期限以 draw_time 為基準，必須固定；factory 預設是隨機日期會讓測試 flaky
            'draw_time' => now()->subHour(),
            'expire_hour' => 0,
        ], $eventDetailOverrides));

        $luckyDraw = LuckyDraw::create(array_merge([
            'event_id' => $event->id,
            'event_detail_id' => $eventDetail->id,
            'no' => 1,
            'draw_time' => now()->subDay(),
            'draw_number' => '123456-1',
            'status' => 1,
        ], $luckyDrawOverrides));

        $prize = Prize::create([
            'event_id' => $event->id,
            'type_id' => 1,
            'name' => 'test prize',
            'background' => '',
            'number' => 1,
            'expire_date' => now()->addYear(),
            'status' => 1,
        ]);

        return compact('agent', 'event', 'eventDetail', 'luckyDraw', 'prize');
    }

    private function makeTicket(LuckyDraw $luckyDraw, Member $member, array $overrides = []): LuckyDrawTicket
    {
        return LuckyDrawTicket::create(array_merge([
            'lucky_draw_id' => $luckyDraw->id,
            'member_id' => $member->id,
            'ticket_number' => '123456-1',
            'reward_item_id' => 0,
            'point' => null,
            'type' => 0,
            'status' => 0,
        ], $overrides));
    }

    private function makePrizeMember(Prize $prize, int $ticketId, int $memberId, int $status = 0): PrizeMember
    {
        return PrizeMember::create([
            'member_id' => $memberId,
            'prize_id' => $prize->id,
            'lottery_number' => $ticketId,
            'status' => $status,
            'admin' => 0,
        ]);
    }

    private function makeRewardItem(array $overrides = []): RewardItem
    {
        return RewardItem::create(array_merge([
            'prize_content_id' => 0,
            'name' => 'r',
            'level' => 1,
            'type' => 1,
            'bonus' => 0,
            'prize_name' => '',
            'status' => 1,
        ], $overrides));
    }

    public function test_get_issue_list_includes_global_and_own_agent_events_only(): void
    {
        $agent = $this->createAgentAndDomain();
        $this->domain = Str::random(12) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();

        $own = $this->makeIssue($agent, [], [], ['no' => 10]);
        $global = $this->makeIssue($agent, ['agent_id' => null], [], ['no' => 20]);
        $other = $this->makeIssue($otherAgent, [], [], ['no' => 30]);

        $list = $this->service->getIssueList($agent);

        $issueIds = $list->pluck('issue_id')->all();
        $this->assertContains($own['luckyDraw']->id, $issueIds);
        $this->assertContains($global['luckyDraw']->id, $issueIds);
        $this->assertNotContains($other['luckyDraw']->id, $issueIds);
    }

    public function test_get_issue_detail_computes_summary_and_zeroes_unclaimed_after_expiry(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        // 已過期: expire_date = event_details.draw_time + expire_hour，此處設為 10 天前 + 1 小時
        $issue = $this->makeIssue($agent, [], ['draw_time' => now()->subDays(10), 'expire_hour' => 1]);

        $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '100.0000', 'status' => 0]);
        $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '50.0000', 'status' => 1]);

        $detail = $this->service->getIssueDetail($issue['luckyDraw']->id, $member->id);

        $this->assertSame(2, $detail['summary']['totalCount_tickets']);
        $this->assertSame('150.00', $detail['summary']['totalCount_winnings']);
        // 已過期, 未領取金額歸零
        $this->assertSame('0', $detail['summary']['totalCount_unclaimed']);
    }

    public function test_get_issue_detail_throws_when_issue_not_found(): void
    {
        $this->expectException(ApiException::class);
        $this->service->getIssueDetail(999999999, 1);
    }

    public function test_get_issue_tickets_resolves_status_for_won_unclaimed_and_lost_tickets(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeIssue($agent, [], ['end_time' => now()->addDay(), 'expire_hour' => 0]);

        $won = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '10.0000', 'status' => 0]);
        $claimed = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '20.0000', 'status' => 1]);
        $lost = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 0, 'reward_item_id' => 0, 'point' => null, 'status' => 0]);

        $result = $this->service->getIssueTickets($issue['luckyDraw']->id, $member->id, 1, 20, 1);

        $items = collect($result['paginator']->items())->keyBy('ticket_id');
        $this->assertSame(2, $items[$won->id]['status']); // 已開獎-未領取
        $this->assertSame(1, $items[$claimed->id]['status']); // 已開獎-已領取
        $this->assertSame(3, $items[$lost->id]['status']); // 已開獎-沒中獎
        $this->assertSame($issue['luckyDraw']->draw_number, $result['result_number']);
    }

    public function test_receive_credits_balance_and_marks_ticket_claimed(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '0.0000']);
        $issue = $this->makeIssue($agent);

        $rewardItem = $this->makeRewardItem();
        $ticket = $this->makeTicket($issue['luckyDraw'], $member, [
            'type' => 1,
            'reward_item_id' => $rewardItem->id,
            'point' => '100.0000',
            'status' => 0,
        ]);
        $this->makePrizeMember($issue['prize'], $ticket->id, $member->id);

        $this->service->receive($member, $ticket->id);

        $member->refresh();
        $ticket->refresh();

        $this->assertSame('100.0000', $member->balance);
        $this->assertSame(1, $ticket->status);
        $this->assertNotNull($ticket->time);
    }

    public function test_receive_throws_when_ticket_not_found(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1941'));

        $this->service->receive($member, 999999999);
    }

    /**
     * 沿用舊版查詢: receive() 對 reward_items 使用 INNER JOIN，
     * 未中獎券 (reward_item_id=0) 因無對應 reward_items.id=0 而查無此券 (1941)，
     * 而非命中「未中獎」(1942) 分支 —— 與舊版 EventLuckyDrawController::receive() 行為一致。
     */
    public function test_receive_throws_not_found_when_ticket_has_no_reward_item(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeIssue($agent);

        $ticket = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 0, 'reward_item_id' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1941'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_throws_when_already_claimed(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeIssue($agent);

        $rewardItem = $this->makeRewardItem();
        $ticket = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 1]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1943'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_throws_when_event_lock_mode_active(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['event_mode' => 1]);
        $issue = $this->makeIssue($agent);

        $ticket = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '10.0000']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_throws_when_member_has_active_event_v2(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeIssue($agent);

        $ticket = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '10.0000']);

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
        $issue = $this->makeIssue($agent);

        $ticket = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => 1, 'point' => '10.0000']);

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

    public function test_receive_all_claims_every_winning_unclaimed_ticket(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent, ['balance' => '0.0000']);
        $issue = $this->makeIssue($agent);

        $rewardItem = $this->makeRewardItem();

        $ticketA = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '10.0000', 'status' => 0]);
        $ticketB = $this->makeTicket($issue['luckyDraw'], $member, ['type' => 1, 'reward_item_id' => $rewardItem->id, 'point' => '20.0000', 'status' => 0]);
        $this->makeTicket($issue['luckyDraw'], $member, ['type' => 0, 'reward_item_id' => 0, 'point' => null, 'status' => 0]);

        $this->makePrizeMember($issue['prize'], $ticketA->id, $member->id);
        $this->makePrizeMember($issue['prize'], $ticketB->id, $member->id);

        $this->service->receiveAll($member, $issue['luckyDraw']->id);

        $member->refresh();
        $ticketA->refresh();
        $ticketB->refresh();

        $this->assertSame('30.0000', $member->balance);
        $this->assertSame(1, $ticketA->status);
        $this->assertSame(1, $ticketB->status);
    }

    /**
     * lucky_draw_tickets 已標記中獎（reward_item_id > 0, type = 1, status = 0），
     * 但對應的 prize_members 憑證不存在時的行為（見 docs/ticket_4005/lucky-draw-flow.md §5）。
     * 註：此情境當初是排查一筆人工建立的 Dev 測試資料時發現，並非證實會自然發生的
     * production 案例；此處僅記錄 receive() 現行行為——這種情況與「併發下已被搶先領取」
     * 共用同一個 error_message.2001，訊息無法區分成因。
     */
    public function test_receive_throws_when_prize_member_missing_despite_winning_ticket(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeIssue($agent);

        $rewardItem = $this->makeRewardItem();
        $ticket = $this->makeTicket($issue['luckyDraw'], $member, [
            'type' => 1,
            'reward_item_id' => $rewardItem->id,
            'point' => '18.0000',
            'status' => 0,
        ]);
        // 刻意不呼叫 makePrizeMember()：重現「中獎票券查無對應 prize_members 憑證」的資料缺口。

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2001'));

        $this->service->receive($member, $ticket->id);
    }

    public function test_receive_all_throws_when_no_claimable_tickets(): void
    {
        $agent = $this->createAgentAndDomain();
        $member = $this->createMember($agent);
        $issue = $this->makeIssue($agent);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1000'));

        $this->service->receiveAll($member, $issue['luckyDraw']->id);
    }
}
