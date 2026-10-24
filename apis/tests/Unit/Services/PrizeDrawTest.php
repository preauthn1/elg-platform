<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Event\EventActionIdEnum;
use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\Transaction\TransactionEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\EventPayoutCancel;
use App\Models\EventProcess;
use App\Models\EventV2Detail;
use App\Models\EventV2UserJoin;
use App\Models\Member;
use App\Models\MemberBankAccount;
use App\Models\NewbieChallengeProgress;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Models\PromotionWallet;
use App\Models\RewardClaimRecord;
use App\Services\PrizeService;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PrizeService::drawPrize 測試（舊版 api/getPrize）
 *
 * php artisan test tests/Unit/Services/PrizeDrawTest.php
 */
class PrizeDrawTest extends TestCase
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
            'action_id' => EventActionIdEnum::BET->value,
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
            'number' => 10,
            'sent' => 0,
            'level' => 0,
            'point' => 70,
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

    private function makeClaimable(array $ctx, array $overrides = []): PrizeMember
    {
        EventProcess::create([
            'member_id' => $this->member->id,
            'detail_id' => $ctx['detail']->id,
            'point' => '0.0000',
            'received' => 0,
            'admin' => 0,
        ]);

        return PrizeMember::create(array_merge([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'status' => 0,
            'admin' => 0,
        ], $overrides));
    }

    public function test_draws_and_credits_balance(): void
    {
        $ctx = $this->makeEvent();
        $prizeMember = $this->makeClaimable($ctx);

        $data = $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame($ctx['prize']->id, (int) $data['id']);
        $this->assertSame($ctx['content']->id, (int) $data['prize']->id);
        $this->assertSame($prizeMember->id, (int) $data['prize']->prize_member);
        $this->assertSame(70.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) PrizeMember::find($prizeMember->id)->status);
    }

    public function test_response_carries_get_point_and_point_type(): void
    {
        $ctx = $this->makeEvent([], ['point' => 35, 'point_type' => 1]);
        $this->makeClaimable($ctx);

        $data = $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(35.0, (float) $data['prize']['get_point']);
        $this->assertSame(1, $data['prize']['point_type']);
    }

    public function test_credits_promotion_wallet_when_point_type_is_promotion(): void
    {
        $ctx = $this->makeEvent([], ['point' => 88, 'point_type' => 4]);
        $this->makeClaimable($ctx);

        $data = $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        // 優惠錢包不進主錢包
        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);
        $this->assertSame(4, $data['prize']['point_type']);

        $wallet = PromotionWallet::where('member_id', $this->member->id)->first();
        $this->assertNotNull($wallet);
        $this->assertSame(88.0, (float) $wallet->balance);
        $this->assertSame($ctx['event']->id, (int) $wallet->event_id);
        $this->assertSame(1, (int) $wallet->status);

        $this->assertDatabaseHas('promotion_wallet_logs', [
            'member_id' => $this->member->id,
            'promotion_wallet_id' => $wallet->id,
            'event_id' => $ctx['event']->id,
            'type' => 3,
            'before_balance' => '0.0000',
            'balance' => '88.0000',
        ]);
    }

    public function test_accumulates_into_existing_promotion_wallet(): void
    {
        $ctx = $this->makeEvent([], ['point' => 20, 'point_type' => 4]);
        $this->makeClaimable($ctx);

        $wallet = PromotionWallet::create([
            'parent' => $this->member->parent,
            'member_id' => $this->member->id,
            'event_id' => $ctx['event']->id,
            'balance' => '30.0000',
            'status' => 1,
            'admin' => 0,
        ]);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(50.0, (float) $wallet->fresh()->balance);
        $this->assertSame(1, PromotionWallet::where('member_id', $this->member->id)->count());
        $this->assertDatabaseHas('promotion_wallet_logs', [
            'promotion_wallet_id' => $wallet->id,
            'before_balance' => '30.0000',
            'balance' => '50.0000',
        ]);
    }

    public function test_promotion_wallet_flow_limit_uses_wallet_id_and_reward_type(): void
    {
        $ctx = $this->makeEvent(
            [],
            ['point' => 60, 'point_type' => 4, 'flow_limit_multiple' => 2],
            ['flow_limit_multiple' => 2],
        );
        $this->makeClaimable($ctx);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $wallet = PromotionWallet::where('member_id', $this->member->id)->first();

        // 優惠錢包的稽核流水改記 type 104、reference_id 指向錢包本身（vw 獨有）
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'event_id' => $ctx['event']->id,
            'type' => TransactionEnum::PROMOTION_WALLET_REWARD->value,
            'reference_id' => $wallet->id,
            'flow_limit' => '120.0000',
        ]);
    }

    public function test_promotion_wallet_rejects_conflicting_vendor(): void
    {
        $ctx = $this->makeEvent([], ['point' => 10, 'point_type' => 4]);
        $this->makeClaimable($ctx);

        // 既有優惠錢包綁的遊戲商與本活動相同，不可同時持有
        $otherEvent = Event::factory()->create(['agent_id' => $this->agent->id, 'status' => 1]);
        PromotionWallet::create([
            'parent' => $this->member->parent,
            'member_id' => $this->member->id,
            'event_id' => $otherEvent->id,
            'balance' => '5.0000',
            'status' => 1,
            'admin' => 0,
        ]);
        DB::table('event_promotions')->insert([
            ['event_id' => $ctx['event']->id, 'vendor_id' => 7, 'admin' => 0],
            ['event_id' => $otherEvent->id, 'vendor_id' => 7, 'admin' => 0],
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2042'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_throws_when_member_is_in_event_lock_mode(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx);
        $this->member->update(['event_mode' => 3]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_throws_when_member_has_active_event_v2(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx);
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
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_throws_when_member_has_active_newbie_challenge(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx);
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_throws_when_event_detail_not_in_time_window(): void
    {
        $ctx = $this->makeEvent(['start_time' => now()->addDay(), 'end_time' => now()->addDays(2)]);
        $this->makeClaimable($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_throws_when_no_claimable_prize_member(): void
    {
        $ctx = $this->makeEvent();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1949'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_login_gift_creates_prize_member_on_the_fly(): void
    {
        $ctx = $this->makeEvent(['action_id' => EventActionIdEnum::LOGIN->value]);

        $data = $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $created = PrizeMember::where('member_id', $this->member->id)->where('prize_id', $ctx['prize']->id)->first();
        $this->assertNotNull($created);
        $this->assertSame(1, (int) $created->status);
        $this->assertSame($ctx['content']->id, (int) $created->content_id);
        $this->assertSame(70.0, (float) $data['prize']['get_point']);
        // 登入送把獎勵分數寫入 lottery_point（舊版誤寫成不存在的 point 欄位，見文件 2-10）
        $this->assertSame(70.0, (float) $created->lottery_point);
    }

    public function test_login_gift_percentage_content_uses_written_lottery_point(): void
    {
        // type=1（%數）時 sendPrize 以 prize_members.lottery_point 為基數，
        // 故登入送先寫入的 lottery_point 會直接影響派發金額
        $ctx = $this->makeEvent(
            ['action_id' => EventActionIdEnum::LOGIN->value],
            ['type' => 1, 'point' => 40, 'point_percentage' => 0],
        );

        $data = $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(40.0, (float) $data['prize']['get_point']);
        $this->assertSame(40.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_login_gift_is_not_repeatable(): void
    {
        $ctx = $this->makeEvent(['action_id' => EventActionIdEnum::LOGIN->value]);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        // 已存在 prize_member 且已領取 → 第二次找不到可領取的
        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1949'));
        $this->service->drawPrize($this->member->fresh(), $this->agent, $ctx['event']->id, 1);
    }

    public function test_already_drawn_content_increments_sent(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx, ['content_id' => $ctx['content']->id]);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(1, (int) PrizeContent::find($ctx['content']->id)->sent);
    }

    /**
     * 儲值活動與註冊送由 SP 增加 sent，端點不再加一次
     */
    public function test_sent_is_not_incremented_for_sp_managed_actions(): void
    {
        $ctx = $this->makeEvent(['action_id' => EventActionIdEnum::DEPOSIT_MANUAL->value]);
        $this->makeClaimable($ctx, ['content_id' => $ctx['content']->id, 'lottery_point' => '0.0000']);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(0, (int) PrizeContent::find($ctx['content']->id)->sent);
    }

    public function test_register_bonus_also_skips_sent_increment(): void
    {
        // action 11 為 vw-master 獨有的排除項，本次取聯集收錄
        $ctx = $this->makeEvent(['action_id' => EventActionIdEnum::REG_BONUS->value]);
        $this->makeClaimable($ctx, ['content_id' => $ctx['content']->id]);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(0, (int) PrizeContent::find($ctx['content']->id)->sent);
    }

    public function test_content_name_uses_language_row(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx, ['content_id' => $ctx['content']->id]);
        \DB::table('prize_content_names')->insert([
            'prize_content_id' => $ctx['content']->id,
            'language_id' => 2,
            'name' => 'localized name',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $data = $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 2);

        $this->assertSame('localized name', $data['prize']->name);
    }

    public function test_throws_when_progress_not_reached(): void
    {
        $ctx = $this->makeEvent(['point' => 100]);
        PrizeMember::create([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'status' => 0,
            'admin' => 0,
        ]);
        EventProcess::create([
            'member_id' => $this->member->id,
            'detail_id' => $ctx['detail']->id,
            'point' => '10.0000',
            'received' => 0,
            'admin' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.2004'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_tpower_invite_action_skips_progress_check(): void
    {
        // action 60 無進度條，直接視為合法（tpower 獨有，本次取聯集收錄）
        $ctx = $this->makeEvent(
            ['action_id' => EventActionIdEnum::TPOWER_INVITE_PLAYER->value, 'point' => 100],
            [],
            ['info' => json_encode(['achieve_people' => 1])],
        );

        // 派彩時會回頭綁定達標的邀請紀錄，需有一筆合格的被邀請人
        $invitee = $this->createMember($this->agent, [
            'username' => 'invitee_' . Str::random(12),
            'status' => 1,
            'realname_validate' => 1,
            'mobile_validate' => 1,
        ]);
        EventInviteRecord::create([
            'event_id' => $ctx['event']->id,
            'member_id' => $invitee->id,
            'invite_member_id' => $this->member->id,
            'ip_duplicate' => 0,
            'phone_duplicate' => 0,
            'realname_duplicate' => 0,
            'telegram_id_duplicate' => 0,
        ]);

        PrizeMember::create([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'content_id' => $ctx['content']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $data = $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(70.0, (float) $data['prize']['get_point']);
    }

    public function test_throws_when_mobile_validation_required_but_unverified(): void
    {
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::EVENT_REWARD_MOBILE_VALIDATION, 1);
        Cache::flush();
        $this->member->update(['mobile_validate' => 0]);

        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9007'));
        $this->service->drawPrize($this->member, $this->agent->fresh(), $ctx['event']->id, 1);
    }

    public function test_throws_when_first_name_missing(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx);
        $this->member->update(['first_name' => '']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1095'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_blocks_duplicate_register_ip(): void
    {
        $ctx = $this->makeEvent(['ip_block' => 1]);
        $this->makeClaimable($ctx);

        $other = $this->createMember($this->agent, [
            'username' => 'other_' . Str::random(10),
            'register_ip' => $this->member->register_ip,
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
            $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
            $this->fail('should be blocked');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.1950'), $e->getMessage());
        }

        $this->assertSame(2, (int) EventPayoutCancel::where('member_id', $this->member->id)->value('type'));
        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_writes_reward_claim_records(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $types = RewardClaimRecord::where('event_detail_id', $ctx['detail']->id)
            ->where('member_id', $this->member->id)
            ->pluck('type')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 2, 3, 4], $types);
    }

    public function test_claim_ip_is_written_to_prize_member(): void
    {
        $ctx = $this->makeEvent();
        $prizeMember = $this->makeClaimable($ctx);

        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertNotEmpty(PrizeMember::find($prizeMember->id)->ip);
    }

    public function test_expired_prize_member_is_not_claimable(): void
    {
        $ctx = $this->makeEvent();
        $this->makeClaimable($ctx, ['expired_time' => now()->subDay()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1949'));
        $this->service->drawPrize($this->member, $this->agent, $ctx['event']->id, 1);
    }
}
