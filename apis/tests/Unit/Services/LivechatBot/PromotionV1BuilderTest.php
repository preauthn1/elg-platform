<?php

declare(strict_types=1);

namespace Tests\Unit\Services\LivechatBot;

use App\Enums\Event\EventActionIdEnum;
use App\Enums\Event\PrizeMemberStatusEnum;
use App\Enums\LivechatBotPromotionSourceEnum;
use App\Enums\OrderStatusEnum;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventExcludeAgent;
use App\Models\EventInviteRecord;
use App\Models\EventProcess;
use App\Models\Member;
use App\Models\Order;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\VendorHourRecord;
use App\Services\LivechatBot\PromotionV1Builder;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Livechat Bot V1 Promotion Builder 測試
 *
 * php artisan test tests/Unit/Services/LivechatBot/PromotionV1BuilderTest.php
 */
class PromotionV1BuilderTest extends TestCase
{
    private ?Agent $agent = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agent = $this->createAgentAndDomain();
    }

    /**
     * 建立 buildConditioned 通過資格濾鏡所需的最小資料：
     * Event(agent_id = 測試代理) + Prize(active + 未過期) + EventDetail。
     *
     * 傳入的 attributes 可覆寫對應預設（例如測停用情境傳 ['status' => 0]）。
     */
    private function createEligibleDetail(array $eventAttrs = [], array $detailAttrs = []): EventDetail
    {
        $event = Event::factory()->create(array_merge([
            'agent_id' => $this->agent->id,
            'status' => 1,
        ], $eventAttrs));

        Prize::create([
            'event_id' => $event->id,
            'type_id' => 0,
            'name' => 'Test Prize',
            'background' => '',
            'number' => 100,
            'flow_limit_multiple' => 1,
            'max_point' => 1000,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
            'status' => 1,
            'admin' => 0,
        ]);

        return EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'status' => 1,
        ], $detailAttrs));
    }

    /**
     * 在 detail 對應的 prize 底下建立多筆分級 prize_content（achieve_people 門檻）。
     *
     * 邀請類活動的 level 為「成就類型」而非門檻排名：level=0 純邀請（action 15）、
     * level=1 邀請+儲值（action 16）。純邀請門檻計算只取 level=0，故預設建 level=0。
     *
     * @param  array<int, int>  $tiers  各級 achieve_people 門檻
     */
    private function addPrizeTiers(EventDetail $detail, array $tiers, int $status = 1, int $level = 0): void
    {
        $prize = Prize::query()->where('event_id', $detail->event_id)->firstOrFail();

        foreach ($tiers as $people) {
            PrizeContent::create([
                'prize_id' => $prize->id,
                'name' => 'Tier ' . $people,
                'number' => 1,
                'level' => $level,
                'percentage' => 100,
                'achieve_people' => $people,
                'status' => $status,
                'admin' => 0,
            ]);
        }
    }

    /**
     * 建立一筆 prize（預設 active 未過期），可覆寫 attributes（如 status=0、expire_date 過期）。
     * 用於同一 event 需要多筆 prize 的情境（驗證 eager-load 只取 active prize）。
     */
    private function createPrize(int $eventId, array $attrs = []): Prize
    {
        return Prize::create(array_merge([
            'event_id' => $eventId,
            'type_id' => 0,
            'name' => 'Prize',
            'background' => '',
            'number' => 100,
            'flow_limit_multiple' => 1,
            'max_point' => 1000,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
            'status' => 1,
            'admin' => 0,
        ], $attrs));
    }

    /**
     * 在指定 prize 下建立分級 prize_content（achieve_people 門檻）。
     *
     * @param  array<int, int>  $tiers
     */
    private function addTiersToPrize(Prize $prize, array $tiers, int $status = 1, int $level = 0): void
    {
        foreach ($tiers as $people) {
            PrizeContent::create([
                'prize_id' => $prize->id,
                'name' => 'Tier ' . $people,
                'number' => 1,
                'level' => $level,
                'percentage' => 100,
                'achieve_people' => $people,
                'status' => $status,
                'admin' => 0,
            ]);
        }
    }

    /**
     * 為 inviter 建立 $count 筆「有效」（四個 *_duplicate 皆 0）邀請紀錄。
     */
    private function createValidInvites(EventDetail $detail, Member $member, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => 6000 + $i,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }
    }

    public function test_buildClaimable_returns_empty_when_no_unaward_records(): void
    {
        $member = $this->createMember($this->agent);

        $result = (new PromotionV1Builder)->buildClaimable($member);

        $this->assertSame([], $result);
    }

    public function test_buildClaimable_returns_unaward_prize_member_records(): void
    {
        $member = $this->createMember($this->agent);
        $event = Event::factory()->create(['title' => 'Daily Login Bonus']);
        $detail = EventDetail::factory()->create(['event_id' => $event->id, 'action_id' => EventActionIdEnum::LOGIN->value]);

        $prize = PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $detail->id,
            'lottery_point' => 25.5,
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::UNAWARD->value,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildClaimable($member);

        $this->assertCount(1, $result);
        $this->assertSame(LivechatBotPromotionSourceEnum::V1->value, $result[0]['promotionSource']);
        $this->assertSame($prize->id, $result[0]['applyId']);
        $this->assertSame('Daily Login Bonus', $result[0]['promotionName']);
        $this->assertSame('25.5000', $result[0]['rewardAmount']);
        $this->assertIsArray($result[0]['message']);
    }

    public function test_buildClaimable_excludes_awarded_records(): void
    {
        $member = $this->createMember($this->agent);
        $event = Event::factory()->create();
        $detail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::DEPOSIT->value,
        ]);

        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $detail->id,
            'lottery_point' => 10,
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::AWARD->value,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildClaimable($member);

        $this->assertSame([], $result);
    }

    public function test_buildClaimable_excludes_expired_records(): void
    {
        $member = $this->createMember($this->agent);
        $event = Event::factory()->create();
        $detail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::DEPOSIT->value,
        ]);

        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $detail->id,
            'lottery_point' => 10,
            'expired_time' => now()->subDay()->toDateTimeString(),
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::UNAWARD->value,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildClaimable($member);

        $this->assertSame([], $result);
    }

    public function test_buildConditioned_returns_empty_when_no_eligible_details(): void
    {
        $member = $this->createMember($this->agent);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    public function test_buildConditioned_returns_progress_for_unmet_event_processes(): void
    {
        $member = $this->createMember($this->agent);
        // 用 INVITE_PLAYER_OFFERS (31)：是 PHP 層仍主動寫 event_processes 的 action，
        // 用來驗證共用的「進度未達標時帶出進度資料」行為。
        $detail = $this->createEligibleDetail(
            eventAttrs: ['title' => 'Daily Bet Quest'],
            detailAttrs: ['action_id' => EventActionIdEnum::INVITE_PLAYER_OFFERS->value, 'point' => 5],
        );

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $entry = $result[0];

        $this->assertSame(LivechatBotPromotionSourceEnum::V1->value, $entry['promotionSource']);
        $this->assertSame('Daily Bet Quest', $entry['promotionName']);
        $this->assertCount(1, $entry['nonQualify']);
        $this->assertSame('INVITE_PLAYER_OFFERS', $entry['progress']['actionType']);
        $this->assertSame(EventActionIdEnum::INVITE_PLAYER_OFFERS->value, $entry['progress']['actionId']);
        $this->assertSame(5, $entry['progress']['requiredPoint']);
        $this->assertSame(3, $entry['progress']['currentPoint']);
        $this->assertSame(2, $entry['progress']['remaining']);
    }

    public function test_buildConditioned_excludes_satisfied_processes(): void
    {
        $member = $this->createMember($this->agent);
        // 用 INVITE_PLAYER_OFFERS (31)（仍走 EventProcess fallback）測共用的「達標即排除」邏輯。
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::INVITE_PLAYER_OFFERS->value, 'point' => 5],
        );

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 5, // 已達標
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 「當期」定義：event_detail.end_time >= now。
     * 過期的 detail 不應出現在 conditioned 列表。
     */
    public function test_buildConditioned_excludes_expired_detail(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            eventAttrs: ['title' => 'Expired Quest'],
            detailAttrs: [
                'action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value,
                'point' => 5,
                'start_time' => now()->subDays(30),
                'end_time' => now()->subDays(1), // 已結束
            ],
        );

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 「當期」定義：event_detail.start_time <= now。
     * 尚未開始（start_time 在未來）的 detail 不應出現於 conditioned。
     * 與 excludes_expired_detail（測 end_time 過去）互補，守住 start_time <= now 條件。
     */
    public function test_buildConditioned_excludes_not_yet_started_detail(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            eventAttrs: ['title' => 'Future Quest'],
            detailAttrs: [
                'action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value,
                'point' => 5,
                'start_time' => now()->addDays(1), // 尚未開始
                'end_time' => now()->addDays(30),
            ],
        );

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 「當期」定義：event_detail.status = 1。
     * status=0（停用）的 detail 不應出現。
     */
    public function test_buildConditioned_excludes_inactive_detail(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            eventAttrs: ['title' => 'Disabled Detail'],
            detailAttrs: [
                'action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value,
                'point' => 5,
                'status' => 0, // 停用
            ],
        );

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 「當期」定義：event.status = 1。
     * event 停用時，即使 detail 仍 active，也不應出現。
     */
    public function test_buildConditioned_excludes_when_parent_event_inactive(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            eventAttrs: ['title' => 'Parent Disabled', 'status' => 0],
            detailAttrs: [
                'action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value,
                'point' => 5,
            ],
        );

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 未知 action_id（不在 EventActionIdEnum 中）→ 不顯示為 conditioned。
     * 即使存在對應 EventProcess，重構後不再讓未明示行為的 fallback 漏出來。
     */
    public function test_buildConditioned_excludes_unknown_action_even_with_event_process(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            eventAttrs: ['title' => 'Custom Promo'],
            detailAttrs: ['action_id' => 999, 'point' => 10],
        );

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 1,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 新架構從 EventDetail 起手，必須以 agent_id 隔離跨租戶活動。
     * 不在 member 代理線下的 event 即使 status=1 也不應出現。
     */
    public function test_buildConditioned_excludes_events_outside_member_agent_hierarchy(): void
    {
        $member = $this->createMember($this->agent);

        // 另建一個獨立代理（不在 member 的 hierarchy_array 內）
        $this->domain = bin2hex(random_bytes(8)) . '.test.local';
        $otherAgent = $this->createAgentAndDomain();
        $event = Event::factory()->create(['agent_id' => $otherAgent->id, 'status' => 1]);
        Prize::create([
            'event_id' => $event->id,
            'type_id' => 0,
            'name' => 'Other Agent Prize',
            'background' => '',
            'number' => 100,
            'flow_limit_multiple' => 1,
            'max_point' => 1000,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
            'status' => 1,
            'admin' => 0,
        ]);
        $detail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value,
            'point' => 5,
            'status' => 1,
        ]);

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * Prize.status=0（停用）的活動不應顯示為 conditioned —
     * 沒獎品的活動沒有導購意義。
     */
    public function test_buildConditioned_excludes_event_with_inactive_prize(): void
    {
        $member = $this->createMember($this->agent);
        $event = Event::factory()->create(['agent_id' => $this->agent->id, 'status' => 1]);
        Prize::create([
            'event_id' => $event->id,
            'type_id' => 0,
            'name' => 'Disabled Prize',
            'background' => '',
            'number' => 100,
            'flow_limit_multiple' => 1,
            'max_point' => 1000,
            'withdrawal_cancel_reward' => 0,
            'cancel_other_reward' => 0,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
            'status' => 0, // 停用
            'admin' => 0,
        ]);
        $detail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value,
            'point' => 5,
            'status' => 1,
        ]);

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * Prize 已過期（expire_date <= now）的活動不應顯示。
     */
    public function test_buildConditioned_excludes_event_with_expired_prize(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value, 'point' => 5],
        );
        // 覆寫 prize 過期時間
        Prize::query()->where('event_id', $detail->event_id)->update([
            'expire_date' => now()->subDay()->toDateTimeString(),
        ]);

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * event_exclude_agents 命中 member 代理線 → 活動被排除。
     */
    public function test_buildConditioned_excludes_when_member_agent_in_exclude_list(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value, 'point' => 5],
        );

        EventExcludeAgent::create([
            'event_id' => $detail->event_id,
            'agent_id' => $this->agent->id,
        ]);

        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 3,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    // ════════════════════════════════════════════════════════════
    //  Deposit resolver (action 1, 4, 51-56) + DepositCycle (57, 58)
    // ════════════════════════════════════════════════════════════

    /**
     * 累計儲值（action 52）：活動視窗內所有完成的 orders.point 加總為進度。
     */
    public function test_deposit_cumulative_sums_completed_orders_in_window(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::DEPOSIT_AUTO->value,
                'point' => 100,
            ],
        );

        $start = Carbon::parse($detail->start_time)->addHour();
        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 30,
            'pay_time' => $start,
        ]);
        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 50,
            'pay_time' => $start->copy()->addHour(),
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(80, $result[0]['progress']['currentPoint']);
        $this->assertSame(100, $result[0]['progress']['requiredPoint']);
        $this->assertSame(20, $result[0]['progress']['remaining']);
    }

    /**
     * 累計儲值：活動視窗外的訂單不計入。
     */
    public function test_deposit_cumulative_excludes_orders_outside_window(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::DEPOSIT_AUTO->value,
                'point' => 100,
                'start_time' => now()->subDays(2),
                'end_time' => now()->addDays(2),
            ],
        );

        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 200,
            'pay_time' => now()->subDays(10), // 視窗外
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
    }

    /**
     * 累計儲值：detail.order_type_id 限定時，只計符合的訂單。
     */
    public function test_deposit_cumulative_respects_order_type_filter(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::DEPOSIT_AUTO->value,
                'point' => 100,
                'order_type_id' => 7, // 指定 type
            ],
        );

        $start = Carbon::parse($detail->start_time)->addHour();
        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 7,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 40,
            'pay_time' => $start,
        ]);
        // 不同 type，不應計入
        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 99,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 60,
            'pay_time' => $start,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(40, $result[0]['progress']['currentPoint']);
    }

    /**
     * 累計儲值：未完成的訂單（status != DEPOSIT_FINISHED）不計入。
     */
    public function test_deposit_cumulative_excludes_unfinished_orders(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::DEPOSIT_AUTO->value,
                'point' => 100,
            ],
        );

        $start = Carbon::parse($detail->start_time)->addHour();
        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::CREATED->value, // 未完成
            'point' => 80,
            'pay_time' => $start,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
    }

    /**
     * 首儲（51 FIRST_DEPOSIT_AUTO）：會員從未儲值 → 進度為 0。
     */
    public function test_first_deposit_member_never_deposited_returns_zero_progress(): void
    {
        $member = $this->createMember($this->agent);
        // 確保 first_deposit 為 null
        $member->update(['first_deposit' => null]);

        $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::FIRST_DEPOSIT_AUTO->value,
                'point' => 100,
            ],
        );

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(100, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 首儲：會員的 first_deposit 早於活動開始 → 不符合資格（不顯示）。
     */
    public function test_first_deposit_member_first_deposited_before_event_returns_null(): void
    {
        $member = $this->createMember($this->agent);
        $member->update(['first_deposit' => now()->subDays(30)->toDateTimeString()]);

        $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::FIRST_DEPOSIT_AUTO->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 每日領取（55 PICK_UP_ONCE_A_DAY）：當日已領（PrizeMember 今日已存在）→ 不顯示。
     */
    public function test_daily_deposit_already_claimed_today_returns_null(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::PICK_UP_ONCE_A_DAY->value,
                'point' => 100,
            ],
        );

        PrizeMember::create([
            'member_id' => $member->id,
            'prize_id' => 0,
            'event_detail_id' => $detail->id,
            'lottery_point' => 10,
            'ip' => '0.0.0.0',
            'status' => PrizeMemberStatusEnum::AWARD->value, // 已領
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * 每日領取：只計今日（UTC+8）視窗內的儲值，昨日不計入。
     */
    public function test_daily_deposit_only_counts_today_orders(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::PICK_UP_ONCE_A_DAY_AUTO->value,
                'point' => 100,
                'start_time' => now()->subDays(7),
                'end_time' => now()->addDays(7),
            ],
        );

        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 50,
            'pay_time' => now(), // 今天
        ]);
        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 200,
            'pay_time' => now()->subDays(2), // 兩天前
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(50, $result[0]['progress']['currentPoint']);
    }

    /**
     * 週期儲值（57）：目前以活動視窗當作週期（簡化），與累計儲值同行為。
     * TODO 完整週期解析待後續 commit/版本補完。
     */
    public function test_deposit_cycle_uses_event_window_as_simplified_cycle(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::DEPOSIT_CYCLE_TIME_AUTO->value,
                'point' => 500,
            ],
        );

        $start = Carbon::parse($detail->start_time)->addHour();
        Order::factory()->create([
            'member_id' => $member->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'point' => 200,
            'pay_time' => $start,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(200, $result[0]['progress']['currentPoint']);
        $this->assertSame(500, $result[0]['progress']['requiredPoint']);
    }

    // ════════════════════════════════════════════════════════════
    //  Bet / Win / Rebate / BET_ONCE_A_DAY resolver
    // ════════════════════════════════════════════════════════════

    /**
     * BET (2)：vendor_hour_records.bet 加總為進度。
     */
    public function test_bet_sums_vendor_hour_records_bet_in_window(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::BET->value,
                'point' => 10000,
            ],
        );

        $hour = Carbon::parse($detail->start_time)->addHour()->format('Y-m-d H:00:00');
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => $hour,
            'bet' => 3000,
            'win' => 1000,
            'win_lose' => -2000,
        ]);
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => $hour,
            'bet' => 4500,
            'win' => 2000,
            'win_lose' => -2500,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(7500, $result[0]['progress']['currentPoint']);
        $this->assertSame(10000, $result[0]['progress']['requiredPoint']);
    }

    /**
     * WIN (3)：vendor_hour_records 中 win_lose > 0 的加總為進度（敗會被排除）。
     */
    public function test_win_sums_only_positive_win_lose(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::WIN->value,
                'point' => 5000,
            ],
        );

        $hour = Carbon::parse($detail->start_time)->addHour()->format('Y-m-d H:00:00');
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => $hour,
            'bet' => 1000,
            'win' => 3000,
            'win_lose' => 2000, // 贏
        ]);
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => $hour,
            'bet' => 5000,
            'win' => 1000,
            'win_lose' => -4000, // 輸 → 不計入 WIN
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(2000, $result[0]['progress']['currentPoint']);
    }

    /**
     * SLOT_LOSE_REBATE (9)：win_lose < 0 取絕對值加總。
     */
    public function test_rebate_sums_absolute_negative_win_lose(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::SLOT_LOSE_REBATE->value,
                'point' => 5000,
            ],
        );

        $hour = Carbon::parse($detail->start_time)->addHour()->format('Y-m-d H:00:00');
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => $hour,
            'bet' => 8000,
            'win' => 5000,
            'win_lose' => -3000, // 輸
        ]);
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => $hour,
            'bet' => 1000,
            'win' => 3000,
            'win_lose' => 2000, // 贏 → REBATE 不計
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(3000, $result[0]['progress']['currentPoint']);
    }

    /**
     * Bet 系列：vendor_id 限定時，只計符合該 vendor 的紀錄。
     */
    public function test_bet_respects_vendor_id_filter(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::BET->value,
                'point' => 10000,
                'vendor_id' => 7,
            ],
        );

        $hour = Carbon::parse($detail->start_time)->addHour()->format('Y-m-d H:00:00');
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'vendor_id' => 7,
            'date_hour' => $hour,
            'bet' => 3000,
        ]);
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'vendor_id' => 99, // 不同 vendor → 不計入
            'date_hour' => $hour,
            'bet' => 8000,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(3000, $result[0]['progress']['currentPoint']);
    }

    /**
     * BET_ONCE_A_DAY (14)：今日無下注 → 進度 0，required 1，出現於 conditioned。
     */
    public function test_bet_once_a_day_returns_zero_when_no_bet_today(): void
    {
        $member = $this->createMember($this->agent);
        $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value, 'point' => 1],
        );

        // 兩天前有下注，今日無 → 不算
        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => now()->subDays(2)->format('Y-m-d H:00:00'),
            'bet' => 100,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(1, $result[0]['progress']['requiredPoint']);
    }

    /**
     * BET_ONCE_A_DAY (14)：今日已下注 → 已達標，被排除。
     */
    public function test_bet_once_a_day_excluded_when_today_has_bet(): void
    {
        $member = $this->createMember($this->agent);
        $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::BET_ONCE_A_DAY->value, 'point' => 1],
        );

        VendorHourRecord::factory()->create([
            'member_id' => $member->id,
            'date_hour' => now()->format('Y-m-d H:00:00'),
            'bet' => 100,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    // ════════════════════════════════════════════════════════════
    //  Invite resolver (action 15, 16, 60)
    // ════════════════════════════════════════════════════════════

    /**
     * NEW_INVITE_PLAYER_OFFERS (15)：計 event_invite_records 中該 inviter
     * 未被任一 *_duplicate flag 命中的紀錄。
     */
    public function test_invite_count_excludes_duplicate_flagged_records(): void
    {
        $member = $this->createMember($this->agent);
        // detail.point 故意設成與分級門檻不同的值，證明門檻來自 prize_contents.achieve_people 而非 point。
        // 四個 block 旗標皆設 1，duplicate 過濾才會動態套用（對齊舊系統 getNewInvitePrizeCnt）。
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 999,
                'ip_block' => 1,
                'phone_block' => 1,
                'account_block' => 1,
                'tgid_block' => 1,
            ],
        );
        // 分級 {5, 20}：3 筆有效邀請 → 尚未達成的最低級為 5。
        $this->addPrizeTiers($detail, [5, 20]);

        // 三筆有效邀請
        for ($i = 0; $i < 3; $i++) {
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => 9000 + $i,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }
        // 一筆 IP 重複 → 不計
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 9100,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 1,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);
        // 一筆是別人邀的，跟 member 無關
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 9200,
            'invite_member_id' => 8888,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(3, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
        $this->assertSame(2, $result[0]['progress']['remaining']);
    }

    /**
     * TPOWER_INVITE_PLAYER (60)：共用 NEW_INVITE_PLAYER_OFFERS 的計數邏輯，
     * 門檻同樣來自 prize_contents.achieve_people。
     */
    public function test_tpower_invite_uses_same_count_logic(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::TPOWER_INVITE_PLAYER->value, 'point' => 999],
        );
        $this->addPrizeTiers($detail, [3, 10]);

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 9500,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(3, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 規則 A — 分級 {3, 20}、已邀請 0 → 顯示尚未達成的最低級 3（0/3）。
     */
    public function test_invite_tiered_threshold_shows_lowest_unmet_tier_when_zero_invited(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value, 'point' => 999],
        );
        $this->addPrizeTiers($detail, [3, 20]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(3, $result[0]['progress']['requiredPoint']);
        $this->assertSame(3, $result[0]['progress']['remaining']);
    }

    /**
     * 規則 A — 分級 {3, 20}、已邀請 5（已過第一級）→ 顯示下一個未達成級 20（5/20）。
     */
    public function test_invite_tiered_threshold_shows_next_tier_after_passing_first(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value, 'point' => 999],
        );
        $this->addPrizeTiers($detail, [3, 20]);
        $this->createValidInvites($detail, $member, 5);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(5, $result[0]['progress']['currentPoint']);
        $this->assertSame(20, $result[0]['progress']['requiredPoint']);
        $this->assertSame(15, $result[0]['progress']['remaining']);
    }

    /**
     * 規則 A — 已邀請數達最高級（20，分級 {3, 20}）→ 無未達成級別，
     * 回傳最高級後被 current >= required 過濾，不出現在 conditioned。
     */
    public function test_invite_tiered_threshold_excluded_when_top_tier_reached(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value, 'point' => 999],
        );
        $this->addPrizeTiers($detail, [3, 20]);
        $this->createValidInvites($detail, $member, 20);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * prize_contents 無 achieve_people>0 的有效級別 → 退回使用 event_details.point。
     */
    public function test_invite_falls_back_to_point_when_no_valid_tier(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value, 'point' => 7],
        );
        // 有效（status=1）但 achieve_people=0；以及一筆 achieve_people>0 卻停用（status=0）
        $this->addPrizeTiers($detail, [0]);
        $this->addPrizeTiers($detail, [20], status: 0);
        $this->createValidInvites($detail, $member, 2);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['progress']['currentPoint']);
        $this->assertSame(7, $result[0]['progress']['requiredPoint']);
        $this->assertSame(5, $result[0]['progress']['remaining']);
    }

    /**
     * action 15（純邀請）門檻只取 level=0 的 prize_content。
     * prize 同時有 level=0 純邀請級距 {5,20} 與一筆 level=1（邀請+儲值）achieve_people=2，
     * 已邀請 0 → 門檻應為 5（level=1 的 2 屬另一成就維度，不得被誤用為純邀請門檻）。
     */
    public function test_invite_threshold_ignores_level_one_achieve_people(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value, 'point' => 999],
        );
        // level=0 純邀請級距 {5,20}
        $this->addPrizeTiers($detail, [5, 20], level: 0);
        // level=1 邀請+儲值，achieve_people=2 —— 不應被當成純邀請門檻
        $this->addPrizeTiers($detail, [2], level: 1);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
        $this->assertSame(5, $result[0]['progress']['remaining']);
    }

    /**
     * 只有 level-1（無任何 level-0）的 action 15 → 主 progress 改由「邀請並充值」續儲維度承載，
     * 不再以 level-0 point fallback 假裝純邀請。被邀請者首單達標數即 currentPoint，門檻取
     * level-1 級距（規則 A），progress 帶 depositThreshold、且不掛 additionalProgress。
     *
     * （取代舊 test_invite_falls_back_to_point_when_only_level_one_tiers：該測試斷言的
     * 「只有 level-1 → 純邀請 point fallback」正是本次方案甲修正掉的錯誤行為。）
     */
    public function test_invite_only_level_one_promotes_deposit_dimension_to_primary(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 8, // 此維度語意為「最低充值點數門檻」
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );
        // 只有 level-1 級距 {2,6}、無任何 level-0
        $this->addPrizeTiers($detail, [2, 6], level: 1);

        // 2 名被邀請者各有窗內 status=3 首單、point >= 門檻(8) → 續儲達標數 = 2
        $u = fn () => uniqid('inv_');
        foreach (range(1, 2) as $i) {
            $invitee = Member::factory()->create([
                'parent' => $this->agent->id,
                'username' => $u(),
                'nickname' => $u(),
            ]);
            Order::factory()->create([
                'member_id' => $invitee->id,
                'admin' => 0,
                'order_type_id' => 0,
                'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
                'amount' => 50,
                'point' => 50, // >= 門檻 8
            ]);
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitee->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        // 主 progress = 續儲維度（非純邀請）
        $this->assertSame('INVITE_PLAYER_DEPOSIT', $result[0]['progress']['actionType']);
        $this->assertSame(EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value, $result[0]['progress']['actionId']);
        $this->assertSame(2, $result[0]['progress']['currentPoint']);
        $this->assertSame(6, $result[0]['progress']['requiredPoint']); // 規則 A：current=2 → 下一未達成級 6
        $this->assertSame(4, $result[0]['progress']['remaining']);
        $this->assertSame(8, $result[0]['progress']['depositThreshold']);
        // 只有 level-1 → 不掛 additionalProgress
        $this->assertArrayNotHasKey('additionalProgress', $result[0]);
    }

    /**
     * 動態 block 旗標 — ip_block=0：一筆 ip_duplicate=1 的邀請仍會被計入
     * （旗標關閉時不套用對應 duplicate 過濾，對齊舊系統 getNewInvitePrizeCnt）。
     */
    public function test_invite_count_includes_ip_duplicate_when_ip_block_disabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'ip_block' => 0, // 關閉 → ip_duplicate 不過濾
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7000,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 1, // 重複 IP，但 ip_block=0 故仍計入
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 動態 block 旗標 — ip_block=1：同一筆 ip_duplicate=1 的邀請被排除（對照組）。
     */
    public function test_invite_count_excludes_ip_duplicate_when_ip_block_enabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'ip_block' => 1, // 開啟 → 排除 ip_duplicate=1
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7000,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 1,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * realname_block=1：要求被邀請人（member_id）realname_validate=1，
     * realname_validate=0 的邀請不計入、=1 才計入。
     */
    public function test_invite_count_requires_invitee_realname_when_realname_block_enabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'realname_block' => 1, // 要求被邀請人實名
            ],
        );

        // 被邀請人 A：已實名 → 計入（uniqid 避免 username 唯一鍵衝突）
        $u = fn () => uniqid('inv_');
        $invitedValidated = $this->createMember($this->agent, [
            'username' => $u(), 'nickname' => $u(), 'realname_validate' => 1,
        ]);
        // 被邀請人 B：未實名 → 不計入
        $invitedUnvalidated = $this->createMember($this->agent, [
            'username' => $u(), 'nickname' => $u(), 'realname_validate' => 0,
        ]);

        foreach ([$invitedValidated, $invitedUnvalidated] as $invitee) {
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitee->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']); // 只有已實名的 A
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 時間窗：created_at 落在活動 end_time 之後的邀請不計入。
     */
    public function test_invite_count_excludes_records_after_event_window(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
            ],
        );

        // 窗內邀請（created_at 自動為 now，落在 [start_time, end_time]）→ 計入
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7100,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        // 窗後邀請：created_at 為 guarded（無法 mass-assign），建立後手動覆寫至 end_time 之後
        $lateRecord = EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7101,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);
        $lateRecord->created_at = Carbon::parse($detail->end_time)->addDay();
        $lateRecord->save();

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']); // 只算窗內那筆
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 時間窗上界為「嚴格小於」：created_at 剛好等於活動 end_time 的邀請**不計入**。
     * 鎖死 resolveInviteCount 的 `->where('created_at', '<', end_time)`：
     * 若被改回 `<=`，邊界那筆會被多算，currentPoint 變 2，本測試轉紅。
     */
    public function test_invite_count_excludes_record_exactly_at_end_time_boundary(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
            ],
        );

        // 窗內邀請（created_at 自動為 now，落在 [start_time, end_time)）→ 計入
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7200,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        // 邊界邀請：created_at 為 guarded，建立後手動覆寫至**剛好等於** end_time
        $boundaryRecord = EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7201,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);
        $boundaryRecord->created_at = Carbon::parse($detail->end_time);
        $boundaryRecord->save();

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']); // 不含 created_at == end_time 那筆
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    // ════════════════════════════════════════════════════════════
    //  Ticket resolver (action 5, 7) + 不適用 conditioned 的 action (10, 11, 12)
    // ════════════════════════════════════════════════════════════

    /**
     * LOTTERY (5)：未開獎的 lucky_draws 上的會員持票數為當前進度。
     */
    public function test_lottery_counts_tickets_on_undrawn_draws(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::LOTTERY->value, 'point' => 5],
        );

        // 未開獎 draw
        $undrawnId = DB::table('lucky_draws')->insertGetId([
            'event_id' => $detail->event_id,
            'event_detail_id' => $detail->id,
            'status' => 0,
        ]);
        // 已派完獎 draw（status=1） → 該 draw 上的票應排除
        $finishedId = DB::table('lucky_draws')->insertGetId([
            'event_id' => $detail->event_id,
            'event_detail_id' => $detail->id,
            'status' => 1,
        ]);

        for ($i = 0; $i < 2; $i++) {
            DB::table('lucky_draw_tickets')->insert([
                'lucky_draw_id' => $undrawnId,
                'member_id' => $member->id,
                'ticket_number' => 'T' . $i,
            ]);
        }
        // 已派完的票 → 不計入
        DB::table('lucky_draw_tickets')->insert([
            'lucky_draw_id' => $finishedId,
            'member_id' => $member->id,
            'ticket_number' => 'TX',
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(2, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * W_JACKPOT (7)：同 LOTTERY，改查 w_jackpot_tickets。
     */
    public function test_w_jackpot_counts_tickets_on_undrawn(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::W_JACKPOT->value, 'point' => 3],
        );

        $drawId = DB::table('w_jackpots')->insertGetId([
            'event_id' => $detail->event_id,
            'event_detail_id' => $detail->id,
            'status' => 0,
        ]);
        DB::table('w_jackpot_tickets')->insert([
            'w_jackpot_id' => $drawId,
            'member_id' => $member->id,
            'ticket_number' => 'WJ1',
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
    }

    /**
     * SLOT_MACHINE_COMPETITION (8)：未派發競賽上會員的累計投注額為進度。
     */
    public function test_slot_competition_sums_member_bet_amount_on_active_round(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::SLOT_MACHINE_COMPETITION->value, 'point' => 5000],
        );

        $activeId = DB::table('event_slot_competitions')->insertGetId([
            'event_id' => $detail->event_id,
            'event_detail_id' => $detail->id,
            'status' => 0, // 未派獎
        ]);
        $finishedId = DB::table('event_slot_competitions')->insertGetId([
            'event_id' => $detail->event_id,
            'event_detail_id' => $detail->id,
            'status' => 1, // 已派獎
        ]);

        DB::table('event_slot_competition_records')->insert([
            'event_slot_competition_id' => $activeId,
            'member_id' => $member->id,
            'bet_amount' => 1200,
            'last_bet_time' => now(),
        ]);
        // 已派獎 round 的紀錄不應計入
        DB::table('event_slot_competition_records')->insert([
            'event_slot_competition_id' => $finishedId,
            'member_id' => $member->id,
            'bet_amount' => 9999,
            'last_bet_time' => now(),
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1200, $result[0]['progress']['currentPoint']);
        $this->assertSame(5000, $result[0]['progress']['requiredPoint']);
    }

    /**
     * REFRESH_RANKING (13)：未派發排行榜上 is_calc=1 的累計投注額為進度。
     * is_calc=0（電話/IP 重複等）的紀錄應排除。
     */
    public function test_refresh_ranking_only_sums_is_calc_records(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::REFRESH_RANKING->value, 'point' => 3000],
        );

        // 兩個期數 ranking（unique 索引 = ranking_id + member_id，須用不同 ranking_id）
        $rankingA = DB::table('event_refresh_rankings')->insertGetId([
            'event_id' => $detail->event_id,
            'event_detail_id' => $detail->id,
            'no' => 1,
            'status' => 0, // 未派發
        ]);
        $rankingB = DB::table('event_refresh_rankings')->insertGetId([
            'event_id' => $detail->event_id,
            'event_detail_id' => $detail->id,
            'no' => 2,
            'status' => 0,
        ]);

        DB::table('event_refresh_ranking_records')->insert([
            'event_refresh_ranking_id' => $rankingA,
            'member_id' => $member->id,
            'bet_amount' => 800,
            'is_calc' => 1, // 計算
        ]);
        DB::table('event_refresh_ranking_records')->insert([
            'event_refresh_ranking_id' => $rankingB,
            'member_id' => $member->id,
            'bet_amount' => 1500,
            'is_calc' => 0, // 不計算（電話/IP 重複）
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(800, $result[0]['progress']['currentPoint']);
        $this->assertSame(3000, $result[0]['progress']['requiredPoint']);
    }

    /**
     * REG_BONUS (11)：boolean 性質，不顯示為 conditioned（resolver 直接回 null）。
     * 即使建有對應 EventProcess，也不應出現在 conditioned 列表。
     */
    public function test_reg_bonus_never_appears_in_conditioned(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::REG_BONUS->value, 'point' => 1],
        );

        // 即使建了 EventProcess，REG_BONUS 因 resolver 回 null 不會走 EventProcess fallback
        EventProcess::create([
            'detail_id' => $detail->id,
            'member_id' => $member->id,
            'point' => 0,
            'status' => 0,
            'admin' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * INVITE_PLAYER_DEPOSIT (16)：邀請的會員需要在活動視窗內完成首儲才計入。
     */
    public function test_invite_deposit_counts_invited_members_with_first_deposit_in_window(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value,
                'point' => 3,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );

        // 邀請了三個會員（uniqid 避免 username 唯一鍵衝突）
        $u = fn () => uniqid('inv_');
        $invited1 = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(), 'nickname' => $u(),
            'first_deposit' => now()->subDay()->toDateTimeString(),
        ]);
        $invited2 = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(), 'nickname' => $u(),
            'first_deposit' => now()->subDays(10)->toDateTimeString(), // 視窗外
        ]);
        $invited3 = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(), 'nickname' => $u(),
            'first_deposit' => null, // 沒首儲
        ]);

        foreach ([$invited1, $invited2, $invited3] as $invitedMember) {
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitedMember->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']); // 只有 invited1 算
        $this->assertSame(3, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 門檻嚴格大於守護：count 剛好等於中間級門檻（tiers {3,20}、有效邀請=3）→
     * 取下一個「嚴格大於 count」的級別 20，仍出現在 conditioned（current=3 < required=20）。
     * 鎖死 resolveInviteRequired 用 `>`（非 `>=`）：若被誤改成 `>=`，required 會變 3 而被當作已達標排除。
     */
    public function test_invite_threshold_uses_strict_greater_than_at_exact_tier(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: ['action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value, 'point' => 999],
        );
        $this->addPrizeTiers($detail, [3, 20]);
        $this->createValidInvites($detail, $member, 3);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(3, $result[0]['progress']['currentPoint']);
        $this->assertSame(20, $result[0]['progress']['requiredPoint']);
        $this->assertSame(17, $result[0]['progress']['remaining']);
    }

    /**
     * phone_block=1：phone_duplicate=1 的邀請被排除，乾淨那筆仍計入 → current=1。
     */
    public function test_invite_count_excludes_phone_duplicate_when_phone_block_enabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'phone_block' => 1,
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7300,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 1, // 電話重複 → phone_block=1 故排除
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7301,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * phone_block=0：同一筆 phone_duplicate=1 仍計入（旗標關閉不套用過濾）→ current=1。
     */
    public function test_invite_count_includes_phone_duplicate_when_phone_block_disabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'phone_block' => 0,
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7310,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 1,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * account_block=1：realname_duplicate=1（真實姓名重複）的邀請被排除，乾淨那筆計入 → current=1。
     */
    public function test_invite_count_excludes_realname_duplicate_when_account_block_enabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'account_block' => 1,
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7400,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 1, // 真實姓名重複 → account_block=1 故排除
            'telegram_id_duplicate' => 0,
        ]);
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7401,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * account_block=0：同一筆 realname_duplicate=1 仍計入 → current=1。
     */
    public function test_invite_count_includes_realname_duplicate_when_account_block_disabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'account_block' => 0,
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7410,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 1,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * tgid_block=1：telegram_id_duplicate=1 的邀請被排除，乾淨那筆計入 → current=1。
     */
    public function test_invite_count_excludes_telegram_duplicate_when_tgid_block_enabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'tgid_block' => 1,
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7500,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 1, // telegram_id 重複 → tgid_block=1 故排除
        ]);
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7501,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * tgid_block=0：同一筆 telegram_id_duplicate=1 仍計入 → current=1。
     */
    public function test_invite_count_includes_telegram_duplicate_when_tgid_block_disabled(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'tgid_block' => 0,
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7510,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 1,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 時間窗下界：created_at 早於活動 start_time 的邀請不計入（對齊舊系統 start 閉區間 >=）。
     */
    public function test_invite_count_excludes_records_before_event_window(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 5,
                'start_time' => now()->subDays(2),
                'end_time' => now()->addDays(2),
            ],
        );

        // 窗內邀請（created_at 自動為 now）→ 計入
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7600,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        // 活動開始前一天的邀請：created_at 為 guarded，建立後手動覆寫至 start_time 之前
        $earlyRecord = EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => 7601,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);
        $earlyRecord->created_at = Carbon::parse($detail->start_time)->subDay();
        $earlyRecord->save();

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['progress']['currentPoint']); // 只算窗內那筆
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
    }

    /**
     * 多 prize 錯選守護（修正 1）：同一 event 同時有「停用 prize（較早 id，achieve_people=99）」
     * 與「active prize（achieve_people=3）」。eager-load 必須只取 active prize，
     * 門檻才會是 3 而非 99（停用 prize 不得污染門檻計算）。
     */
    public function test_invite_threshold_uses_active_prize_when_multiple_prizes_exist(): void
    {
        $member = $this->createMember($this->agent);
        $event = Event::factory()->create(['agent_id' => $this->agent->id, 'status' => 1]);

        // 停用 prize（較早 id）：achieve_people 級距 99
        $disabledPrize = $this->createPrize($event->id, ['name' => 'Disabled Prize', 'status' => 0]);
        $this->addTiersToPrize($disabledPrize, [99], level: 0);

        // active prize：achieve_people 級距 3
        $activePrize = $this->createPrize($event->id, ['name' => 'Active Prize', 'status' => 1]);
        $this->addTiersToPrize($activePrize, [3], level: 0);

        $detail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            'point' => 999,
            'status' => 1,
        ]);

        // 0 邀請 → 門檻應取自 active prize = 3（非停用 prize 的 99）
        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(3, $result[0]['progress']['requiredPoint']);
        $this->assertSame(3, $result[0]['progress']['remaining']);

        // 直接斷言 eager-load 選中的是 active prize（去除對「DB row order 回較小 id」的隱性依賴）：
        // 移除 status 約束時，eager-load 會帶回停用 prize，本斷言必定轉紅。
        $detail->load([
            'event.prize' => fn ($q) => $q->where('status', 1)->where('expire_date', '>', now()),
        ]);
        $this->assertSame($activePrize->id, $detail->event->prize->id);
    }

    /**
     * 多 prize expire 約束守護（修正 3）：同一 event 同時有「已過期 prize（較早 id、status=1、
     * achieve_people=99）」與「active prize（status=1、expire_date 未來、achieve_people=3）」。
     * eager-load 閉包的 `->where('expire_date', '>', $now)` 必須濾掉過期 prize，
     * 門檻才會是 3 而非 99。拿掉該 expire 約束時，過期 prize 會污染門檻，本測試轉紅。
     */
    public function test_invite_threshold_uses_unexpired_prize_when_multiple_prizes_exist(): void
    {
        $member = $this->createMember($this->agent);
        $event = Event::factory()->create(['agent_id' => $this->agent->id, 'status' => 1]);

        // 已過期 prize（較早 id、status=1）：achieve_people 級距 99
        $expiredPrize = $this->createPrize($event->id, [
            'name' => 'Expired Prize',
            'status' => 1,
            'expire_date' => now()->subDay()->toDateTimeString(),
        ]);
        $this->addTiersToPrize($expiredPrize, [99], level: 0);

        // active prize（status=1、未過期）：achieve_people 級距 3
        $activePrize = $this->createPrize($event->id, [
            'name' => 'Active Prize',
            'status' => 1,
            'expire_date' => now()->addDays(30)->toDateTimeString(),
        ]);
        $this->addTiersToPrize($activePrize, [3], level: 0);

        $detail = EventDetail::factory()->create([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            'point' => 999,
            'status' => 1,
        ]);

        // 0 邀請 → 門檻應取自未過期的 active prize = 3（非過期 prize 的 99）
        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(3, $result[0]['progress']['requiredPoint']);
        $this->assertSame(3, $result[0]['progress']['remaining']);

        $detail->load([
            'event.prize' => fn ($q) => $q->where('status', 1)->where('expire_date', '>', now()),
        ]);
        $this->assertSame($activePrize->id, $detail->event->prize->id);
    }

    // ════════════════════════════════════════════════════════════
    //  level-1「邀請並充值」附加維度（additionalProgress）
    // ════════════════════════════════════════════════════════════

    /**
     * action 15 同時有 level-0 {3} 與 level-1 {2}、被邀請者 0：
     * 既有 progress（純邀請）門檻為 3，並新增 additionalProgress[0]（邀請並充值）
     * requiredPoint=2、currentPoint=0、remaining=2、depositThreshold=detail.point。
     */
    public function test_invite_deposit_dimension_emitted_with_level_one_tier(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100, // 此維度語意為「最低充值金額門檻」
            ],
        );
        $this->addPrizeTiers($detail, [3], level: 0); // 純邀請級距
        $this->addPrizeTiers($detail, [2], level: 1); // 邀請並充值級距

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        // 既有 level-0 progress 不變
        $this->assertSame('NEW_INVITE_PLAYER_OFFERS', $result[0]['progress']['actionType']);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(3, $result[0]['progress']['requiredPoint']);

        // 新增 additionalProgress
        $this->assertArrayHasKey('additionalProgress', $result[0]);
        $this->assertCount(1, $result[0]['additionalProgress']);
        $dimension = $result[0]['additionalProgress'][0];
        $this->assertSame('INVITE_PLAYER_DEPOSIT', $dimension['actionType']);
        $this->assertSame(EventActionIdEnum::INVITE_PLAYER_DEPOSIT->value, $dimension['actionId']);
        $this->assertSame(2, $dimension['requiredPoint']);
        $this->assertSame(0, $dimension['currentPoint']);
        $this->assertSame(2, $dimension['remaining']);
        $this->assertSame(100, $dimension['depositThreshold']);
    }

    /**
     * level-1 計數：被邀請者中，活動窗內首筆 status=3 訂單 point 達門檻者才計入。
     * - A：首單 status=3、窗內、point>=門檻 → 計入
     * - B：首單 status=3、窗內、point<門檻 → 不計入
     * - C：唯一訂單 status!=3 → 不計入
     * - D：訂單 status=3、point 達標但落在窗外 → 不計入
     * 預期 additionalProgress.currentPoint = 1。
     */
    public function test_invite_deposit_dimension_counts_only_qualified_first_orders(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );
        // 加 level-0 大門檻（邀請數遠不及）→ 活動以純邀請為主、續儲維度留在 additionalProgress，
        // 保留本測試對「續儲維度計數邏輯」的覆蓋（只有 level-1 的活動改由主 progress 承載，另有專屬測試）。
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [2], level: 1);

        $u = fn () => uniqid('inv_');
        $makeInvitee = function () use ($u): Member {
            return Member::factory()->create([
                'parent' => $this->agent->id,
                'username' => $u(),
                'nickname' => $u(),
            ]);
        };

        $a = $makeInvitee();
        $b = $makeInvitee();
        $c = $makeInvitee();
        $d = $makeInvitee();

        // A：窗內 status=3、amount 150 >= 100 → 計入
        Order::factory()->create([
            'member_id' => $a->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 150,
            'point' => 150,
        ]);
        // B：窗內 status=3、amount 50 < 100 → 不計入
        Order::factory()->create([
            'member_id' => $b->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 50,
            'point' => 50,
        ]);
        // C：唯一訂單 status=CREATED → 子查詢 status=3 過濾掉，不計入
        Order::factory()->create([
            'member_id' => $c->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::CREATED->value,
            'amount' => 200,
            'point' => 200,
        ]);
        // D：status=3、amount 達標但 created_at 落在窗外 → 不計入
        $orderD = Order::factory()->create([
            'member_id' => $d->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 200,
            'point' => 200,
        ]);
        $orderD->created_at = Carbon::parse($detail->start_time)->subDay();
        $orderD->save();

        foreach ([$a, $b, $c, $d] as $invitee) {
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitee->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('additionalProgress', $result[0]);
        $dimension = $result[0]['additionalProgress'][0];
        $this->assertSame(1, $dimension['currentPoint']); // 只有 A
        $this->assertSame(2, $dimension['requiredPoint']);
        $this->assertSame(1, $dimension['remaining']);
        $this->assertSame(100, $dimension['depositThreshold']);
    }

    /**
     * 相容性：活動只有 level-0（無 level-1 prize）→ 不輸出 additionalProgress，
     * 回應形狀與既有逐位元組相同。
     */
    public function test_invite_deposit_dimension_absent_when_no_level_one_tier(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 999,
            ],
        );
        $this->addPrizeTiers($detail, [3], level: 0); // 只有 level-0

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('additionalProgress', $result[0]);
    }

    /**
     * message 會附加 level-1 維度的進度行，且含 min deposit 門檻。
     */
    public function test_invite_deposit_dimension_appends_message_line_with_min_deposit(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
            ],
        );
        $this->addPrizeTiers($detail, [3], level: 0);
        $this->addPrizeTiers($detail, [2], level: 1);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertContains(
            'Progress: INVITE_PLAYER_DEPOSIT 0 / 2 (2 remaining, min deposit 100 points)',
            $result[0]['message'],
        );
    }

    /**
     * 首單未達標、後續單達標 → 不計入（守 MIN(id) 首單語意）。
     * 同一被邀請者：首單（較小 id／較早 created_at）point=50 < 門檻(100)、
     * 第二單 point=200 >= 100，皆 status=3、皆窗內 → 只看首單 → currentPoint=0。
     * 此案同時殺 MIN→MAX、「任一單達標」、per-member 去重三種 mutation。
     */
    public function test_invite_deposit_dimension_only_counts_first_order_not_later_qualified(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );
        // level-0 大門檻 → 續儲維度留在 additionalProgress（見 counts_only_qualified 說明）。
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [2], level: 1);

        $u = fn () => uniqid('inv_');
        $invitee = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(),
            'nickname' => $u(),
        ]);

        // 首單：較早 created_at（窗內）、point=50 < 門檻 → 不達標
        $first = Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 50,
            'point' => 50,
        ]);
        $first->created_at = Carbon::parse($detail->start_time)->addDay();
        $first->save();

        // 第二單：較晚 created_at（窗內）、point=200 >= 門檻 → 但非首單，不應使該被邀請者計入
        $second = Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 200,
            'point' => 200,
        ]);
        $second->created_at = Carbon::parse($detail->start_time)->addDays(2);
        $second->save();

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('additionalProgress', $result[0]);
        $this->assertSame(0, $result[0]['additionalProgress'][0]['currentPoint']);
    }

    /**
     * point 剛好等於門檻 → 計入（守 `>=`，防 `>` mutation）。
     * 被邀請者首單 point == detail.point(100)、status=3、窗內 → currentPoint=1。
     */
    public function test_invite_deposit_dimension_counts_when_point_equals_threshold(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );
        // level-0 大門檻 → 續儲維度留在 additionalProgress（見 counts_only_qualified 說明）。
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [2], level: 1);

        $u = fn () => uniqid('inv_');
        $invitee = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(),
            'nickname' => $u(),
        ]);

        Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 100,
            'point' => 100, // 恰好等於門檻
        ]);

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('additionalProgress', $result[0]);
        $this->assertSame(1, $result[0]['additionalProgress'][0]['currentPoint']);
    }

    /**
     * level-1 多級規則 A：level-1 tiers {2,5}，2 名達標被邀請者（各自首單 point>=門檻）。
     * 門檻取「下一未達成最低級」= 大於 current(2) 的最小級 = 5 →
     * requiredPoint=5, currentPoint=2, remaining=3（守 level-1 獨立路徑的多級選級）。
     */
    public function test_invite_deposit_dimension_multi_tier_picks_next_unmet_level(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );
        // level-0 大門檻 → 續儲維度留在 additionalProgress（見 counts_only_qualified 說明）。
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [2, 5], level: 1);

        $u = fn () => uniqid('inv_');
        foreach (range(1, 2) as $i) {
            $invitee = Member::factory()->create([
                'parent' => $this->agent->id,
                'username' => $u(),
                'nickname' => $u(),
            ]);

            Order::factory()->create([
                'member_id' => $invitee->id,
                'admin' => 0,
                'order_type_id' => 0,
                'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
                'amount' => 150,
                'point' => 150, // >= 門檻
            ]);

            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitee->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $dimension = $result[0]['additionalProgress'][0];
        $this->assertSame(2, $dimension['currentPoint']);
        $this->assertSame(5, $dimension['requiredPoint']);
        $this->assertSame(3, $dimension['remaining']);
    }

    /**
     * 以 point 判定、非 amount（鎖死修正 1）。
     * 被邀請者首單 amount=50（< 門檻）但 point=150（>= 門檻 100）、status=3、窗內 →
     * currentPoint=1。證明比的是 point 不是 amount；若改回 orders.amount 此案轉紅。
     */
    public function test_invite_deposit_dimension_uses_point_not_amount(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );
        // level-0 大門檻 → 續儲維度留在 additionalProgress（見 counts_only_qualified 說明）。
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [2], level: 1);

        $u = fn () => uniqid('inv_');
        $invitee = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(),
            'nickname' => $u(),
        ]);

        // amount=50 < 門檻，但 point=150 >= 門檻：rate≠1 / tax 造成 amount 與 point 發散
        Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 50,
            'point' => 150,
        ]);

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('additionalProgress', $result[0]);
        $this->assertSame(1, $result[0]['additionalProgress'][0]['currentPoint']);
    }

    /**
     * 契約守護：level-1「邀請並充值」維度只掛在 action 15（NEW_INVITE_PLAYER_OFFERS），
     * action 60（TPOWER_INVITE_PLAYER）即使 prize 同時有 level-1 achieve_people 級距，
     * 也**不得**輸出 additionalProgress。
     * 若 buildConditioned 的條件被誤放寬成「含 action 60」，本測試轉紅。
     */
    public function test_tpower_invite_does_not_emit_additional_progress(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::TPOWER_INVITE_PLAYER->value,
                'point' => 100,
            ],
        );
        // level-0 純邀請級距 {5} 讓活動能進 conditioned（current=2 < 5）
        $this->addPrizeTiers($detail, [5], level: 0);
        // level-1 邀請並充值級距 {2}：action 60 不應據此掛 additionalProgress
        $this->addPrizeTiers($detail, [2], level: 1);

        $this->createValidInvites($detail, $member, 2);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        // 既有 level-0 progress 仍正常顯示
        $this->assertSame('TPOWER_INVITE_PLAYER', $result[0]['progress']['actionType']);
        $this->assertSame(2, $result[0]['progress']['currentPoint']);
        $this->assertSame(5, $result[0]['progress']['requiredPoint']);
        // 只有 action 15 掛 level-1 維度 → action 60 必無 additionalProgress
        $this->assertArrayNotHasKey('additionalProgress', $result[0]);
    }

    /**
     * level-1 時間窗上界為「嚴格小於」：被邀請者唯一一筆達標首單（status=3、point>=門檻）
     * 的 created_at 剛好等於 end_time → **不計入** additionalProgress.currentPoint。
     * 守 resolveInviteDepositDimension 的 `->where('created_at', '<', end_time)` 上界
     * （既有測試只覆蓋了窗外/窗內，未鎖死 == end_time 邊界）。
     */
    public function test_invite_deposit_dimension_excludes_order_at_end_time_boundary(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );
        // level-0 大門檻 → 續儲維度留在 additionalProgress（見 counts_only_qualified 說明）。
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [2], level: 1);

        $u = fn () => uniqid('inv_');
        $invitee = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(),
            'nickname' => $u(),
        ]);

        // 唯一一筆達標首單，但 created_at 剛好等於 end_time → 嚴格小於上界排除
        $order = Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 150,
            'point' => 150,
        ]);
        $order->created_at = Carbon::parse($detail->end_time);
        $order->save();

        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('additionalProgress', $result[0]);
        $this->assertSame(0, $result[0]['additionalProgress'][0]['currentPoint']); // 不含 created_at == end_time 那筆
        $this->assertSame(2, $result[0]['additionalProgress'][0]['requiredPoint']);
    }

    /**
     * level-1 全達成回最高級（守 `?? last()` fallback）：level-1 tiers {2}（單級），
     * 2 名達標被邀請者 → current(2) >= 唯一級(2)，first(tier>2) 為 null →
     * requiredPoint 取 last()=2、currentPoint=2、remaining=0。
     * 若拿掉 `?? $tiers->last()` fallback，requiredPoint 會變 null 導致型別/數值錯誤，本測試轉紅。
     */
    public function test_invite_deposit_dimension_returns_top_tier_when_all_achieved(): void
    {
        $member = $this->createMember($this->agent);
        // level-0 大門檻 {99}（2 邀請遠不及）→ 活動以純邀請為主而顯示，續儲維度留在 additionalProgress，
        // 保留本測試對「續儲維度全達成回最高級」的覆蓋。
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
            ],
        );
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [2], level: 1); // 單一 level-1 級距

        $u = fn () => uniqid('inv_');
        foreach (range(1, 2) as $i) {
            $invitee = Member::factory()->create([
                'parent' => $this->agent->id,
                'username' => $u(),
                'nickname' => $u(),
            ]);

            Order::factory()->create([
                'member_id' => $invitee->id,
                'admin' => 0,
                'order_type_id' => 0,
                'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
                'amount' => 150,
                'point' => 150, // >= 門檻 100
            ]);

            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitee->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $dimension = $result[0]['additionalProgress'][0];
        $this->assertSame(2, $dimension['currentPoint']);
        $this->assertSame(2, $dimension['requiredPoint']); // 全達成 → last()
        $this->assertSame(0, $dimension['remaining']);
    }

    /**
     * 回歸守護：只有 level-1 的 action 15、玩家已邀請滿 point fallback 門檻人數、但續儲未達標。
     * 舊行為：純邀請主 progress 的 required=point(1)、current(邀請數)=1 >= 1 → 整筆 entry 被砍，
     * 連 level-1 維度一起從 bot 消失。新行為：主 progress = 續儲維度（current 續儲 < required）→ 活動仍出現。
     */
    public function test_invite_only_level_one_activity_not_dropped_when_invite_quota_met(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 1, // 舊 point fallback 門檻 = 1
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );
        $this->addPrizeTiers($detail, [2], level: 1); // 只有 level-1

        // 邀請 1 人（達舊 point fallback 門檻 1）、但該被邀請者無達標首單 → 續儲 current=0
        $invitee = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => uniqid('inv_'),
            'nickname' => uniqid('inv_'),
        ]);
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        // 活動不再消失：仍出現於 conditioned，且主 progress 為續儲維度
        $this->assertCount(1, $result);
        $this->assertSame('INVITE_PLAYER_DEPOSIT', $result[0]['progress']['actionType']);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(2, $result[0]['progress']['requiredPoint']);
        $this->assertArrayNotHasKey('additionalProgress', $result[0]);
    }

    /**
     * 只有 level-1 的 action 15：主訊息行採續儲格式（含 min deposit 門檻），
     * 且不得出現純邀請（NEW_INVITE_PLAYER_OFFERS）進度行。
     */
    public function test_invite_only_level_one_message_uses_deposit_format(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
                'realname_block' => 0, // 顯式關閉，避免依賴 DB 欄位預設值才不觸發 whereHas('member')
            ],
        );
        $this->addPrizeTiers($detail, [2], level: 1); // 只有 level-1

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        // 主訊息行為續儲格式、含 min deposit 門檻
        $this->assertContains(
            'Progress: INVITE_PLAYER_DEPOSIT 0 / 2 (2 remaining, min deposit 100 points)',
            $result[0]['message'],
        );
        // 不得出現純邀請進度行
        foreach ($result[0]['message'] as $line) {
            $this->assertStringNotContainsString('NEW_INVITE_PLAYER_OFFERS', $line);
        }
    }

    /**
     * 方案乙 — level-0 + level-1、純邀請已滿級、續儲未達標 → 活動不消失、續儲為主。
     * level-0 {2}（邀 2 人即滿）+ level-1 {3}，邀請 2 人但無人續儲達標 →
     * 舊行為會因純邀請滿級砍掉整筆 entry，連未達標的續儲維度一起消失；
     * 新行為改以續儲維度為主 progress（活動仍出現）、不掛 additionalProgress。
     */
    public function test_invite_promotes_deposit_to_primary_when_invite_met_but_deposit_unmet(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
                'realname_block' => 0,
            ],
        );
        $this->addPrizeTiers($detail, [2], level: 0); // 純邀請 2 人即滿
        $this->addPrizeTiers($detail, [3], level: 1); // 續儲門檻

        // 邀請 2 人（純邀請達標）、皆無達標首單 → 續儲 current=0
        $u = fn () => uniqid('inv_');
        foreach (range(1, 2) as $i) {
            $invitee = Member::factory()->create([
                'parent' => $this->agent->id,
                'username' => $u(),
                'nickname' => $u(),
            ]);
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitee->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        // 活動仍出現、主 progress 為續儲維度、不掛 additionalProgress
        $this->assertCount(1, $result);
        $this->assertSame('INVITE_PLAYER_DEPOSIT', $result[0]['progress']['actionType']);
        $this->assertSame(0, $result[0]['progress']['currentPoint']);
        $this->assertSame(3, $result[0]['progress']['requiredPoint']);
        $this->assertArrayNotHasKey('additionalProgress', $result[0]);
    }

    /**
     * 方案乙 — level-0 + level-1、兩維度皆達標 → 活動隱藏。
     * level-0 {2}（邀 2 人即滿）+ level-1 {1}（1 人續儲即滿），邀請 2 人且皆續儲達標 →
     * 純邀請與續儲皆達標 → 整筆 entry 隱藏（不出現於 conditioned）。
     */
    public function test_invite_hidden_when_all_dimensions_met(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
                'realname_block' => 0,
            ],
        );
        $this->addPrizeTiers($detail, [2], level: 0); // 純邀請 2 人即滿
        $this->addPrizeTiers($detail, [1], level: 1); // 續儲 1 人即滿

        // 邀請 2 人、皆有窗內 status=3 首單 point>=門檻 → 純邀請與續儲皆達標
        $u = fn () => uniqid('inv_');
        foreach (range(1, 2) as $i) {
            $invitee = Member::factory()->create([
                'parent' => $this->agent->id,
                'username' => $u(),
                'nickname' => $u(),
            ]);
            Order::factory()->create([
                'member_id' => $invitee->id,
                'admin' => 0,
                'order_type_id' => 0,
                'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
                'amount' => 150,
                'point' => 150, // >= 門檻 100
            ]);
            EventInviteRecord::create([
                'event_id' => $detail->event_id,
                'member_id' => $invitee->id,
                'invite_member_id' => $member->id,
                'ip_duplicate' => 0,
                'phone_duplicate' => 0,
                'realname_duplicate' => 0,
                'telegram_id_duplicate' => 0,
            ]);
        }

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertSame([], $result);
    }

    /**
     * Cleanup — level-1 多級選級殺 `?? last()` mutation：level-1 tiers {3,9}、
     * 1 名達標被邀請者（current=1）→ requiredPoint 取「大於 current 的最小級」=3（非 last()=9）。
     * 既有多級測試的 current 都剛好落在 next==last，把 `first(fn>current) ?? last()` 換成
     * `return last()` 也不會轉紅；本測試讓 next≠last，鎖死選級邏輯。
     */
    public function test_invite_deposit_dimension_picks_lowest_unmet_tier_not_last(): void
    {
        $member = $this->createMember($this->agent);
        $detail = $this->createEligibleDetail(
            detailAttrs: [
                'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
                'point' => 100,
                'start_time' => now()->subDays(5),
                'end_time' => now()->addDays(5),
                'realname_block' => 0,
            ],
        );
        // level-0 大門檻 → 純邀請未達標而為主、續儲維度留在 additionalProgress（穩定斷言 requiredPoint）。
        $this->addPrizeTiers($detail, [99], level: 0);
        $this->addPrizeTiers($detail, [3, 9], level: 1);

        // 1 名達標被邀請者（窗內 status=3 首單 point>=門檻）→ 續儲 current=1
        $u = fn () => uniqid('inv_');
        $invitee = Member::factory()->create([
            'parent' => $this->agent->id,
            'username' => $u(),
            'nickname' => $u(),
        ]);
        Order::factory()->create([
            'member_id' => $invitee->id,
            'admin' => 0,
            'order_type_id' => 0,
            'status' => OrderStatusEnum::DEPOSIT_FINISHED->value,
            'amount' => 150,
            'point' => 150,
        ]);
        EventInviteRecord::create([
            'event_id' => $detail->event_id,
            'member_id' => $invitee->id,
            'invite_member_id' => $member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        $result = (new PromotionV1Builder)->buildConditioned($member);

        $this->assertCount(1, $result);
        $dimension = $result[0]['additionalProgress'][0];
        $this->assertSame(1, $dimension['currentPoint']);
        $this->assertSame(3, $dimension['requiredPoint']); // 大於 current(1) 的最小級 3，非 last()=9
        $this->assertSame(2, $dimension['remaining']);
    }
}
