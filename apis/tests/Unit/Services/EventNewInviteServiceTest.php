<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Enums\Agent\AgentCustomizedSettingKeyEnum;
use App\Enums\Event\EventActionIdEnum;
use App\Enums\WalletEnum;
use App\Exceptions\ApiException;
use App\Models\Agent;
use App\Models\Event;
use App\Models\EventDetail;
use App\Models\EventInviteRecord;
use App\Models\EventPayoutCancel;
use App\Models\Member;
use App\Models\MemberCode;
use App\Models\MemberWallet;
use App\Models\Prize;
use App\Models\PrizeContent;
use App\Models\PrizeMember;
use App\Services\EventNewInviteService;
use Carbon\Carbon;
use Database\Seeders\SyncAgentSettingDefinesSeeder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * EventNewInviteService 測試（新玩家邀請活動 event/getNewInviteEvent、event/getNewInvitePrize）
 *
 * php artisan test tests/Unit/Services/EventNewInviteServiceTest.php
 */
class EventNewInviteServiceTest extends TestCase
{
    private EventNewInviteService $service;

    private Agent $agent;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(SyncAgentSettingDefinesSeeder::class);
        $this->service = app(EventNewInviteService::class);
        $this->agent = $this->createAgentAndDomain();
        $this->member = $this->createMember($this->agent, [
            'register_ip' => ip2long('0.0.0.0'),
            'mobile' => '0900' . random_int(100000, 999999),
        ]);
        MemberCode::create(['member_id' => $this->member->id, 'agent_code' => 'CODE' . Str::random(6)]);
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
        array $prizeInfo = ['period' => 1],
        array $contentOverrides = [],
        array $detailOverrides = [],
        array $prizeOverrides = [],
    ): array {
        $event = Event::factory()->create([
            'agent_id' => $this->agent->id,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'auto_payout' => 0,
        ]);

        $detail = EventDetail::factory()->create(array_merge([
            'event_id' => $event->id,
            'action_id' => EventActionIdEnum::NEW_INVITE_PLAYER_OFFERS->value,
            'status' => 1,
            'point' => 100,
            'vendor_id' => 0,
            'game_type_id' => 0,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
            'draw_time' => now()->addDay(),
            'expire_hour' => 0,
            'ip_block' => 0,
            'phone_block' => 0,
            'account_block' => 0,
            'realname_block' => 0,
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
            'info' => json_encode($prizeInfo),
            'status' => 1,
        ], $prizeOverrides));

        $content = PrizeContent::create(array_merge([
            'prize_id' => $prize->id,
            'type' => 0,
            'name' => 'content',
            'number' => 1,
            'level' => 0,
            'achieve_people' => 2,
            'percentage' => 0,
            'point_type' => 1,
            'point' => 50,
            'point_percentage' => 0,
            'achieve_point' => 0,
            'flow_limit_multiple' => 0,
            'status' => 1,
            'admin' => 0,
        ], $contentOverrides));

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

    private function makeQualifiedOrder(Member $invitee, int $point): void
    {
        DB::table('orders')->insert([
            'order_no' => 'T' . Str::random(12),
            'third_party_order_no' => '',
            'agent_cash_flow_id' => 0,
            'cash_flow_info_id' => 0,
            'cash_flow_id' => 0,
            'third_party_id' => 0,
            'currency_id' => 1,
            'tax' => 0,
            'balance' => 0,
            'ip' => '',
            'callback' => '',
            'extra_content' => '',
            'remark' => '',
            'member_id' => $invitee->id,
            'agent_id' => $this->agent->id,
            'order_type_id' => 1,
            'point' => $point,
            'amount' => $point,
            'rate' => 1,
            'status' => 3,
            'admin' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_get_event_throws_without_member_code(): void
    {
        $member = $this->createMember($this->agent, ['username' => 'nocode_' . Str::random(10)]);
        $ctx = $this->makeEvent();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1945'));
        $this->service->getEvent($member, $this->agent, $ctx['event']->id, 1);
    }

    public function test_get_event_throws_for_other_agent_event(): void
    {
        $other = Agent::factory()->create(['status' => 1]);
        $other->hierarchy_path = "/{$other->id}";
        $other->hierarchy = "[-1,{$other->id},-2]";
        $other->save();

        $event = Event::factory()->create([
            'agent_id' => $other->id,
            'status' => 1,
            'start_time' => now()->subDay(),
            'end_time' => now()->addDay(),
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1901'));
        $this->service->getEvent($this->member, $this->agent, $event->id, 1);
    }

    public function test_get_event_returns_code_and_progress(): void
    {
        $ctx = $this->makeEvent();
        $this->makeInvitee($ctx['event']);

        $data = $this->service->getEvent($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(MemberCode::where('member_id', $this->member->id)->value('agent_code'), $data->code);
        $this->assertCount(1, $data->prize_contents);
        $row = $data->prize_contents[0];
        // 1 位邀請、需 2 位 → 不可兌換
        $this->assertSame(1, $row->achieve_number);
        $this->assertSame(0, $row->show_exchange);
        $this->assertSame(__('param.prize_button.can_not_exchange'), $row->text);
    }

    public function test_get_event_marks_claimable_when_reached(): void
    {
        $ctx = $this->makeEvent();
        $this->makeInvitee($ctx['event']);
        $this->makeInvitee($ctx['event']);

        $data = $this->service->getEvent($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(2, $data->prize_contents[0]->achieve_number);
        $this->assertSame(1, $data->prize_contents[0]->show_exchange);
        $this->assertSame(__('param.prize_button.exchange'), $data->prize_contents[0]->text);
    }

    public function test_get_event_marks_exchanged_when_already_claimed(): void
    {
        $ctx = $this->makeEvent();
        PrizeMember::create([
            'member_id' => $this->member->id,
            'content_id' => $ctx['content']->id,
            'event_detail_id' => $ctx['detail']->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 1,
            'admin' => 0,
        ]);

        $data = $this->service->getEvent($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(2, $data->prize_contents[0]->show_exchange);
        $this->assertSame(__('param.prize_button.exchanged'), $data->prize_contents[0]->text);
    }

    public function test_duplicate_ip_invitees_are_excluded(): void
    {
        $ctx = $this->makeEvent(['period' => 1], ['achieve_people' => 1], ['ip_block' => 1]);
        $this->makeInvitee($ctx['event'], ['ip_duplicate' => 1]);

        $data = $this->service->getEvent($this->member, $this->agent, $ctx['event']->id, 1);

        $this->assertSame(0, $data->prize_contents[0]->achieve_number);
    }

    public function test_receive_prize_credits_platform_balance(): void
    {
        $ctx = $this->makeEvent(['period' => 1], ['achieve_people' => 1, 'point' => 50, 'point_type' => 1]);
        $this->makeInvitee($ctx['event']);
        $before = $this->member->balance;

        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);

        $this->assertSame(
            (float) $before + 50,
            (float) Member::find($this->member->id)->balance,
        );
        $this->assertDatabaseHas('prize_members', [
            'member_id' => $this->member->id,
            'content_id' => $ctx['content']->id,
            'status' => 1,
        ]);
        $this->assertDatabaseHas('transaction_logs', [
            'member_id' => $this->member->id,
            'remark' => 'New Invite Reward',
        ]);
    }

    public function test_receive_prize_throws_when_not_reached(): void
    {
        $ctx = $this->makeEvent(['period' => 1], ['achieve_people' => 2]);
        $this->makeInvitee($ctx['event']);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1936'));
        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);
    }

    public function test_receive_prize_is_not_repeatable_in_same_period(): void
    {
        $ctx = $this->makeEvent(['period' => 1], ['achieve_people' => 1]);
        $this->makeInvitee($ctx['event']);

        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1947'));
        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);
    }

    public function test_receive_prize_writes_flow_limit_when_multiple_set(): void
    {
        $ctx = $this->makeEvent(['period' => 1], [
            'achieve_people' => 1,
            'point' => 50,
            'flow_limit_multiple' => 3,
        ]);
        $this->makeInvitee($ctx['event']);

        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);

        $this->assertDatabaseHas('flow_limits', [
            'member_id' => $this->member->id,
            'event_id' => $ctx['event']->id,
            'flow_limit' => '150.0000',
        ]);
    }

    public function test_receive_prize_credits_game_wallet_when_multi_wallet_enabled(): void
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

        $ctx = $this->makeEvent(
            ['period' => 1],
            ['achieve_people' => 1, 'point' => 50, 'point_type' => 1, 'flow_limit_multiple' => 2],
            [],
            ['flow_limit_multiple' => 5],
        );
        $this->makeInvitee($ctx['event']);

        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);

        // 舊版於錢包不存在時一律建立禮碼錢包，本次改為建立對應類型
        $wallet = MemberWallet::where('member_id', $this->member->id)
            ->where('wallet_type_id', WalletEnum::CODE_GAME->value)
            ->first();

        $this->assertNotNull($wallet);
        $this->assertSame(50.0, (float) $wallet->balance);
        $this->assertDatabaseHas('wallet_transaction_logs', [
            'member_id' => $this->member->id,
            'wallet_type_id' => WalletEnum::CODE_GAME->value,
        ]);
    }

    public function test_receive_prize_blocks_duplicate_mobile_and_records_cancel(): void
    {
        $ctx = $this->makeEvent(['period' => 1], ['achieve_people' => 1], ['phone_block' => 1]);
        $this->makeInvitee($ctx['event']);

        // 另一位相同手機號的會員已領過同一活動明細
        $other = $this->createMember($this->agent, [
            'username' => 'other_' . Str::random(10),
            'mobile' => $this->member->mobile,
        ]);
        PrizeMember::create([
            'member_id' => $other->id,
            'content_id' => $ctx['content']->id,
            'event_detail_id' => $ctx['detail']->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 1,
            'admin' => 0,
        ]);

        try {
            $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);
            $this->fail('should be blocked');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.1951'), $e->getMessage());
        }

        $this->assertDatabaseHas('event_payout_cancels', [
            'event_id' => $ctx['event']->id,
            'member_id' => $this->member->id,
            'type' => 3,
            'block_member_id' => $other->id,
        ]);
        // 被擋下時不得派彩
        $this->assertDatabaseMissing('prize_members', [
            'member_id' => $this->member->id,
            'content_id' => $ctx['content']->id,
            'status' => 1,
        ]);
    }

    public function test_existing_payout_cancel_blocks_immediately(): void
    {
        $ctx = $this->makeEvent(['period' => 1], ['achieve_people' => 1]);
        $this->makeInvitee($ctx['event']);

        $prizeMember = PrizeMember::create([
            'member_id' => $this->member->id,
            'content_id' => $ctx['content']->id,
            'event_detail_id' => $ctx['detail']->id,
            'prize_id' => $ctx['prize']->id,
            'status' => 0,
            'admin' => 0,
        ]);
        EventPayoutCancel::create([
            'event_id' => $ctx['event']->id,
            'prize_member_id' => $prizeMember->id,
            'from_member_id' => $this->member->id,
            'member_id' => $this->member->id,
            'type' => 1,
            'block_member_id' => 0,
        ]);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage(__('error_message.1952'));
        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);
    }

    /**
     * 舊版領獎端誤用 prize_contents.type，已統一為 level：
     * level=1（首儲）但 type=0 時，領獎應與顯示一致地要求首儲達標
     */
    public function test_receive_prize_uses_level_not_type(): void
    {
        $ctx = $this->makeEvent(['period' => 1], [
            'level' => 1,
            'type' => 0,
            'achieve_people' => 1,
        ], ['point' => 100]);
        $invitee = $this->makeInvitee($ctx['event']);

        // 僅有邀請、無合格首儲 → 依 level 判定應被拒（若誤用 type=0 則會放行）
        try {
            $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);
            $this->fail('should not be claimable');
        } catch (ApiException $e) {
            $this->assertSame(__('error_message.1936'), $e->getMessage());
        }

        $this->makeQualifiedOrder($invitee, 200);

        $this->service->receivePrize($this->member, $this->agent, $ctx['content']->id);

        $this->assertDatabaseHas('prize_members', [
            'member_id' => $this->member->id,
            'content_id' => $ctx['content']->id,
            'status' => 1,
        ]);
    }

    public function test_first_deposit_level_counts_only_qualified_orders(): void
    {
        // level 1：需被邀請人本期首筆成功訂單達 event_details.point
        $ctx = $this->makeEvent(['period' => 1], ['level' => 1, 'achieve_people' => 1], ['point' => 100]);
        $invitee = $this->makeInvitee($ctx['event']);

        $data = $this->service->getEvent($this->member, $this->agent, $ctx['event']->id, 1);
        $this->assertSame(0, $data->prize_contents[0]->achieve_number);

        $this->makeQualifiedOrder($invitee, 200);

        $data = $this->service->getEvent($this->member, $this->agent, $ctx['event']->id, 1);
        $this->assertSame(1, $data->prize_contents[0]->achieve_number);
        $this->assertSame(1, $data->prize_contents[0]->show_exchange);
    }
}
