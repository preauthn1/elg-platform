<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Event\EventActionIdEnum;
use App\Enums\EventV2\EventV2DetailCostTypeEnum;
use App\Enums\EventV2\EventV2UserJoinStatusEnum;
use App\Enums\NewbieChallenge\NewbieChallengeStageEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventProcess;
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
use App\Services\PrizeService;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * PrizeService::drawByDetail / sendPrize 測試（舊版 api/getPrizeByDetailId）
 *
 * php artisan test tests/Unit/Services/PrizeDrawByDetailTest.php
 */
class PrizeDrawByDetailTest extends TestCase
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
        $this->member = $this->createMember($this->agent, ['event_mode' => 0]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /**
     * @return array{event: Event, detail: EventDetail, prize: Prize, content: PrizeContent}
     */
    private function makeEvent(
        array $contentOverrides = [],
        array $detailOverrides = [],
        array $prizeOverrides = [],
    ): array {
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
            'point' => 50,
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
        // refreshProcess 需要有 event_processes 紀錄且進度達標
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

    public function test_throws_when_member_is_in_event_lock_mode(): void
    {
        $ctx = $this->makeEvent();
        $this->member->update(['event_mode' => 5]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1934'));
        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }

    public function test_throws_when_member_has_active_event_v2(): void
    {
        $ctx = $this->makeEvent();
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
        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }

    public function test_throws_when_member_has_active_newbie_challenge(): void
    {
        $ctx = $this->makeEvent();
        NewbieChallengeProgress::create([
            'config_id' => 1,
            'member_id' => $this->member->id,
            'stage' => NewbieChallengeStageEnum::CHALLENGING->value,
            'activity_reward' => '0.0000',
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1992'));
        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }

    public function test_throws_when_prize_not_found(): void
    {
        $ctx = $this->makeEvent([], [], ['expire_date' => now()->subDay()]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }

    public function test_throws_when_nothing_claimable(): void
    {
        $ctx = $this->makeEvent();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9003'));
        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }

    public function test_draws_content_and_credits_platform_balance(): void
    {
        $ctx = $this->makeEvent(['point' => 50]);
        $prizeMember = $this->makeClaimable($ctx);
        $before = (float) $this->member->balance;

        $data = $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertSame($ctx['content']->id, (int) $data->prize->id);
        $this->assertSame($prizeMember->id, (int) $data->prize->prize_member);
        $this->assertSame($before + 50, (float) Member::find($this->member->id)->balance);
        $this->assertSame(1, (int) PrizeMember::find($prizeMember->id)->status);
        $this->assertSame(1, (int) PrizeContent::find($ctx['content']->id)->sent);
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'transaction_no' => $prizeMember->id,
        ]);
    }

    public function test_uses_already_drawn_content_without_incrementing_sent(): void
    {
        $ctx = $this->makeEvent(['point' => 30]);
        $prizeMember = $this->makeClaimable($ctx, ['content_id' => $ctx['content']->id]);

        $data = $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertSame($ctx['content']->id, (int) $data->prize->id);
        $this->assertSame(0, (int) PrizeContent::find($ctx['content']->id)->sent);
        $this->assertSame(1, (int) PrizeMember::find($prizeMember->id)->status);
    }

    public function test_throws_when_progress_not_reached(): void
    {
        $ctx = $this->makeEvent([], ['point' => 100]);
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
        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }

    public function test_throws_when_detail_reward_window_expired(): void
    {
        $ctx = $this->makeEvent([], [
            'draw_time' => now()->subHours(5),
            'expire_hour' => 1,
        ]);
        $this->makeClaimable($ctx);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1948'));
        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }

    public function test_expire_hour_zero_never_expires(): void
    {
        $ctx = $this->makeEvent([], ['draw_time' => now()->subYear(), 'expire_hour' => 0]);
        $this->makeClaimable($ctx);

        $data = $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertSame($ctx['content']->id, (int) $data->prize->id);
    }

    public function test_rolling_chip_payout_creates_wallet_and_log(): void
    {
        $ctx = $this->makeEvent(['point_type' => 2, 'point' => 20]);
        $this->makeClaimable($ctx);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $wallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_ROLLING_CHIP->value)
            ->first();

        $this->assertNotNull($wallet);
        $this->assertSame(20.0, (float) $wallet->balance);
        $this->assertSame(1, RollingChipLog::where('member_id', $this->member->id)->count());
        // 平台餘額不應變動
        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_flow_limit_is_created_with_multiple(): void
    {
        $ctx = $this->makeEvent(['point' => 40, 'flow_limit_multiple' => 3]);
        $this->makeClaimable($ctx);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'event_id' => $ctx['event']->id,
            'flow_limit' => '120.0000',
        ]);
    }

    /**
     * 儲值型活動：flow_limit_include_principal=1 時本金計入流水，deduct_principal 決定是否再扣回
     */
    public function test_flow_limit_includes_and_deducts_principal(): void
    {
        $ctx = $this->makeEvent(
            ['point' => 0, 'type' => 1, 'point_percentage' => '0.5000', 'flow_limit_multiple' => 2],
            ['action_id' => EventActionIdEnum::DEPOSIT_MANUAL->value],
            ['info' => json_encode(['flow_limit_include_principal' => 1, 'deduct_principal' => 1])],
        );
        $this->makeClaimable($ctx, ['lottery_point' => '100.0000']);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        // 獎金 = 100 * 0.5 = 50；流水 = (100 + 50) * 2 - 100 = 200
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'flow_limit' => '200.0000',
        ]);
        $this->assertSame(50.0, (float) Member::find($this->member->id)->balance);
    }

    /**
     * deduct_principal 已停用：即使明確設為 0 也一律扣回本金（見文件 2-8）
     */
    public function test_flow_limit_always_deducts_principal_even_when_flag_disabled(): void
    {
        $ctx = $this->makeEvent(
            ['point' => 0, 'type' => 1, 'point_percentage' => '0.5000', 'flow_limit_multiple' => 2],
            ['action_id' => EventActionIdEnum::DEPOSIT_MANUAL->value],
            ['info' => json_encode(['flow_limit_include_principal' => 1, 'deduct_principal' => 0])],
        );
        $this->makeClaimable($ctx, ['lottery_point' => '100.0000']);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        // 流水 = (100 + 50) * 2 - 100 = 200
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'flow_limit' => '200.0000',
        ]);
    }

    public function test_flow_limit_deducts_principal_when_flag_absent(): void
    {
        $ctx = $this->makeEvent(
            ['point' => 0, 'type' => 1, 'point_percentage' => '0.5000', 'flow_limit_multiple' => 2],
            ['action_id' => EventActionIdEnum::DEPOSIT_MANUAL->value],
            ['info' => json_encode(['flow_limit_include_principal' => 1])],
        );
        $this->makeClaimable($ctx, ['lottery_point' => '100.0000']);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'flow_limit' => '200.0000',
        ]);
    }

    public function test_max_point_caps_percentage_reward(): void
    {
        $ctx = $this->makeEvent(
            ['point' => 0, 'type' => 1, 'point_percentage' => '0.5000'],
            ['action_id' => EventActionIdEnum::DEPOSIT_MANUAL->value],
            ['max_point' => '30.0000'],
        );
        $this->makeClaimable($ctx, ['lottery_point' => '100.0000']);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertSame(30.0, (float) Member::find($this->member->id)->balance);
    }

    public function test_vendor_lock_sets_event_mode_and_lock_flow_type(): void
    {
        $ctx = $this->makeEvent(
            ['point' => 40, 'flow_limit_multiple' => 2],
            ['vendor_lock' => 1],
        );
        $this->makeClaimable($ctx);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertSame($ctx['content']->id, (int) Member::find($this->member->id)->event_mode);
        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'type' => 105,
        ]);
    }

    public function test_game_wallet_payout_when_multi_wallet_enabled(): void
    {
        // type=2 已廢除改為只查不建（決議⑬），存量錢包由測試預建——
        // 守的是「存量錢包還在的過渡期，多錢包派彩仍正確入帳」
        $this->createAgentSetting($this->agent, AgentCustomizedSettingKeyEnum::MULTIPLE_WALLET_SWITCH, 1);
        Cache::flush();
        MemberWallet::factory()->create([
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME,
            'balance' => 0,
        ]);

        $ctx = $this->makeEvent(['point' => 25, 'flow_limit_multiple' => 2]);
        $this->makeClaimable($ctx);

        $this->service->drawByDetail($this->member, $this->agent->fresh(), $ctx['event']->id, $ctx['detail']->id);

        $wallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_GAME->value)
            ->first();

        $this->assertNotNull($wallet);
        $this->assertSame(25.0, (float) $wallet->balance);
        $this->assertSame(0.0, (float) Member::find($this->member->id)->balance);

        $flowLimit = FlowLimit::where('member_id', $this->member->id)->first();
        $this->assertSame(0, (int) $flowLimit->reference_id);
        $this->assertGreaterThan(0, (int) $flowLimit->wallet_transaction_log_id);
    }

    public function test_cancel_other_reward_marks_sibling_rewards_cancelled(): void
    {
        $ctx = $this->makeEvent(['point' => 10], [], ['cancel_other_reward' => 1]);
        $prizeMember = $this->makeClaimable($ctx);

        $other = PrizeMember::create([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'status' => 0,
            'admin' => 0,
        ]);

        $orderId = 987654;
        \DB::table('prize_member_orders')->insert([
            ['prize_member_id' => $prizeMember->id, 'order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()],
            ['prize_member_id' => $other->id, 'order_id' => $orderId, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertSame(2, (int) PrizeMember::find($other->id)->status);
    }

    public function test_random_point_max_draws_within_range(): void
    {
        $ctx = $this->makeEvent(['type' => 0, 'point' => 10, 'random_point_max' => 20]);
        $this->makeClaimable($ctx);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $balance = (float) Member::find($this->member->id)->balance;
        $this->assertGreaterThanOrEqual(10.0, $balance);
        $this->assertLessThanOrEqual(20.0, $balance);
    }

    public function test_flow_limit_restrictions_from_prize_info(): void
    {
        $ctx = $this->makeEvent(
            ['point' => 40, 'flow_limit_multiple' => 2],
            [],
            ['info' => json_encode(['flow_limit_vendor_id' => [7, 0], 'flow_limit_game_type' => [0, 3]])],
        );
        $this->makeClaimable($ctx);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $flowLimit = FlowLimit::where('member_id', $this->member->id)->first();
        $this->assertDatabaseHas('flow_limit_restrictions', [
            'flow_limit_id' => $flowLimit->id,
            'vendor_id' => 7,
            'type_id' => 0,
        ]);
        $this->assertDatabaseHas('flow_limit_restrictions', [
            'flow_limit_id' => $flowLimit->id,
            'vendor_id' => 0,
            'type_id' => 3,
        ]);
    }

    public function test_flow_limit_restrictions_from_prize_content_table(): void
    {
        $ctx = $this->makeEvent(['point' => 40, 'flow_limit_multiple' => 2]);
        $this->makeClaimable($ctx);

        \DB::table('prize_content_flow_limits')->insert([
            'prize_content_id' => $ctx['content']->id,
            'vendor_id' => 11,
            'game_type_id' => 2,
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $flowLimit = FlowLimit::where('member_id', $this->member->id)->first();
        $this->assertDatabaseHas('flow_limit_restrictions', [
            'flow_limit_id' => $flowLimit->id,
            'vendor_id' => 11,
            'type_id' => 2,
        ]);
    }

    public function test_repeatable_prize_advances_process_received(): void
    {
        $ctx = $this->makeEvent(['point' => 10], ['point' => 20], ['is_repeatedly' => 1]);
        PrizeMember::create([
            'member_id' => $this->member->id,
            'prize_id' => $ctx['prize']->id,
            'event_detail_id' => $ctx['detail']->id,
            'status' => 0,
            'admin' => 0,
        ]);
        $process = EventProcess::create([
            'member_id' => $this->member->id,
            'detail_id' => $ctx['detail']->id,
            'point' => '50.0000',
            'received' => 0,
            'admin' => 0,
        ]);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertSame(20.0, (float) EventProcess::find($process->id)->received);
    }

    public function test_prize_member_id_is_used_as_transaction_no(): void
    {
        $ctx = $this->makeEvent(['point' => 15]);
        $prizeMember = $this->makeClaimable($ctx);

        $this->service->drawByDetail($this->member, $this->agent, $ctx['event']->id, $ctx['detail']->id);

        $this->assertDatabaseHas('transaction_logs', [
            'transaction_no' => (string) $prizeMember->id,
            'member_id' => $this->member->id,
            'operator' => 'system',
        ]);
    }

    public function test_unique_member_scope(): void
    {
        $ctx = $this->makeEvent(['point' => 15]);
        $this->makeClaimable($ctx);

        // 另一位會員不能領走這筆
        $other = $this->createMember($this->agent, ['username' => 'other_' . Str::random(10), 'event_mode' => 0]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.9003'));
        $this->service->drawByDetail($other, $this->agent, $ctx['event']->id, $ctx['detail']->id);
    }
}
